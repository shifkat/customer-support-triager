<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\ToolBox;
use App\Services\Triage\TriageClient;

/**
 * Assignment step 4 -- core API plus tool use.
 *
 * Uses the SDK's tool runner, which drives the request -> execute -> feed-back
 * loop for us. Step 5 drops to a hand-written loop to expose the mechanics
 * this one deliberately hides.
 */
final class Step04Command extends TriageCommand
{
    protected $signature = 'triage:step4 {--ticket=p1-billing.md}';

    protected $description = 'Step 4: tool definitions and the SDK tool runner';

    public function __construct(TriageClient $triage, private readonly ToolBox $tools)
    {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 4', 'Core API + tool use');

            $this->section('Tools exposed to the model');
            foreach ($this->tools->definitions() as $definition) {
                $this->kv($definition['name'], implode(', ', array_keys($definition['input_schema']['properties'])));
                $this->line('    <fg=gray>'.wordwrap($definition['description'], 96, "\n    ").'</>');
            }

            $ticket = (string) file_get_contents(storage_path('app/fixtures/tickets/'.$this->option('ticket')));

            $this->section('Running the tool runner');
            $this->kv('model', $this->triage->model('investigate'));
            $this->kv('loop', 'client.beta.messages.toolRunner()');

            $this->tools->resetCalls();
            $started = microtime(true);

            $runner = $this->triage->client()->beta->messages->toolRunner(
                maxTokens: 4096,
                messages: [[
                    'role' => 'user',
                    'content' => "Triage this support ticket. Gather whatever account and billing ".
                        "context you need using the tools, then state the urgency, the owning team, ".
                        "and the single most important fact you discovered.\n\n<ticket>\n".
                        trim($ticket)."\n</ticket>",
                ]],
                model: $this->triage->model('investigate'),
                tools: $this->tools->runnable(),
                maxIterations: 6,
            );

            $turn = 0;
            $lastMessage = null;

            foreach ($runner as $message) {
                $turn++;
                $lastMessage = $message;
                $this->newLine();
                $this->line("  <fg=yellow>── assistant turn {$turn} ──</>");

                foreach ($message->content as $block) {
                    if ('tool_use' === $block->type) {
                        $this->line('  <fg=magenta>[tool_use]</> '.$block->name.'  '.json_encode($block->input, JSON_UNESCAPED_SLASHES));
                    } elseif ('text' === $block->type && '' !== trim($block->text)) {
                        $this->line('  '.trim($block->text));
                    }
                }
            }

            $elapsed = (microtime(true) - $started) * 1000;

            $this->section('Tools actually executed');
            foreach ($this->tools->calls() as $i => $call) {
                $this->kv(($i + 1).'. '.$call['name'], number_format($call['ms'], 0).' ms  '.json_encode($call['input'], JSON_UNESCAPED_SLASHES));
            }

            $this->section('Diagnostics');
            $this->kv('assistant turns', (string) $turn);
            $this->kv('tool executions', (string) count($this->tools->calls()));
            $this->kv('wall clock', number_format($elapsed, 0).' ms');
            if (null !== $lastMessage) {
                $this->kv('final stop reason', (string) $lastMessage->stopReason);
                $this->reportUsage($lastMessage->model, $lastMessage->usage);
            }

            $this->newLine();
            $this->ok('Claude selected and called the tools correctly.');
        });
    }
}
