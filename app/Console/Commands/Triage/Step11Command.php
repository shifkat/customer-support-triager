<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\McpHub;
use App\Services\Triage\TriageClient;

/**
 * Assignment step 11 -- the custom MCP server.
 *
 * Connects to our own support-policy server over stdio, lists what it
 * exposes, and calls the tool across a spread of inputs so the policy layers
 * are visible rather than asserted.
 */
final class Step11Command extends TriageCommand
{
    protected $signature = 'triage:step11 {--via-model : also let Claude call the tool, instead of calling it directly}';

    protected $description = 'Step 11: custom MCP server (support-policy) over stdio';

    public function __construct(TriageClient $triage, private readonly McpHub $hub)
    {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 11', 'Custom MCP server — support-policy');

            $this->section('Transport choice');
            $this->kv('transport', 'stdio');
            $this->kv('command', config('triage.policy_server.php_binary').' artisan mcp:start support-policy');
            $this->line('  <fg=gray>The policy engine is internal, so stdio gives it no open port and no</>');
            $this->line('  <fg=gray>auth surface, and its lifetime is scoped to this CLI run. routes/ai.php</>');
            $this->line('  <fg=gray>also registers it over Streamable HTTP for remote clients — which is</>');
            $this->line('  <fg=gray>how GitHub is consumed in step 9 — from the same implementation.</>');

            $client = $this->hub->policyClient();

            $this->section('Handshake');
            $tools = $client->listTools()->tools;
            $this->ok('connected over stdio');
            foreach ($tools as $tool) {
                $this->kv('tool', (string) $tool->name);
                $this->kv('  description', mb_strimwidth((string) $tool->description, 0, 100, '…'));
                $this->kv('  inputs', implode(', ', array_keys((array) ($tool->inputSchema['properties'] ?? []))));
            }

            $this->section('Calling get-routing-policy');

            $cases = [
                ['P1', 'data_loss', 'enterprise'],
                ['P2', 'billing', 'business'],
                ['P3', 'performance', 'pro'],
                ['P4', 'how_to', 'free'],
            ];

            $rows = [];
            foreach ($cases as [$urgency, $topic, $plan]) {
                $result = $client->callTool('get-routing-policy', [
                    'urgency' => $urgency,
                    'topic' => $topic,
                    'plan_tier' => $plan,
                ]);

                $decision = $result->structuredContent ?? [];

                $rows[] = [
                    "{$urgency} / {$topic}",
                    $plan,
                    $decision['team'] ?? '?',
                    $decision['slack_channel'] ?? '?',
                    ($decision['sla_first_response_mins'] ?? '?').' min',
                    ($decision['requires_github_issue'] ?? false) ? 'yes' : 'no',
                    ($decision['escalate_to_human'] ?? false) ? 'yes' : 'no',
                ];
            }

            $this->newLine();
            $this->table(
                ['ticket', 'plan', 'team', 'channel', 'SLA', 'issue?', 'human?'],
                $rows,
            );

            $this->section('Rationale for the first case');
            $first = $client->callTool('get-routing-policy', [
                'urgency' => 'P1', 'topic' => 'data_loss', 'plan_tier' => 'enterprise',
            ])->structuredContent ?? [];

            foreach ((array) ($first['rationale'] ?? []) as $i => $line) {
                $this->line('  '.($i + 1).'. '.$line);
            }
            $this->kv('policy version', (string) ($first['policy_version'] ?? '?'));

            if ($this->option('via-model')) {
                $this->viaModel();
            }

            $this->hub->disconnectAll();
            $this->newLine();
            $this->ok('Custom MCP server verified over stdio.');
        });
    }

    /** Let Claude discover and call the tool, rather than us invoking it directly. */
    private function viaModel(): void
    {
        $this->section('Same tool, invoked by Claude');

        $client = $this->hub->policyClient();

        $runner = $this->triage->client()->beta->messages->toolRunner(
            maxTokens: 2048,
            messages: [[
                'role' => 'user',
                'content' => 'A P1 data_loss ticket just arrived from an enterprise customer. '.
                    'Use the routing policy tool to find out who owns it and how fast we must '.
                    'respond, then state the answer in one sentence.',
            ]],
            model: $this->triage->model('investigate'),
            tools: $this->hub->toolsFrom($client),
            maxIterations: 5,
        );

        foreach ($runner as $message) {
            foreach ($message->content as $block) {
                if ('tool_use' === $block->type) {
                    $this->line('  <fg=magenta>[tool call]</> '.$block->name.' '.json_encode($block->input));
                } elseif ('text' === $block->type && '' !== trim($block->text)) {
                    $this->line('  '.trim($block->text));
                }
            }
        }
    }
}
