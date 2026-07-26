<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\McpHub;
use App\Services\Triage\TriageClient;

/**
 * Assignment step 9 -- the GitHub MCP server.
 *
 * Uses the hosted MCP connector rather than a local MCP client: the GitHub
 * server is already public HTTPS, so Anthropic connects to it directly and
 * PHP needs no client, no child process, and no tunnel. Contrast with steps
 * 10 and 11, which talk to local processes and therefore must run a client
 * in-process.
 */
final class Step09Command extends TriageCommand
{
    protected $signature = 'triage:step9
        {--ticket=p2-integration.md}
        {--dry-run : search for duplicates but do not create an issue}';

    protected $description = 'Step 9: GitHub MCP via the hosted connector — search and file an issue';

    public function __construct(TriageClient $triage, private readonly McpHub $hub)
    {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 9', 'GitHub MCP server');

            $repo = (string) config('triage.github.repo');
            if ('' === trim($repo)) {
                throw new \RuntimeException('GITHUB_REPO is not set in .env.');
            }

            $params = $this->hub->githubConnectorParams();

            $this->section('Connection');
            $this->kv('style', 'hosted MCP connector (no local MCP client)');
            $this->kv('url', (string) config('triage.github.mcp_url'));
            $this->kv('transport', 'Streamable HTTP, dialled by Anthropic');
            $this->kv('auth', 'authorization_token = GITHUB_PAT');
            $this->kv('beta header', implode(', ', $params['betas']));
            $this->kv('repo', $repo);
            $this->kv('tools allowlisted', implode(', ', (array) config('triage.github.allowed_tools')));
            $this->line('  <fg=gray>The server exposes its whole catalogue by default. Allowlisting three</>');
            $this->line('  <fg=gray>issue tools keeps every other schema out of the prompt — measured in</>');
            $this->line('  <fg=gray>step 7 as the single biggest token saving in this project.</>');

            $ticket = (string) file_get_contents(storage_path('app/fixtures/tickets/'.$this->option('ticket')));

            $instruction = $this->option('dry-run')
                ? "Search {$repo} for an existing open issue that already covers this ticket. ".
                  'Report what you found. Do NOT create an issue.'
                : "First search {$repo} for an existing open issue covering this ticket. ".
                  'If there is a genuine duplicate, report it and stop. Otherwise create one '.
                  'issue with a precise title, a body containing the customer impact, the '.
                  'reproduction detail from the ticket, and a Triage section stating urgency '.
                  'and owning team. Then report the issue number and URL.';

            $this->section($this->option('dry-run') ? 'Searching (dry run)' : 'Searching, then filing');

            $message = $this->triage->client()->beta->messages->create(
                maxTokens: 4096,
                messages: [[
                    'role' => 'user',
                    'content' => $instruction."\n\n<ticket>\n".trim($ticket)."\n</ticket>",
                ]],
                model: $this->triage->model('investigate'),
                mcpServers: $params['mcpServers'],
                tools: $params['tools'],
                betas: $params['betas'],
            );

            $issueUrl = null;

            foreach ($message->content as $block) {
                switch ($block->type) {
                    case 'mcp_tool_use':
                        $this->newLine();
                        $this->line('  <fg=magenta>[mcp_tool_use]</> '.$block->name.'  <fg=gray>(server: '.$block->serverName.')</>');
                        $this->line('      '.mb_strimwidth(json_encode($block->input, JSON_UNESCAPED_SLASHES), 0, 180, '…'));
                        break;

                    case 'mcp_tool_result':
                        $text = '';
                        foreach ((array) $block->content as $part) {
                            $text .= (string) ($part->text ?? '');
                        }
                        if ($block->isError) {
                            $this->warn2('tool error: '.mb_strimwidth($text, 0, 200, '…'));
                            break;
                        }
                        $this->line('  <fg=gray>      → '.mb_strimwidth(preg_replace('/\s+/', ' ', $text) ?? '', 0, 180, '…').'</>');
                        if (preg_match('#https://github\.com/[^\s"\\\\]+/issues/\d+#', $text, $m)) {
                            $issueUrl = $m[0];
                        }
                        break;

                    case 'text':
                        if ('' !== trim($block->text)) {
                            $this->newLine();
                            $this->line('  '.wordwrap(trim($block->text), 96, "\n  "));
                        }
                        break;
                }
            }

            $this->section('Result');
            if (null !== $issueUrl) {
                $this->ok('issue: '.$issueUrl);
            } elseif ($this->option('dry-run')) {
                $this->kv('dry run', 'no issue created, as requested');
            } else {
                $this->warn2('No issue URL found in the tool results — check the transcript above.');
            }

            $this->kv('stop reason', (string) $message->stopReason);
            $this->reportUsage($message->model, $message->usage);
        });
    }
}
