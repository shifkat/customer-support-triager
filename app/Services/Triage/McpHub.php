<?php

declare(strict_types=1);

namespace App\Services\Triage;

use Anthropic\Lib\Tools\BetaMcp;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;

/**
 * Connects the triager to its MCP servers (assignment steps 9, 10, 11).
 *
 * The assignment's three MCP integrations deliberately use the two different
 * styles the Claude API offers, chosen per server rather than by habit:
 *
 *   GitHub  -> hosted MCP connector. The server is already public HTTPS, so
 *              Anthropic dials it directly via the `mcpServers` request
 *              parameter. No client here, no local process, no tunnel.
 *              See githubConnectorParams() below.
 *
 *   Slack   -> client-side over stdio. The reference Slack MCP server is an
 *   Policy     npx process, and our policy server is an artisan command --
 *              both are local, so the hosted connector cannot reach either.
 *              We run an MCP client in-process and bridge their tools into
 *              the Claude API with BetaMcp::tools().
 *
 * Clients are cached per key and closed by disconnectAll(), because each one
 * owns a child process.
 *
 * @phpstan-type McpTools list<mixed>
 */
final class McpHub
{
    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * Connect to the custom support-policy server over stdio.
     *
     * stdio is the right transport here: the policy engine is internal, so it
     * gets no open port and no auth surface, and its lifetime is scoped to
     * this CLI run. routes/ai.php also exposes it over HTTP for remote
     * clients, but the triager itself has no reason to go over the network to
     * talk to its own application.
     */
    public function policyClient(): Client
    {
        return $this->clients['policy'] ??= $this->connectStdio(
            command: (string) config('triage.policy_server.php_binary'),
            args: ['artisan', 'mcp:start', (string) config('triage.policy_server.name')],
        );
    }

    /**
     * Connect to Slack over stdio.
     *
     * driver=live spawns the reference Slack MCP server via npx.
     * driver=stub spawns our own SlackStubServer, which exposes identical
     * tool names and argument shapes. The triager cannot tell the difference,
     * which is the point -- going live is a .env change.
     */
    public function slackClient(): Client
    {
        return $this->clients['slack'] ??= match ((string) config('triage.slack.driver')) {
            'live' => $this->connectStdio(
                command: 'npx',
                args: ['-y', '@modelcontextprotocol/server-slack'],
                env: [
                    'SLACK_BOT_TOKEN' => (string) config('triage.slack.bot_token'),
                    'SLACK_TEAM_ID' => (string) config('triage.slack.team_id'),
                    'PATH' => (string) getenv('PATH'),
                ],
            ),
            default => $this->connectStdio(
                command: (string) config('triage.policy_server.php_binary'),
                args: ['artisan', 'mcp:start', 'slack-stub'],
            ),
        };
    }

    public function slackIsStub(): bool
    {
        return 'live' !== (string) config('triage.slack.driver');
    }

    /**
     * Tools from an MCP client, converted into Claude API tool definitions.
     *
     * BetaMcp::tools() wraps each MCP tool so the SDK's tool runner can
     * invoke it -- the runner calls back into the MCP client, so no manual
     * schema translation or dispatch code is needed.
     *
     * @return list<mixed>
     */
    public function toolsFrom(Client $client): array
    {
        return BetaMcp::tools($client->listTools()->tools, $client);
    }

    /** @return list<string> */
    public function toolNames(Client $client): array
    {
        return array_map(
            static fn ($tool): string => (string) $tool->name,
            $client->listTools()->tools,
        );
    }

    /**
     * Request parameters for the GitHub hosted connector (step 9).
     *
     * The toolset is allowlisted down to three tools. The GitHub MCP server
     * exposes on the order of ninety, and every schema would otherwise be
     * loaded into the prompt on every request -- the single biggest token win
     * in this project (measured in docs/SUBMISSION.md).
     *
     * @return array{mcpServers: list<array<string, mixed>>, tools: list<array<string, mixed>>, betas: list<string>}
     */
    public function githubConnectorParams(): array
    {
        $pat = (string) config('triage.github.pat');

        if ('' === trim($pat)) {
            throw new \RuntimeException(
                'GITHUB_PAT is not set. Create a fine-grained token with Issues '.
                'read+write on the repo in GITHUB_REPO, then add it to .env.'
            );
        }

        $configs = [];
        foreach ((array) config('triage.github.allowed_tools') as $tool) {
            $configs[(string) $tool] = ['enabled' => true];
        }

        return [
            'mcpServers' => [[
                'type' => 'url',
                'url' => (string) config('triage.github.mcp_url'),
                'name' => 'github',
                'authorization_token' => $pat,
            ]],
            'tools' => [[
                'type' => 'mcp_toolset',
                'mcp_server_name' => 'github',
                'default_config' => ['enabled' => false],
                'configs' => $configs,
            ]],
            'betas' => ['mcp-client-2025-11-20'],
        ];
    }

    public function disconnectAll(): void
    {
        foreach ($this->clients as $client) {
            try {
                $client->disconnect();
            } catch (\Throwable) {
                // The child process may already be gone; nothing to salvage.
            }
        }

        $this->clients = [];
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string>|null  $env
     */
    private function connectStdio(string $command, array $args, ?array $env = null): Client
    {
        $client = Client::builder()->build();

        $client->connect(new StdioTransport(
            command: $command,
            args: $args,
            cwd: base_path(),
            env: $env,
        ));

        return $client;
    }
}
