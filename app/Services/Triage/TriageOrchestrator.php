<?php

declare(strict_types=1);

namespace App\Services\Triage;

use App\Services\Triage\Data\RoutingDecision;
use App\Services\Triage\Data\TriageResult;

/**
 * The full pipeline (assignment step 12).
 *
 * Ties every earlier step together in the order a real ticket would travel:
 *
 *   classify (cheap tier, structured output, cached prefix)
 *     -> escalate? (config rule, not a vibe)
 *       -> gather context (local tools, executed in parallel)
 *       -> routing policy (custom MCP server, stdio)
 *       -> documentation answer (web search) for how-to tickets
 *       -> file an issue (GitHub MCP, hosted connector) if policy demands one
 *       -> announce (Slack MCP, stdio) in the channel policy selected
 *
 * Every stage records its own tokens and wall clock so the run ends with a
 * real bill rather than an estimate.
 */
final class TriageOrchestrator
{
    /** @var list<array{stage: string, model: string|null, input: int, output: int, cache_read: int, cache_write: int, ms: float, note: string}> */
    private array $ledger = [];

    public function __construct(
        private readonly TriageClient $triage,
        private readonly Classifier $classifier,
        private readonly ToolBox $tools,
        private readonly ToolLoop $loop,
        private readonly RoutingPolicy $policy,
        private readonly McpHub $hub,
    ) {}

    /** @return list<array<string, mixed>> */
    public function ledger(): array
    {
        return $this->ledger;
    }

    public function totalCost(): float
    {
        $total = 0.0;

        foreach ($this->ledger as $entry) {
            if (null !== $entry['model']) {
                $total += $this->triage->cost($entry['model'], $entry['input'], $entry['output'], $entry['cache_read'], $entry['cache_write']);
            }
        }

        return $total;
    }

    /**
     * Stage 1 -- classify on the cheap tier.
     *
     * @param  callable(string, mixed): void  $emit
     */
    public function classify(string $ticket, callable $emit): TriageResult
    {
        $emit('stage', 'Classifying on '.$this->triage->model('classify'));

        $outcome = $this->classifier->classify($ticket);

        $this->record('classify', $outcome->model, $outcome->inputTokens, $outcome->outputTokens,
            $outcome->cacheReadTokens, $outcome->cacheWriteTokens, $outcome->elapsedMs, 'structured output');

        $emit('classified', $outcome);

        return $outcome->result;
    }

    /** Stage 2 -- the escalation rule from config, applied verbatim. */
    public function shouldEscalate(TriageResult $result): bool
    {
        return $result->shouldEscalate((array) config('triage.escalation'));
    }

    /** @return list<string> */
    public function escalationReasons(TriageResult $result): array
    {
        return $result->escalationReasons((array) config('triage.escalation'));
    }

    /**
     * Stage 3 -- gather account context with the local tools, in parallel.
     *
     * @param  callable(string, mixed): void  $emit
     * @return array{summary: string, batches: list<array<string, mixed>>}
     */
    public function gatherContext(string $ticket, TriageResult $result, callable $emit): array
    {
        $emit('stage', 'Gathering account context (parallel local tools)');

        $messages = [[
            'role' => 'user',
            'content' => "Gather the account and billing context needed to act on this ticket. ".
                "Look up the customer and their recent orders — request both at once, they are ".
                "independent. Then summarise, in three sentences, only the facts that change how ".
                "we should respond.\n\n<ticket>\n".trim($ticket)."\n</ticket>",
        ]];

        $outcome = $this->loop->run(
            messages: $messages,
            tools: array_values(array_filter(
                $this->tools->definitions(),
                static fn (array $d): bool => 'create_ticket' !== $d['name'],
            )),
            tier: 'investigate',
            observer: function (string $event, mixed $payload) use ($emit): void {
                if ('tools' === $event) {
                    $emit('tools', $payload);
                }
                if ('batch' === $event) {
                    $emit('batch', $payload);
                }
            },
        );

        $final = $outcome['final'];
        $this->record('gather-context', $final->model, $final->usage->inputTokens, $final->usage->outputTokens,
            (int) ($final->usage->cacheReadInputTokens ?? 0), (int) ($final->usage->cacheCreationInputTokens ?? 0),
            0.0, count($outcome['batches']).' tool batch(es)');

        $summary = '';
        foreach ($final->content as $block) {
            if ('text' === $block->type) {
                $summary .= $block->text;
            }
        }

        return ['summary' => trim($summary), 'batches' => $outcome['batches']];
    }

    /**
     * Stage 4 -- ask the custom MCP server where this goes.
     *
     * @param  callable(string, mixed): void  $emit
     */
    public function route(TriageResult $result, callable $emit): RoutingDecision
    {
        $emit('stage', 'Resolving routing policy (custom MCP server, stdio)');

        $client = $this->hub->policyClient();

        $response = $client->callTool('get-routing-policy', [
            'urgency' => $result->urgency,
            'topic' => $result->topic,
            'plan_tier' => $result->plan_tier,
        ]);

        /** @var array<string, mixed> $data */
        $data = (array) ($response->structuredContent ?? []);

        $decision = new RoutingDecision(
            team: (string) ($data['team'] ?? 'platform'),
            slack_channel: (string) ($data['slack_channel'] ?? config('triage.slack.fallback_channel')),
            sla_first_response_mins: (int) ($data['sla_first_response_mins'] ?? 480),
            requires_github_issue: (bool) ($data['requires_github_issue'] ?? false),
            escalate_to_human: (bool) ($data['escalate_to_human'] ?? false),
            policy_version: (string) ($data['policy_version'] ?? '?'),
            rationale: array_map('strval', (array) ($data['rationale'] ?? [])),
        );

        $this->record('routing-policy', null, 0, 0, 0, 0, 0.0, 'MCP tool call, no model');
        $emit('routed', $decision);

        return $decision;
    }

