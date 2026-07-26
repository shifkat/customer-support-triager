<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

/**
 * Assignment step 1 -- prove API access works.
 *
 * One Messages API call, then report what came back: the model that actually
 * served it, the stop reason, and the token usage. Those three fields are the
 * foundation every later step reads.
 */
final class Step01Command extends TriageCommand
{
    protected $signature = 'triage:step1';

    protected $description = 'Step 1: verify Claude API access with a single Messages call';

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 1', 'API access');

            $model = $this->triage->model('classify');
            $this->kv('configured tier', 'classify');
            $this->kv('model', $model);
            $this->kv('key source', 'config(triage.api_key) <- ANTHROPIC_API_KEY');

            $this->section('Request');
            $prompt = 'Reply with exactly one sentence confirming you are reachable, '
                .'then state which Claude model you are.';
            $this->line("  <fg=gray>{$prompt}</>");

            $started = microtime(true);

            $message = $this->triage->client()->messages->create(
                maxTokens: 200,
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $model,
            );

            $elapsed = (microtime(true) - $started) * 1000;

            $this->section('Response');
            foreach ($message->content as $block) {
                if ('text' === $block->type) {
                    $this->line('  '.$block->text);
                }
            }

            $this->section('Diagnostics');
            $this->kv('served by', $message->model);
            $this->kv('stop reason', (string) $message->stopReason);
            $this->kv('latency', number_format($elapsed, 0).' ms');
            $this->reportUsage($message->model, $message->usage);

            $this->newLine();
            $this->ok('API access confirmed.');
        });
    }
}
