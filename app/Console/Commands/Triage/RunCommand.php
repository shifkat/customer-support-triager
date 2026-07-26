<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\TriageClient;
use App\Services\Triage\TriageOrchestrator;

/**
 * Assignment step 12 -- the full end-to-end triage run.
 *
 * A ticket comes in, gets classified, tools and MCP servers are used, and it
 * is routed to Slack and GitHub. Every stage is narrated as it happens and
 * the run closes with a per-stage token and cost ledger.
 */
final class RunCommand extends TriageCommand
{
    protected $signature = 'triage:run
        {--ticket=p1-billing.md : ticket file in storage/app/fixtures/tickets}
        {--dry-run : skip the GitHub and Slack writes}';

    protected $description = 'Step 12: full end-to-end triage run';

    public function __construct(TriageClient $triage, private readonly TriageOrchestrator $orchestrator)
    {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        $status = $this->guard(function (): void {
            $path = storage_path('app/fixtures/tickets/'.$this->option('ticket'));
            if (! is_file($path)) {
                throw new \RuntimeException("No such ticket: {$path}");
            }
            $ticket = (string) file_get_contents($path);
            $dryRun = (bool) $this->option('dry-run');

            $this->banner('TRIAGE', 'End-to-end run'.($dryRun ? ' (dry run)' : ''));
            $this->kv('ticket', basename($path));
            $this->newLine();
            foreach (array_slice(explode("\n", trim($ticket)), 0, 3) as $line) {
                $this->line('  <fg=gray>│ '.$line.'</>');
            }
            $this->line('  <fg=gray>│ …</>');

            $emit = function (string $event, mixed $payload): void {
                match ($event) {
                    'stage' => $this->section((string) $payload),
                    'tools' => array_map(
                        fn (array $c) => $this->line('  <fg=magenta>[tool_use]</> '.$c['name'].'  '.json_encode($c['input'], JSON_UNESCAPED_SLASHES)),
                        $payload,
                    ),
                    'batch' => $this->kv(
                        'batch timing',
                        sprintf('%d call(s)  sequential %s ms  parallel %s ms',
                            count($payload['calls']),
                            number_format($payload['sequential_ms'], 0),
                            number_format($payload['parallel_ms'], 0)),
                    ),
                    'mcp_call' => $this->line('  <fg=magenta>[mcp]</> '.$payload['server'].' → '.$payload['tool']),
                    'text' => $this->line('  '.wordwrap((string) $payload, 96, "\n  ")),
                    'classified' => null,
                    'routed' => null,
                    default => null,
                };
            };

            // ---- 1. classify ------------------------------------------------
            $result = $this->orchestrator->classify($ticket, $emit);

            $this->kv('urgency', $result->urgency);
            $this->kv('topic', $result->topic);
            $this->kv('plan tier', $result->plan_tier);
            $this->kv('sentiment', $result->sentiment);
            $this->kv('confidence', number_format($result->confidence, 2));
            $this->kv('summary', $result->summary);

            // ---- 2. escalation ----------------------------------------------
            $this->section('Escalation decision');
            $escalate = $this->orchestrator->shouldEscalate($result);

            if ($escalate) {
                foreach ($this->orchestrator->escalationReasons($result) as $reason) {
                    $this->line('  · '.$reason);
                }
                $this->warn2('escalating to '.$this->triage->model('investigate'));
            } else {
                $this->ok('no escalation — the cheap tier is sufficient for this ticket');
            }

            // ---- 3. context --------------------------------------------------
            $context = '';
            if ($escalate) {
                $gathered = $this->orchestrator->gatherContext($ticket, $result, $emit);
                $context = $gathered['summary'];
                $this->newLine();
                $this->line('  '.wordwrap($context, 96, "\n  "));
            }

            // ---- 4. routing policy -------------------------------------------
            $decision = $this->orchestrator->route($result, $emit);

            $this->kv('team', $decision->team);
            $this->kv('channel', $decision->slack_channel);
            $this->kv('SLA', $decision->slaForHumans());
            $this->kv('needs GitHub issue', $decision->requires_github_issue ? 'yes' : 'no');
            $this->kv('needs human', $decision->escalate_to_human ? 'yes' : 'no');
            $this->kv('policy version', $decision->policy_version);

            // ---- 5. GitHub ----------------------------------------------------
            $issueUrl = null;
            if ($decision->requires_github_issue && ! $dryRun) {
                $issueUrl = $this->orchestrator->fileIssue($ticket, $result, $decision, $emit);
                $this->newLine();
                $this->kv('issue', $issueUrl ?? '(none created)');
            } elseif ($decision->requires_github_issue) {
                $this->section('GitHub issue');
                $this->warn2('skipped — dry run');
            }

            // ---- 6. Slack ------------------------------------------------------
            if (! $dryRun) {
                $this->orchestrator->announce($result, $decision, $context, $issueUrl, $emit);
            } else {
                $this->section('Slack announcement');
                $this->warn2('skipped — dry run');
            }

            $this->orchestrator->cleanup();

            // ---- ledger ---------------------------------------------------------
            $this->section('Run ledger');

            $rows = [];
            $totalIn = $totalOut = 0;
            foreach ($this->orchestrator->ledger() as $entry) {
                $totalIn += $entry['input'] + $entry['cache_read'] + $entry['cache_write'];
                $totalOut += $entry['output'];
                $rows[] = [
                    $entry['stage'],
                    $entry['model'] ?? '—',
                    (string) ($entry['input'] + $entry['cache_read'] + $entry['cache_write']),
                    (string) $entry['output'],
                    null === $entry['model'] ? '—' : '$'.number_format(
                        $this->triage->cost($entry['model'], $entry['input'], $entry['output'], $entry['cache_read'], $entry['cache_write']), 6),
                    $entry['note'],
                ];
            }

            $this->table(['stage', 'model', 'in', 'out', 'cost', 'note'], $rows);

            $this->kv('total prompt tokens', number_format($totalIn));
            $this->kv('total output tokens', number_format($totalOut));
            $this->kv('total cost', '$'.number_format($this->orchestrator->totalCost(), 6));

            $this->newLine();
            $this->ok('End-to-end triage complete.');
        });

        return $status;
    }
}
