<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\TriageClient;

/**
 * Assignment step 8 -- a built-in server tool.
 *
 * Web search, scoped to the product's own documentation domains. Chosen
 * because the most common support action is answering from current docs, and
 * search is the only built-in tool that reaches past the training cutoff and
 * returns citations an agent can paste into a reply. Code execution and bash
 * solve problems this workflow does not have.
 */
final class Step08Command extends TriageCommand
{
    protected $signature = 'triage:step8 {--ticket=p4-howto.md} {--open : search the open web instead of the configured domains}';

    protected $description = 'Step 8: web search server tool for documentation answers';

    public function __construct(TriageClient $triage)
    {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 8', 'Built-in server tool: web search');

            $domains = (array) config('triage.web_search.allowed_domains');
            $maxUses = (int) config('triage.web_search.max_uses');

            $this->section('Why web search');
            $this->line('  <fg=gray>Support answers go stale. A model answering "how do I rotate an API</>');
            $this->line('  <fg=gray>key" from memory will confidently describe last year\'s dashboard.</>');
            $this->line('  <fg=gray>Search reaches current docs and returns citations the agent can</>');
            $this->line('  <fg=gray>paste into the reply, which is what makes the answer auditable.</>');
            $this->line('  <fg=gray>It also runs server-side — no sandbox, no client-side execution.</>');

            $this->section('Configuration');
            $this->kv('tool type', 'web_search_20260209');
            $this->kv('max uses', (string) $maxUses);
            $this->kv('allowed domains', $this->option('open') ? '(unscoped for this run)' : implode(', ', $domains));

            if (! $this->option('open')) {
                $this->line('  <fg=gray>Domain scoping keeps the agent quoting our documentation rather</>');
                $this->line('  <fg=gray>than a stale blog post, and caps the search budget per ticket.</>');
            }

            $ticket = (string) file_get_contents(storage_path('app/fixtures/tickets/'.$this->option('ticket')));

            $tool = ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => $maxUses];
            if (! $this->option('open')) {
                $tool['allowed_domains'] = array_values($domains);
            }

            $this->section('Answering the ticket');

            $message = $this->triage->client()->messages->create(
                maxTokens: 4096,
                messages: [[
                    'role' => 'user',
                    'content' => "You are drafting a support reply. Search the documentation for the ".
                        "answer, then reply to the customer in at most two short paragraphs. Cite ".
                        "the page you used.\n\n<ticket>\n".trim($ticket)."\n</ticket>",
                ]],
                model: $this->triage->model('reply'),
                tools: [$tool],
            );

            $searches = 0;
            $citations = [];

            foreach ($message->content as $block) {
                switch ($block->type) {
                    case 'server_tool_use':
                        $searches++;
                        $this->line('  <fg=magenta>[web_search]</> '.json_encode($block->input, JSON_UNESCAPED_SLASHES));
                        break;

                    case 'web_search_tool_result':
                        // A failed server tool returns HTTP 200 with an error
                        // object in content, not an exception — so branch on
                        // the shape before assuming it is a result list.
                        $content = $block->content;
                        if (is_object($content) && isset($content->errorCode)) {
                            $this->warn2('search error: '.$content->errorCode);
                            break;
                        }
                        $results = is_array($content) ? $content : [];
                        $this->line('  <fg=gray>       → '.count($results).' result(s)</>');
                        foreach ($results as $result) {
                            $citations[] = ['title' => (string) ($result->title ?? ''), 'url' => (string) ($result->url ?? '')];
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

            if ($citations !== []) {
                $this->section('Citations');
                foreach (array_slice($citations, 0, 8) as $citation) {
                    $this->kv(mb_strimwidth($citation['title'], 0, 44, '…'), $citation['url']);
                }
            }

            $this->section('Diagnostics');
            $this->kv('searches performed', (string) $searches);
            $this->kv('stop reason', (string) $message->stopReason);

            if ('pause_turn' === $message->stopReason) {
                $this->warn2('Server-tool loop paused. Re-send the assistant turn to resume — see the loop in TriageOrchestrator.');
            }

            $usage = $message->usage;
            $this->reportUsage($message->model, $usage);
            if (null !== $usage->serverToolUse) {
                $this->kv('server tool requests', (string) ($usage->serverToolUse->webSearchRequests ?? 0));
            }
        });
    }
}
