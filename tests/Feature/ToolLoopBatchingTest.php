<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Triage\ParallelToolExecutor;
use App\Services\Triage\ToolBox;
use App\Services\Triage\ToolLoop;
use App\Services\Triage\TriageClient;
use Tests\TestCase;

/**
 * Guards the correctness half of assignment step 5.
 *
 * Parallel tool results must go back in ONE user message. Splitting them
 * across several still "works" -- the API accepts it and the run completes --
 * which is exactly why it needs a test: the failure is silent, and shows up
 * later as the model quietly no longer batching its tool calls.
 */
final class ToolLoopBatchingTest extends TestCase
{
    private function loop(): ToolLoop
    {
        // No API key needed: resultsMessage() and toolCalls() never touch the
        // network. That is the point of keeping them off the request path.
        return new ToolLoop(
            $this->app->make(TriageClient::class),
            $this->app->make(ParallelToolExecutor::class),
        );
    }

    /** @return list<array{id: string, name: string, input: array<string, mixed>}> */
    private function twoCalls(): array
    {
        return [
            ['id' => 'toolu_01', 'name' => 'lookup_customer', 'input' => ['email' => 'dana@northwind.example']],
            ['id' => 'toolu_02', 'name' => 'list_recent_orders', 'input' => ['email' => 'dana@northwind.example']],
        ];
    }

    public function test_all_results_go_into_a_single_user_message(): void
    {
        $message = $this->loop()->resultsMessage($this->twoCalls(), [
            'toolu_01' => '{"found":true}',
            'toolu_02' => '{"found":true,"orders":[]}',
        ]);

        $this->assertSame('user', $message['role']);
        $this->assertCount(2, $message['content'], 'both tool results must ride in one message');

        foreach ($message['content'] as $block) {
            $this->assertSame('tool_result', $block['type']);
        }
    }

    public function test_each_result_is_tagged_with_the_tool_use_id_it_answers(): void
    {
        $message = $this->loop()->resultsMessage($this->twoCalls(), [
            'toolu_01' => '{"a":1}',
            'toolu_02' => '{"b":2}',
        ]);

        $ids = array_column($message['content'], 'tool_use_id');

        $this->assertSame(['toolu_01', 'toolu_02'], $ids);
    }

    public function test_a_missing_result_still_produces_an_error_block(): void
    {
        // Dropping it would leave a dangling tool_use and 400 the next request.
        $message = $this->loop()->resultsMessage($this->twoCalls(), ['toolu_01' => '{"a":1}']);

        $this->assertCount(2, $message['content']);
        $this->assertTrue($message['content'][1]['is_error']);
        $this->assertStringContainsString('no result', $message['content'][1]['content']);
    }

    public function test_a_tool_that_returned_an_error_is_flagged(): void
    {
        $message = $this->loop()->resultsMessage(
            [['id' => 'toolu_09', 'name' => 'unknown_tool', 'input' => []]],
            ['toolu_09' => '{"error":"Unknown tool \'unknown_tool\'."}'],
        );

        $this->assertTrue($message['content'][0]['is_error']);
    }

    public function test_successful_results_carry_no_is_error_key(): void
    {
        $message = $this->loop()->resultsMessage(
            [['id' => 'toolu_10', 'name' => 'lookup_customer', 'input' => []]],
            ['toolu_10' => '{"found":true}'],
        );

        $this->assertArrayNotHasKey('is_error', $message['content'][0]);
    }

    public function test_parallel_and_sequential_produce_identical_results(): void
    {
        // Speed is a bonus; agreement is the requirement. If the parallel path
        // ever returned different data the whole optimisation is worthless.
        $executor = $this->app->make(ParallelToolExecutor::class);
        $calls = $this->twoCalls();

        $sequential = $executor->sequential($calls);
        $parallel = $executor->parallel($calls);

        $decode = static fn (array $results): array => array_map(
            static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $results,
        );

        $this->assertSame(
            $decode($sequential['results']),
            $decode($parallel['results']),
            'parallel execution must not change the data'
        );
    }

    public function test_tool_results_are_projected_not_dumped(): void
    {
        // Tool results are re-sent on every later turn, so a fat payload is
        // paid for repeatedly. Assert the projection actually drops fields.
        $box = (new ToolBox)->withoutLatency();
        $result = json_decode($box->execute('lookup_customer', ['email' => 'dana@northwind.example']), true);

        $this->assertArrayHasKey('plan_tier', $result);
        $this->assertArrayHasKey('health_score', $result);
        $this->assertArrayNotHasKey('email', $result, 'the caller already knows the email it asked about');
        $this->assertArrayNotHasKey('company', $result, 'company never affects routing');
        $this->assertArrayNotHasKey('open_tickets', $result);
    }

    public function test_unknown_customer_is_handled_without_throwing(): void
    {
        $box = (new ToolBox)->withoutLatency();
        $result = json_decode($box->execute('lookup_customer', ['email' => 'nobody@example.com']), true);

        $this->assertFalse($result['found']);
        $this->assertStringContainsString('free-tier', $result['note']);
    }
}
