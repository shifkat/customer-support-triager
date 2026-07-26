<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\ToolBox;
use App\Services\Triage\ToolLoop;
use App\Services\Triage\TriageClient;

/**
 * Assignment step 5 -- parallel tool use.
 *
 * Drops to a hand-written loop so three things are visible that the SDK tool
 * runner hides: how many tool_use blocks arrived in one assistant turn, how
 * long the batch took executed both ways, and the shape of the message that
 * carries the results back.
 */
final class Step05Command extends TriageCommand
{
    protected $signature = 'triage:step5 {--ticket=p1-billing.md}';

    protected $description = 'Step 5: parallel tool execution and single-message result batching';

    public function __construct(
        TriageClient $triage,
        private readonly ToolBox $tools,
        private readonly ToolLoop $loop,
    ) {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 5', 'Parallel tool use');

            $this->section('Why these two tools can run in parallel');
            $this->line('  <fg=gray>list_recent_orders accepts an email directly, so it does not need</>');
            $this->line('  <fg=gray>lookup_customer to resolve a customer_id first. With no data</>');
            $this->line('  <fg=gray>dependency between them, the model is free to request both in a</>');
            $this->line('  <fg=gray>single assistant turn — and their descriptions say so explicitly.</>');
            $this->kv('simulated tool latency', ToolBox::SIMULATED_LATENCY_MS.' ms each');

            $ticket = (string) file_get_contents(storage_path('app/fixtures/tickets/'.$this->option('ticket')));

            $messages = [[
                'role' => 'user',
                'content' => "Triage this ticket. Look up the customer account AND their recent orders ".
                    "before you decide anything — you can request both at once.\n\n<ticket>\n".
                    trim($ticket)."\n</ticket>",
            ]];

            $batchNumber = 0;

            $observer = function (string $event, mixed $payload) use (&$batchNumber): void {
                if ('tools' === $event) {
                    $batchNumber++;
                    $count = count($payload);
                    $this->newLine();
                    $this->line("  <fg=yellow>── batch {$batchNumber}: {$count} tool_use block(s) in ONE assistant turn ──</>");
                    foreach ($payload as $call) {
                        $this->line('  <fg=magenta>[tool_use]</> '.$call['name'].'  '.json_encode($call['input'], JSON_UNESCAPED_SLASHES));
                    }
                }

                if ('batch' === $event) {
                    $this->newLine();
                    $this->kv('driver', (string) $payload['driver']);
                    $this->kv('sequential', number_format($payload['sequential_ms'], 0).' ms');
                    $this->kv('parallel', number_format($payload['parallel_ms'], 0).' ms');

                    $speedup = $payload['speedup'];
                    if ($speedup >= 1.05) {
                        $this->ok(sprintf('parallel was %.2fx faster (saved %s ms)', $speedup, number_format($payload['sequential_ms'] - $payload['parallel_ms'], 0)));
                    } elseif (count($payload['calls']) < 2) {
                        $this->warn2('only one tool in this batch — nothing to parallelise');
                    } else {
                        $this->warn2(sprintf(
                            'parallel was %.2fx — process-driver startup (~600ms) outweighed the saving here',
                            $speedup
                        ));
                    }
                }
            };

            $this->section('Running the loop');

            $outcome = $this->loop->run(
                messages: $messages,
                tools: $this->tools->definitions(),
                tier: 'investigate',
                observer: $observer,
            );

            $this->section('How the results were returned');

            // The assignment's real correctness requirement: one user message
            // carrying every tool_result. Split across messages it still runs,
            // but the model learns to stop batching on later turns.
            $resultMessages = array_values(array_filter(
                $outcome['messages'],
                static fn (array $m): bool => 'user' === $m['role']
                    && is_array($m['content'])
                    && isset($m['content'][0]['type'])
                    && 'tool_result' === $m['content'][0]['type'],
            ));

            foreach ($resultMessages as $i => $message) {
                $blocks = $message['content'];
                $this->kv('user message #'.($i + 1), count($blocks).' tool_result block(s)');
                foreach ($blocks as $block) {
                    $preview = mb_strimwidth((string) $block['content'], 0, 88, '…');
                    $flag = ($block['is_error'] ?? false) ? ' <fg=red>[is_error]</>' : '';
                    $this->line('      <fg=gray>'.$block['tool_use_id'].'</>'.$flag.'  '.$preview);
                }
            }

            $multiBlockBatches = array_filter($resultMessages, static fn (array $m): bool => count($m['content']) > 1);

            $this->newLine();
            if ($multiBlockBatches !== []) {
                $this->ok('Parallel results were batched into a single user message — correct.');
            } else {
                $this->warn2('No multi-result batch this run; the model called tools one at a time.');
            }

            $this->section('Final answer');
            foreach ($outcome['final']->content as $block) {
                if ('text' === $block->type && '' !== trim($block->text)) {
                    $this->line('  '.wordwrap(trim($block->text), 96, "\n  "));
                }
            }

            $this->section('Diagnostics');
            $this->kv('assistant turns', (string) $outcome['turns']);
            $this->kv('tool batches', (string) count($outcome['batches']));
            $this->reportUsage($outcome['final']->model, $outcome['final']->usage);
        });
    }
}