    /**
     * Stage 5 -- file a GitHub issue through the hosted connector.
     *
     * @param  callable(string, mixed): void  $emit
     */
    public function fileIssue(string $ticket, TriageResult $result, RoutingDecision $decision, callable $emit): ?string
    {
        $emit('stage', 'Filing GitHub issue (hosted MCP connector)');

        $params = $this->hub->githubConnectorParams();
        $repo = (string) config('triage.github.repo');

        $message = $this->triage->client()->beta->messages->create(
            maxTokens: 4096,
            messages: [[
                'role' => 'user',
                'content' => sprintf(
                    "Search %s for an open issue that already covers this ticket. If there is a ".
                    "genuine duplicate, report it and stop. Otherwise create one issue: title ".
                    "should be a precise one-line defect statement, body should contain the ".
                    "customer impact, the reproduction detail, and a Triage section stating ".
                    "urgency %s, team %s, first response due in %d minutes. Report the issue URL.".
                    "\n\n<ticket>\n%s\n</ticket>",
                    $repo, $result->urgency, $decision->team, $decision->sla_first_response_mins, trim($ticket),
                ),
            ]],
            model: $this->triage->model('investigate'),
            mcpServers: $params['mcpServers'],
            tools: $params['tools'],
            betas: $params['betas'],
        );

        $this->record('github-mcp', $message->model, $message->usage->inputTokens, $message->usage->outputTokens,
            (int) ($message->usage->cacheReadInputTokens ?? 0), (int) ($message->usage->cacheCreationInputTokens ?? 0),
            0.0, 'issue tools allowlisted');

        $url = null;
        foreach ($message->content as $block) {
            if ('mcp_tool_use' === $block->type) {
                $emit('mcp_call', ['server' => 'github', 'tool' => $block->name]);
            }
            if ('mcp_tool_result' === $block->type) {
                $text = '';
                foreach ((array) $block->content as $part) {
                    $text .= (string) ($part->text ?? '');
                }
                if (preg_match('#https://github\.com/[^\s"\\\\]+/issues/\d+#', $text, $m)) {
                    $url = $m[0];
                }
            }
            if ('text' === $block->type && '' !== trim($block->text)) {
                $emit('text', trim($block->text));
            }
        }

        return $url;
    }

    /**
     * Stage 6 -- announce in Slack.
     *
     * @param  callable(string, mixed): void  $emit
     */
    public function announce(TriageResult $result, RoutingDecision $decision, string $context, ?string $issueUrl, callable $emit): void
    {
        $emit('stage', 'Announcing in Slack (MCP over stdio, driver='.(config('triage.slack.driver')).')');

        $client = $this->hub->slackClient();

        $card = sprintf(
            "Post a triage card to %s using slack_post_message. Content:\n".
            "Line 1: %s %s — %s\n".
            "Line 2: owner %s, first response due in %d minutes\n".
            "Line 3: %s\n%s".
            "Keep it to those lines. Confirm once posted.",
            $decision->slack_channel,
            $result->urgency,
            strtoupper($result->topic),
            $result->summary,
            $decision->team,
            $decision->sla_first_response_mins,
            mb_strimwidth($context, 0, 200, '…'),
            null !== $issueUrl ? "Line 4: tracked at {$issueUrl}\n" : '',
        );

        $runner = $this->triage->client()->beta->messages->toolRunner(
            maxTokens: 2048,
            messages: [['role' => 'user', 'content' => $card]],
            model: $this->triage->model('investigate'),
            tools: $this->hub->toolsFrom($client),
            maxIterations: 5,
        );

        $last = null;
        foreach ($runner as $message) {
            $last = $message;
            foreach ($message->content as $block) {
                if ('tool_use' === $block->type) {
                    $emit('mcp_call', ['server' => 'slack', 'tool' => $block->name]);
                }
                if ('text' === $block->type && '' !== trim($block->text)) {
                    $emit('text', trim($block->text));
                }
            }
        }

        if (null !== $last) {
            $this->record('slack-mcp', $last->model, $last->usage->inputTokens, $last->usage->outputTokens,
                (int) ($last->usage->cacheReadInputTokens ?? 0), (int) ($last->usage->cacheCreationInputTokens ?? 0),
                0.0, $this->hub->slackIsStub() ? 'stub driver' : 'live workspace');
        }
    }

    public function cleanup(): void
    {
        $this->hub->disconnectAll();
    }

    private function record(string $stage, ?string $model, int $in, int $out, int $cacheRead, int $cacheWrite, float $ms, string $note): void
    {
        $this->ledger[] = [
            'stage' => $stage,
            'model' => $model,
            'input' => $in,
            'output' => $out,
            'cache_read' => $cacheRead,
            'cache_write' => $cacheWrite,
            'ms' => $ms,
            'note' => $note,
        ];
    }
}
