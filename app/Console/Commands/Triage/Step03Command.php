<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\Classifier;
use App\Services\Triage\TriageClient;

/**
 * Assignment step 3 -- model selection per task.
 *
 * Classifies the same ticket on the cheap tier and the expensive tier and
 * prints both bills, so the tiering argument rests on measured numbers rather
 * than on the pricing page.
 */
final class Step03Command extends TriageCommand
{
    protected $signature = 'triage:step3 {--ticket=p2-integration.md}';

    protected $description = 'Step 3: model tiers, escalation rule, and the cost difference';

    public function __construct(TriageClient $triage, private readonly Classifier $classifier)
    {
        parent::__construct($triage);
    }

    public function handle(): int
    {
        return $this->guard(function (): void {
            $this->banner('STEP 3', 'Model selection per task');

            $this->section('Configured tiers');
            $rows = [];
            foreach (['classify', 'investigate', 'reply'] as $tier) {
                $model = $this->triage->model($tier);
                $price = config("triage.pricing.{$model}");
                $rows[] = [
                    $tier,
                    $model,
                    '$'.number_format((float) $price['input'], 2).' / '.'$'.number_format((float) $price['output'], 2),
                    $this->triage->effort($tier) ?? '— (not supported)',
                ];
            }
            $this->table(['tier', 'model', 'in/out per MTok', 'effort'], $rows);

            $this->section('Escalation rule');
            $rule = config('triage.escalation');
            $this->line('  <fg=gray>Every ticket is classified on the cheap tier. The expensive tier is</>');
            $this->line('  <fg=gray>only woken when one of these holds:</>');
            $this->kv('urgency in', implode(', ', $rule['urgencies']));
            $this->kv('confidence below', (string) $rule['min_confidence']);
            $this->kv('or', 'the classifier set needs_human');

            $ticket = $this->ticket();

            $this->section('Same ticket, both tiers');

            $cheap = $this->classifier->classify($ticket);
            $cheapCost = $this->triage->cost($cheap->model, $cheap->inputTokens, $cheap->outputTokens, $cheap->cacheReadTokens, $cheap->cacheWriteTokens);

            // Deliberately run the classification prompt on the expensive tier
            // to price the alternative: "just use Opus for everything".
            $expensiveModel = $this->triage->model('investigate');
            $started = microtime(true);
            $expensive = $this->triage->client()->messages->create(
                maxTokens: 1024,
                messages: [['role' => 'user', 'content' => "<ticket>\n".trim($ticket)."\n</ticket>"]],
                model: $expensiveModel,
                system: $this->classifier->systemBlocks(true),
                outputConfig: ['format' => \App\Services\Triage\Data\TriageResult::class],
            );
            $expensiveMs = (microtime(true) - $started) * 1000;
            $expensiveCost = $this->triage->cost(
                $expensive->model,
                $expensive->usage->inputTokens,
                $expensive->usage->outputTokens,
                (int) ($expensive->usage->cacheReadInputTokens ?? 0),
                (int) ($expensive->usage->cacheCreationInputTokens ?? 0),
            );

            $expensiveResult = $expensive->parsedOutput();

            $this->table(
                ['', 'classify tier', 'investigate tier'],
                [
                    ['model', $cheap->model, $expensive->model],
                    ['urgency', $cheap->result->urgency, $expensiveResult->urgency ?? '?'],
                    ['topic', $cheap->result->topic, $expensiveResult->topic ?? '?'],
                    ['confidence', number_format($cheap->result->confidence, 2), number_format((float) ($expensiveResult->confidence ?? 0), 2)],
                    ['latency', number_format($cheap->elapsedMs, 0).' ms', number_format($expensiveMs, 0).' ms'],
                    ['cost', '$'.number_format($cheapCost, 6), '$'.number_format($expensiveCost, 6)],
                ],
            );

            if ($expensiveCost > 0) {
                $factor = $cheapCost > 0 ? $expensiveCost / $cheapCost : 0;
                $this->kv('cost ratio', number_format($factor, 1).'× more expensive on the investigate tier');
                $this->kv('per 10k tickets', '$'.number_format($cheapCost * 10000, 2).' vs $'.number_format($expensiveCost * 10000, 2));
            }

            $this->section('Escalation decision for this ticket');
            $reasons = $cheap->result->escalationReasons($rule);
            if ($cheap->result->shouldEscalate($rule)) {
                $this->warn2('escalates to '.$this->triage->model('investigate'));
                foreach ($reasons as $reason) {
                    $this->line('    · '.$reason);
                }
            } else {
                $this->ok('handled entirely on the cheap tier — no escalation triggered');
            }

            $this->newLine();
            $this->line('  <fg=gray>Both tiers agree on this ticket, which is the argument for tiering:</>');
            $this->line('  <fg=gray>the expensive model earns its cost on multi-step tool and MCP work,</>');
            $this->line('  <fg=gray>not on a schema-constrained classification.</>');
        });
    }

    private function ticket(): string
    {
        $path = storage_path('app/fixtures/tickets/'.$this->option('ticket'));

        if (! is_file($path)) {
            throw new \RuntimeException("No such ticket: {$path}");
        }

        return (string) file_get_contents($path);
    }
}
