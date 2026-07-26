<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\Classifier;
use App\Services\Triage\McpHub;
use App\Services\Triage\ToolBox;
use App\Services\Triage\TriageClient;

/**
 * Assignment step 7 -- token optimisation.
 *
 * Four changes, each measured with countTokens or real usage figures rather
 * than asserted. The numbers this prints are the ones quoted in
 * docs/SUBMISSION.md.
 */
final class Step07Command extends TriageCommand
{
    protected $signature = 'triage:step7 {--ticket=p1-billing.md}';

    protected $description = 'Step 7: measured token optimisations';

    public function __construct(
        TriageClient $triage,
        private readonly Classifier $classifier,
        private readonly ToolBox $tools,
        private readonly McpHub $hub,
    ) {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 7', 'Token optimisation');

            $ticket = (string) file_get_contents(storage_path('app/fixtures/tickets/'.$this->option('ticket')));
            $client = $this->triage->client();
            $model = $this->triage->model('classify');

            // ---------------------------------------------------------------
            $this->section('1. Prompt caching on the frozen system prompt');

            $this->line('  <fg=gray>The taxonomy is byte-identical on every ticket; the ticket text is</>');
            $this->line('  <fg=gray>not. Cache breakpoint on the system block, volatile text after it.</>');
            $this->newLine();

            $first = $this->classifier->classify($ticket, cache: true);
            $second = $this->classifier->classify($ticket, cache: true);

            $this->table(
                ['call', 'uncached in', 'cache write', 'cache read', 'cost'],
                [
                    ['1st (cold)', (string) $first->inputTokens, (string) $first->cacheWriteTokens, (string) $first->cacheReadTokens,
                        '$'.number_format($this->triage->cost($first->model, $first->inputTokens, $first->outputTokens, $first->cacheReadTokens, $first->cacheWriteTokens), 6)],
                    ['2nd (warm)', (string) $second->inputTokens, (string) $second->cacheWriteTokens, (string) $second->cacheReadTokens,
                        '$'.number_format($this->triage->cost($second->model, $second->inputTokens, $second->outputTokens, $second->cacheReadTokens, $second->cacheWriteTokens), 6)],
                ],
            );

            if ($second->cacheReadTokens > 0) {
                $this->ok($second->cacheReadTokens.' tokens served from cache at ~10% of input price.');
            } else {
                $this->warn2(
                    'No cache read. The system prompt is '.$this->countTokens($model, $this->classifier->systemPrompt()).
                    ' tokens; the minimum cacheable prefix on '.$model.' is 4096 for Haiku 4.5, so a short taxonomy simply will not cache. '.
                    'This is a real limit, not a misconfiguration — see the note in docs/SUBMISSION.md.'
                );
            }

            // ---------------------------------------------------------------
            $this->section('2. Scoping the GitHub MCP toolset');

            $this->line('  <fg=gray>The hosted GitHub MCP server exposes its whole catalogue by default.</>');
            $this->line('  <fg=gray>We allowlist three issue tools, so the rest never enter the prompt.</>');
            $this->newLine();

            try {
                $all = $this->measureGithubToolset(scoped: false);
                $scoped = $this->measureGithubToolset(scoped: true);

                $this->table(
                    ['toolset', 'tools loaded', 'prompt tokens'],
                    [
                        ['all tools', (string) $all['count'], (string) $all['tokens']],
                        ['allowlisted', (string) $scoped['count'], (string) $scoped['tokens']],
                    ],
                );

                $saved = $all['tokens'] - $scoped['tokens'];
                if ($saved > 0) {
                    $this->ok($saved.' tokens saved on every single request that touches GitHub.');
                }
            } catch (\Throwable $e) {
                $this->warn2('Skipped: '.$e->getMessage());
            }

            // ---------------------------------------------------------------
            $this->section('3. Schema instead of few-shot examples');

            $withExamples = $this->classifier->systemPrompt()."\n\n".$this->legacyFewShots();
            $withoutExamples = $this->classifier->systemPrompt();

            $a = $this->countTokens($model, $withExamples);
            $b = $this->countTokens($model, $withoutExamples);

            $this->table(
                ['prompt', 'tokens'],
                [
                    ['taxonomy + 3 few-shot examples (the old way)', (string) $a],
                    ['taxonomy only, output shape enforced by schema', (string) $b],
                ],
            );
            $this->ok(($a - $b).' tokens saved per classification, and the schema is a harder guarantee than examples.');

            // ---------------------------------------------------------------
            $this->section('4. Thin tool results');

            $fat = json_encode(json_decode((string) file_get_contents(storage_path('app/fixtures/customers.json')), true)['dana@northwind.example']);
            $thin = $this->tools->withoutLatency()->execute('lookup_customer', ['email' => 'dana@northwind.example']);

            $fatTokens = $this->countTokens($model, (string) $fat);
            $thinTokens = $this->countTokens($model, $thin);

            $this->table(
                ['tool result', 'tokens'],
                [
                    ['whole fixture record', (string) $fatTokens],
                    ['projected to what routing consumes', (string) $thinTokens],
                ],
            );
            $this->line('  <fg=gray>Tool results are re-sent on every subsequent turn of the loop, so</>');
            $this->line('  <fg=gray>this saving multiplies by the number of turns, not by 1.</>');
            $this->ok(($fatTokens - $thinTokens).' tokens saved per turn per lookup.');

            // ---------------------------------------------------------------
            $this->section('5. Model tiering (the largest lever)');
            $this->line('  <fg=gray>Measured in step 3: the same classification on the cheap tier versus</>');
            $this->line('  <fg=gray>the investigate tier. Tokens are identical; the bill is not.</>');
            $this->line('  <fg=gray>Run `php artisan triage:step3` for the current numbers.</>');

            $this->hub->disconnectAll();
        });
    }

    private function countTokens(string $model, string $text): int
    {
        $response = $this->triage->client()->messages->countTokens(
            messages: [['role' => 'user', 'content' => $text]],
            model: $model,
        );

        return $response->inputTokens;
    }

    /**
     * Count the prompt cost of the GitHub toolset, scoped and unscoped.
     *
     * countTokens accepts the same tools/mcpServers parameters as create(),
     * so this measures the real schema weight without burning a completion.
     *
     * @return array{count: int, tokens: int}
     */
    private function measureGithubToolset(bool $scoped): array
    {
        $params = $this->hub->githubConnectorParams();

        if (! $scoped) {
            // Drop the allowlist: every tool the server offers gets loaded.
            $params['tools'] = [['type' => 'mcp_toolset', 'mcp_server_name' => 'github']];
        }

        $response = $this->triage->client()->beta->messages->countTokens(
            messages: [['role' => 'user', 'content' => 'File an issue for this ticket.']],
            model: $this->triage->model('investigate'),
            mcpServers: $params['mcpServers'],
            tools: $params['tools'],
            betas: $params['betas'],
        );

        $count = $scoped ? count((array) config('triage.github.allowed_tools')) : 0;

        return ['count' => $count, 'tokens' => $response->inputTokens];
    }

    /** The few-shot block the schema replaced, kept only to price it. */
    private function legacyFewShots(): string
    {
        return <<<'TXT'
        Respond with JSON only. No preamble, no markdown fence.

        Example 1
        Ticket: "Our whole team is locked out and we cannot process orders."
        {"urgency":"P1","topic":"auth","plan_tier":"business","sentiment":"frustrated",
         "summary":"Entire team locked out, order processing halted.","confidence":0.92,
         "needs_human":true,"customer_email":null,"order_id":null}

        Example 2
        Ticket: "How do I change my invoice email address?"
        {"urgency":"P4","topic":"how_to","plan_tier":"free","sentiment":"neutral",
         "summary":"Wants to change the invoice email address.","confidence":0.95,
         "needs_human":false,"customer_email":null,"order_id":null}

        Example 3
        Ticket: "Charged twice for invoice inv_9001, please refund one."
        {"urgency":"P2","topic":"billing","plan_tier":"pro","sentiment":"frustrated",
         "summary":"Double charge on inv_9001, refund requested.","confidence":0.9,
         "needs_human":false,"customer_email":null,"order_id":"inv_9001"}
        TXT;
    }
}
