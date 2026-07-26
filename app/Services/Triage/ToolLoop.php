<?php

declare(strict_types=1);

namespace App\Services\Triage;

/**
 * A hand-written tool-use loop (assignment steps 4 and 5).
 *
 * The SDK's tool runner is the right default and is what steps 9-12 use. This
 * loop exists because step 5 has to *show* the mechanics the runner hides:
 * how many tool_use blocks arrived in one assistant turn, that they were
 * executed together, and that every result went back in one user message.
 */
final class ToolLoop
{
    public function __construct(
        private readonly TriageClient $triage,
        private readonly ParallelToolExecutor $executor,
    ) {}

    /**
     * Extract the tool_use blocks from an assistant message.
     *
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    public function toolCalls(object $message): array
    {
        $calls = [];

        foreach ($message->content as $block) {
            if ('tool_use' === $block->type) {
                $calls[] = [
                    'id' => (string) $block->id,
                    'name' => (string) $block->name,
                    'input' => (array) $block->input,
                ];
            }
        }

        return $calls;
    }

    /**
     * Build the single user message carrying every tool result.
     *
     * This is the load-bearing part of step 5. One message, one block per
     * call, each tagged with the tool_use_id it answers. A failed tool still
     * gets a block, flagged is_error -- dropping it would leave a dangling
     * tool_use and the next request would 400.
     *
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $calls
     * @param  array<string, string>  $results  Keyed by tool_use id.
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    public function resultsMessage(array $calls, array $results): array
    {
        $blocks = [];

        foreach ($calls as $call) {
            $raw = $results[$call['id']] ?? null;

            if (null === $raw) {
                $blocks[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $call['id'],
                    'content' => "Tool '{$call['name']}' produced no result.",
                    'is_error' => true,
                ];

                continue;
            }

            $decoded = json_decode($raw, true);
            $isError = is_array($decoded) && isset($decoded['error']);

            $blocks[] = array_filter([
                'type' => 'tool_result',
                'tool_use_id' => $call['id'],
                'content' => $raw,
                'is_error' => $isError ?: null,
            ], static fn ($v): bool => null !== $v);
        }

        return ['role' => 'user', 'content' => $blocks];
    }

    /**
     * Drive the loop until the model stops asking for tools.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @param  callable(string, mixed): void|null  $observer  Receives ('assistant'|'tools'|'batch', payload).
     * @return array{messages: list<array<string, mixed>>, final: object, turns: int, batches: list<array<string, mixed>>}
     */
    public function run(array $messages, array $tools, string $tier = 'investigate', ?string $system = null, ?callable $observer = null, int $maxTurns = 6): array
    {
        $model = $this->triage->model($tier);
        $batches = [];
        $turns = 0;
        $final = null;

        while ($turns < $maxTurns) {
            $turns++;

            $params = [
                'maxTokens' => 4096,
                'messages' => $messages,
                'model' => $model,
                'tools' => $tools,
            ];

            if (null !== $system) {
                $params['system'] = [[
                    'type' => 'text',
                    'text' => $system,
                    'cache_control' => ['type' => 'ephemeral'],
                ]];
            }

            $message = $this->triage->client()->messages->create(...$params);
            $final = $message;

            $notify = static function (string $event, mixed $payload) use ($observer): void {
                if (null !== $observer) {
                    $observer($event, $payload);
                }
            };

            $notify('assistant', $message);

            if ('tool_use' !== $message->stopReason) {
                break;
            }

            $calls = $this->toolCalls($message);
            $notify('tools', $calls);

            $batch = $this->executor->runBoth($calls);
            $batch['calls'] = $calls;
            $batches[] = $batch;

            $notify('batch', $batch);

            // Full content, not just the text -- the tool_use blocks must be
            // preserved or the tool_result ids have nothing to answer.
            $messages[] = ['role' => 'assistant', 'content' => $message->content];
            $messages[] = $this->resultsMessage($calls, $batch['results']);
        }

        return [
            'messages' => $messages,
            'final' => $final,
            'turns' => $turns,
            'batches' => $batches,
        ];
    }
}
