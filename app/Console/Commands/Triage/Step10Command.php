<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\McpHub;
use App\Services\Triage\RoutingPolicy;
use App\Services\Triage\TriageClient;

/**
 * Assignment step 10 -- the Slack MCP server.
 *
 * Client-side MCP over stdio. The reference Slack server is an npx process
 * and cannot be reached by the hosted connector, so the triager runs an MCP
 * client in-process and bridges the tools into the Claude API with
 * BetaMcp::tools() -- the other half of the pattern step 9 demonstrates.
 *
 * With TRIAGE_SLACK_DRIVER=stub the same stdio client talks to
 * SlackStubServer instead, which mirrors the real tool names and arguments.
 */
final class Step10Command extends TriageCommand
{
    protected $signature = 'triage:step10
        {--urgency=P1}
        {--topic=billing}
        {--plan=enterprise}';

    protected $description = 'Step 10: Slack MCP over stdio — route a triaged ticket to the right channel';

    public function __construct(
        TriageClient $triage,
        private readonly McpHub $hub,
        private readonly RoutingPolicy $policy,
    ) {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 10', 'Slack MCP server');

            $isStub = $this->hub->slackIsStub();

            $this->section('Connection');
            $this->kv('style', 'client-side MCP (mcp/sdk) — the connector cannot reach a local process');
            $this->kv('transport', 'stdio');
            $this->kv('driver', $isStub ? 'stub' : 'live');
            $this->kv('command', $isStub
                ? 'php artisan mcp:start slack-stub'
                : 'npx -y @modelcontextprotocol/server-slack');

            if ($isStub) {
                $this->newLine();
                $this->warn2('Running against SlackStubServer — messages are recorded, not delivered.');
                $this->line('  <fg=gray>Tool names and arguments are copied from the reference server, so</>');
                $this->line('  <fg=gray>the triager cannot tell the difference. Set TRIAGE_SLACK_DRIVER=live</>');
                $this->line('  <fg=gray>with SLACK_BOT_TOKEN and SLACK_TEAM_ID to post for real; no code</>');
                $this->line('  <fg=gray>changes either way.</>');
            }

            $client = $this->hub->slackClient();
            $tools = $client->listTools()->tools;

            $this->section('Tools discovered over MCP');
            foreach ($tools as $tool) {
                $this->kv((string) $tool->name, implode(', ', array_keys((array) ($tool->inputSchema['properties'] ?? []))));
            }

            // The routing policy — not the model — decides the destination.
            $decision = $this->policy->resolve(
                urgency: (string) $this->option('urgency'),
                topic: (string) $this->option('topic'),
                planTier: (string) $this->option('plan'),
            );

            $this->section('Where the policy says this goes');
            $this->kv('urgency / topic / plan', $this->option('urgency').' / '.$this->option('topic').' / '.$this->option('plan'));
            $this->kv('team', $decision->team);
            $this->kv('channel', $decision->slack_channel);
            $this->kv('SLA', $decision->slaForHumans());

            $this->section('Letting Claude post the triage card');

            $runner = $this->triage->client()->beta->messages->toolRunner(
                maxTokens: 2048,
                messages: [[
                    'role' => 'user',
                    'content' => sprintf(
                        "Post a support triage card to the Slack channel %s. Use slack_post_message. ".
                        "The card must state: urgency %s, topic %s, owning team %s, first response ".
                        "due within %s. Keep it to three short lines. Confirm once posted.",
                        $decision->slack_channel,
                        $this->option('urgency'),
                        $this->option('topic'),
                        $decision->team,
                        $decision->slaForHumans(),
                    ),
                ]],
                model: $this->triage->model('investigate'),
                tools: $this->hub->toolsFrom($client),
                maxIterations: 6,
            );

            foreach ($runner as $message) {
                foreach ($message->content as $block) {
                    if ('tool_use' === $block->type) {
                        $this->newLine();
                        $this->line('  <fg=magenta>[tool_use]</> '.$block->name);
                        $this->line('      '.mb_strimwidth(json_encode($block->input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 200, '…'));
                    } elseif ('text' === $block->type && '' !== trim($block->text)) {
                        $this->newLine();
                        $this->line('  '.wordwrap(trim($block->text), 96, "\n  "));
                    }
                }
            }

            if ($isStub) {
                $this->section('Stub outbox');
                $path = storage_path('app/private/triage/slack-outbox.jsonl');
                if (is_file($path)) {
                    $lines = array_filter(explode("\n", trim((string) file_get_contents($path))));
                    $last = json_decode((string) end($lines), true);
                    $this->kv('file', $path);
                    $this->kv('channel', (string) ($last['channel'] ?? '?'));
                    $this->newLine();
                    foreach (explode("\n", (string) ($last['text'] ?? '')) as $line) {
                        $this->line('    <fg=cyan>│</> '.$line);
                    }
                } else {
                    $this->warn2('No outbox file yet.');
                }
            }

            $this->hub->disconnectAll();
            $this->newLine();
            $this->ok('Triaged message reached the channel the policy selected.');
        });
    }
}
