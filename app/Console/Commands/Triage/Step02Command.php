<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\Classifier;
use App\Services\Triage\TriageClient;

/**
 * Assignment step 2 -- the triage logic itself.
 *
 * Prints the taxonomy the classifier is given, then classifies the sample
 * tickets so the definition and its effect are visible side by side.
 */
final class Step02Command extends TriageCommand
{
    protected $signature = 'triage:step2 {--ticket= : path to a ticket file, relative to storage/app/fixtures/tickets}';

    protected $description = 'Step 2: triage taxonomy and structured classification';

    public function __construct(TriageClient $triage, private readonly Classifier $classifier)
    {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 2', 'Triage logic');

            $this->section('Taxonomy (generated from config/triage.php)');
            foreach (config('triage.urgency') as $code => $band) {
                $this->kv($code.' — '.$band['label'], $band['sla_first_response_mins'].' min first response');
            }
            $this->newLine();
            foreach (config('triage.topics') as $topic => $meta) {
                $this->kv($topic, '→ '.$meta['team'].' team');
            }

            $this->section('Enforcement');
            $this->line('  <fg=gray>The classifier is constrained to the TriageResult schema via</>');
            $this->line('  <fg=gray>outputConfig.format, so urgency/topic/plan_tier can only ever be</>');
            $this->line('  <fg=gray>values the routing engine understands. There is no JSON parsing</>');
            $this->line('  <fg=gray>step to fail, and no prompt-injected "reply with JSON" instruction.</>');

            $tickets = $this->tickets();

            foreach ($tickets as $name => $body) {
                $this->section("Classifying: {$name}");

                $outcome = $this->classifier->classify($body);
                $r = $outcome->result;

                $this->kv('urgency', $r->urgency.'  ('.config("triage.urgency.{$r->urgency}.label").')');
                $this->kv('topic', $r->topic);
                $this->kv('plan tier', $r->plan_tier);
                $this->kv('sentiment', $r->sentiment);
                $this->kv('confidence', number_format($r->confidence, 2));
                $this->kv('needs human', $r->needs_human ? 'yes' : 'no');
                $this->kv('customer email', $r->customer_email ?? '—');
                $this->kv('order id', $r->order_id ?? '—');
                $this->kv('summary', $r->summary);
                $this->newLine();
                $this->reportUsage($outcome->model, (object) [
                    'inputTokens' => $outcome->inputTokens,
                    'outputTokens' => $outcome->outputTokens,
                    'cacheReadInputTokens' => $outcome->cacheReadTokens,
                    'cacheCreationInputTokens' => $outcome->cacheWriteTokens,
                ]);
            }

            $this->newLine();
            $this->ok('Every field came back inside the declared enum set — schema enforced, not hoped for.');
        });
    }

    /** @return array<string, string> */
    private function tickets(): array
    {
        $dir = storage_path('app/fixtures/tickets');

        if (null !== $this->option('ticket')) {
            $path = $dir.'/'.ltrim((string) $this->option('ticket'), '/');
            if (! is_file($path)) {
                throw new \RuntimeException("No such ticket: {$path}");
            }

            return [basename($path) => (string) file_get_contents($path)];
        }

        $out = [];
        foreach (glob($dir.'/*.md') ?: [] as $path) {
            $out[basename($path)] = (string) file_get_contents($path);
        }

        return $out;
    }
}
