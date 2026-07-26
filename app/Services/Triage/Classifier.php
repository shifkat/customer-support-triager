<?php

declare(strict_types=1);

namespace App\Services\Triage;

use Anthropic\Lib\Streaming\MessageAccumulator;
use App\Services\Triage\Data\ClassificationOutcome;
use App\Services\Triage\Data\TriageResult;

/**
 * Turns raw ticket text into a TriageResult (assignment steps 2, 3, 6, 7).
 *
 * Runs on the cheap tier. The whole point of tiering is that this executes on
 * every inbound ticket while the expensive model only wakes up on escalation,
 * so this class is where prompt size and model choice matter most.
 */
final class Classifier
{
    public function __construct(private readonly TriageClient $triage) {}

    /**
     * Classify a ticket.
     *
     * @param  bool  $cache  Put a cache breakpoint on the system prompt. The taxonomy is
     *                       identical on every call, so caching it turns a per-ticket cost
     *                       into a one-off. Step 7 runs this both ways and reports the delta.
     */
    public function classify(string $ticket, bool $cache = true): ClassificationOutcome
    {
        $model = $this->triage->model('classify');
        $started = microtime(true);

        $message = $this->triage->client()->messages->create(
            maxTokens: 1024,
            messages: [['role' => 'user', 'content' => $this->userTurn($ticket)]],
            model: $model,
            system: $this->systemBlocks($cache),
            outputConfig: ['format' => TriageResult::class],
        );

        $parsed = $message->parsedOutput();

        if (! $parsed instanceof TriageResult) {
            throw new \RuntimeException(
                'Classifier did not return a TriageResult. stop_reason='.($message->stopReason ?? 'null')
            );
        }

        return new ClassificationOutcome(
            result: $parsed,
            model: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
            elapsedMs: (microtime(true) - $started) * 1000,
            stopReason: (string) ($message->stopReason ?? ''),
        );
    }

    /**
     * Classify while streaming (assignment step 6).
     *
     * Every event is echoed to $onDelta as it arrives *and* fed to the
     * accumulator. The accumulated message is then parsed by exactly the same
     * code path as the non-streamed call above -- that round trip is the
     * proof the assignment asks for: streaming changed the delivery, not the
     * result.
     *
     * @param  callable(string): void  $onDelta  Receives each text fragment as it arrives.
     * @param  callable(string): void|null  $onEvent  Receives each raw event type, for the demo's event log.
     */
    public function classifyStreaming(string $ticket, callable $onDelta, ?callable $onEvent = null): ClassificationOutcome
    {
        $model = $this->triage->model('classify');
        $started = microtime(true);

        $stream = $this->triage->client()->messages->createStream(
            maxTokens: 1024,
            messages: [['role' => 'user', 'content' => $this->userTurn($ticket)]],
            model: $model,
            system: $this->systemBlocks(true),
            outputConfig: ['format' => TriageResult::class],
        );

        $accumulator = MessageAccumulator::forMessages();

        foreach ($stream as $event) {
            $accumulator->accumulate($event);

            if (null !== $onEvent) {
                $onEvent((string) ($event->type ?? 'unknown'));
            }

            // Structured output still streams as text deltas -- the JSON is
            // generated token by token like any other completion.
            if ('content_block_delta' === ($event->type ?? null)) {
                $text = $event->delta->text ?? null;
                if (is_string($text) && '' !== $text) {
                    $onDelta($text);
                }
            }
        }

        $message = $accumulator->message();
        $parsed = $message->parsedOutput();

        if (! $parsed instanceof TriageResult) {
            throw new \RuntimeException('Streamed classification did not parse into a TriageResult.');
        }

        return new ClassificationOutcome(
            result: $parsed,
            model: $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
            elapsedMs: (microtime(true) - $started) * 1000,
            stopReason: (string) ($message->stopReason ?? ''),
        );
    }

    /**
     * The system prompt, optionally with a cache breakpoint.
     *
     * The taxonomy is byte-identical on every call, which is exactly what
     * prompt caching needs -- the ticket text, which changes every time, is
     * deliberately kept in the user turn *after* the breakpoint. Putting the
     * ticket in the system prompt would invalidate the cache on every ticket
     * and silently make the breakpoint useless.
     *
     * @return list<array<string, mixed>>
     */
    public function systemBlocks(bool $cache): array
    {
        $block = [
            'type' => 'text',
            'text' => $this->systemPrompt(),
        ];

        if ($cache) {
            $block['cache_control'] = ['type' => 'ephemeral'];
        }

        return [$block];
    }

    /**
     * Build the classifier prompt from config.
     *
     * Generated rather than hand-written so the documented policy in
     * docs/TRIAGE_POLICY.md, the routing engine and the prompt cannot drift
     * apart -- tightening a trigger in config/triage.php updates all three.
     */
    public function systemPrompt(): string
    {
        $lines = [
            'You are the triage stage of a customer support pipeline. Classify one inbound ticket.',
            '',
            'URGENCY BANDS — assign the highest band whose triggers are met:',
        ];

        foreach (config('triage.urgency') as $code => $band) {
            $lines[] = sprintf('%s (%s, respond within %d min):', $code, $band['label'], $band['sla_first_response_mins']);
            foreach ($band['triggers'] as $trigger) {
                $lines[] = '  - '.$trigger;
            }
        }

        $lines[] = '';
        $lines[] = 'TOPICS — pick the single best fit:';
        foreach (config('triage.topics') as $topic => $meta) {
            $lines[] = sprintf('  %-16s %s', $topic, $meta['description']);
        }

        $lines[] = '';
        $lines[] = 'RULES:';
        $lines[] = '- Judge urgency by impact described in the ticket, not by how upset the customer sounds.';
        $lines[] = '- An angry tone alone does not raise the band; a blocked workflow does.';
        $lines[] = '- Set needs_human when the ticket alleges data loss, a security problem, or legal action.';
        $lines[] = '- Set confidence below 0.75 when the ticket is too vague to place with certainty.';
        $lines[] = '- Extract customer_email and order_id only if they literally appear in the text. Never invent them.';
        $lines[] = '- plan_tier is what the ticket states or clearly implies; default to free when it is silent.';

        return implode("\n", $lines);
    }

    /** The volatile half of the prompt: everything after the cache breakpoint. */
    private function userTurn(string $ticket): string
    {
        return "<ticket>\n".trim($ticket)."\n</ticket>";
    }
}
