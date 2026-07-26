<?php

declare(strict_types=1);

namespace App\Services\Triage;

use Illuminate\Support\Facades\Concurrency;

/**
 * Executes a batch of independent tool calls (assignment step 5).
 *
 * Two things matter here and they are easy to conflate:
 *
 *   Correctness -- every tool_result must go back in a SINGLE user message.
 *   Splitting them across messages still "works", but it quietly teaches the
 *   model to stop batching, and the parallelism disappears on later turns.
 *   That requirement is transport-independent, and it lives in
 *   ToolLoop::resultsMessage().
 *
 *   Speed -- PHP has no threads, so real concurrency means separate
 *   processes. Laravel's process driver boots a full application per child,
 *   which costs roughly half a second before any work happens. That is worth
 *   paying for two ~900ms API round trips and emphatically not worth it for
 *   two filesystem reads. Rather than assert a speed-up, runBoth() measures
 *   both paths on every run and reports what actually happened.
 */
final class ParallelToolExecutor
{
    /**
     * Run a batch serially.
     *
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $calls
     * @return array{results: array<string, string>, ms: float}
     */
    public function sequential(array $calls): array
    {
        $started = microtime(true);
        $results = [];

        $box = new ToolBox;
        foreach ($calls as $call) {
            $results[$call['id']] = $box->execute($call['name'], $call['input']);
        }

        return ['results' => $results, 'ms' => (microtime(true) - $started) * 1000];
    }

    /**
     * Run a batch concurrently, one child process per call.
     *
     * The closures capture only scalars and arrays -- never $this -- so they
     * serialize cleanly for the process driver. Capturing a service instance
     * is the usual reason this silently falls back or blows up.
     *
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $calls
     * @return array{results: array<string, string>, ms: float, driver: string}
     */
    public function parallel(array $calls): array
    {
        $started = microtime(true);

        $tasks = [];
        foreach ($calls as $call) {
            $name = $call['name'];
            $input = $call['input'];
            $tasks[$call['id']] = static fn (): string => (new ToolBox)->execute($name, $input);
        }

        try {
            /** @var array<string, string> $results */
            $results = Concurrency::run($tasks);
            $driver = (string) config('concurrency.default', 'process');
        } catch (\Throwable $e) {
            // Falling back keeps the pipeline running; the caller reports it
            // rather than pretending the batch ran in parallel.
            $sequential = $this->sequential($calls);

            return [
                'results' => $sequential['results'],
                'ms' => (microtime(true) - $started) * 1000,
                'driver' => 'sequential-fallback ('.$e->getMessage().')',
            ];
        }

        return [
            'results' => $results,
            'ms' => (microtime(true) - $started) * 1000,
            'driver' => $driver,
        ];
    }

    /**
     * Run the batch both ways for the step 5 demo.
     *
     * The parallel results are the ones handed back to the model; the
     * sequential pass exists only to produce an honest baseline on the
     * machine the demo is running on.
     *
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $calls
     * @return array{results: array<string, string>, sequential_ms: float, parallel_ms: float, driver: string, speedup: float}
     */
    public function runBoth(array $calls): array
    {
        $sequential = $this->sequential($calls);
        $parallel = $this->parallel($calls);

        return [
            'results' => $parallel['results'],
            'sequential_ms' => $sequential['ms'],
            'parallel_ms' => $parallel['ms'],
            'driver' => $parallel['driver'],
            'speedup' => $parallel['ms'] > 0 ? $sequential['ms'] / $parallel['ms'] : 1.0,
        ];
    }
}
