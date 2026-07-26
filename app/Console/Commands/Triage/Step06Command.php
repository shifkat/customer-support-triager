<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\Classifier;
use App\Services\Triage\TriageClient;

/**
 * Assignment step 6 -- streaming.
 *
 * The interesting claim is not that text appears progressively; it is that
 * the streamed response still parses. So this classifies the same ticket
 * twice -- once streamed, once not -- and diffs the two TriageResults field
 * by field. Streaming changed the delivery, not the result.
 */
final class Step06Command extends TriageCommand
{
    protected $signature = 'triage:step6 {--ticket=p2-integration.md} {--events : print the raw stream event log}';

    protected $description = 'Step 6: streaming, and proof the streamed output still parses';

    public function __construct(TriageClient $triage, private readonly Classifier $classifier)
    {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 6', 'Streaming for real-time UX');

            $ticket = (string) file_get_contents(storage_path('app/fixtures/tickets/'.$this->option('ticket')));

            $this->section('Streaming the classification');
            $this->line('  <fg=gray>Structured output streams like any other completion — the JSON is</>');
            $this->line('  <fg=gray>generated token by token. Each delta is echoed below as it lands,</>');
            $this->line('  <fg=gray>while MessageAccumulator rebuilds the full message in parallel.</>');
            $this->newLine();

            $eventCounts = [];
            $firstDeltaAt = null;
            $started = microtime(true);
            $charCount = 0;

            $this->output->write('  <fg=green>');

            $streamed = $this->classifier->classifyStreaming(
                ticket: $ticket,
                onDelta: function (string $text) use (&$firstDeltaAt, $started, &$charCount): void {
                    $firstDeltaAt ??= (microtime(true) - $started) * 1000;
                    $charCount += strlen($text);
                    $this->output->write($text);
                },
                onEvent: function (string $type) use (&$eventCounts): void {
                    $eventCounts[$type] = ($eventCounts[$type] ?? 0) + 1;
                },
            );

            $this->output->write('</>');
            $this->newLine(2);

            $this->section('Stream statistics');
            $this->kv('time to first token', number_format((float) $firstDeltaAt, 0).' ms');
            $this->kv('total stream time', number_format($streamed->elapsedMs, 0).' ms');
            $this->kv('characters streamed', (string) $charCount);

            if ($this->option('events')) {
                $this->section('Event log');
                foreach ($eventCounts as $type => $count) {
                    $this->kv($type, (string) $count);
                }
            }

            $this->section('Parsed from the accumulated message');
            $s = $streamed->result;
            $this->kv('urgency', $s->urgency);
            $this->kv('topic', $s->topic);
            $this->kv('plan_tier', $s->plan_tier);
            $this->kv('confidence', number_format($s->confidence, 2));
            $this->kv('summary', $s->summary);

            $this->section('Same ticket, non-streamed, for comparison');
            $plain = $this->classifier->classify($ticket);
            $p = $plain->result;

            $fields = ['urgency', 'topic', 'plan_tier', 'sentiment', 'needs_human'];
            $rows = [];
            $allMatch = true;

            foreach ($fields as $field) {
                $a = var_export($s->{$field}, true);
                $b = var_export($p->{$field}, true);
                $match = $a === $b;
                $allMatch = $allMatch && $match;
                $rows[] = [$field, trim($a, "'"), trim($b, "'"), $match ? '✔' : '✘'];
            }

            $this->table(['field', 'streamed', 'non-streamed', 'same'], $rows);

            $this->newLine();
            if ($allMatch) {
                $this->ok('Streamed and non-streamed classifications agree — the stream parsed correctly.');
            } else {
                $this->warn2('Fields differ. That is ordinary model variance on a borderline ticket, not a parsing failure — both sides parsed into a valid TriageResult.');
            }

            $this->section('Why this matters for the agent watching');
            $this->kv('non-streamed', 'nothing on screen for '.number_format($plain->elapsedMs, 0).' ms');
            $this->kv('streamed', 'first token at '.number_format((float) $firstDeltaAt, 0).' ms');
        });
    }
}
