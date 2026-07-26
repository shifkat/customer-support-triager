<?php

declare(strict_types=1);

namespace App\Console\Commands\Triage;

use App\Services\Triage\TriageClient;
use Illuminate\Console\Command;

/**
 * Shared console chrome for the step demos.
 *
 * Every step command is meant to be run alone and screenshotted, so the
 * output has to explain itself without the reader knowing the codebase.
 */
abstract class TriageCommand extends Command
{
    public function __construct(protected readonly TriageClient $triage)
    {
        parent::__construct();
    }

    protected function banner(string $step, string $title): void
    {
        $this->newLine();
        $this->line("<bg=blue;fg=white> {$step} </> <options=bold>{$title}</>");
        $this->line(str_repeat('─', 72));
    }

    protected function section(string $title): void
    {
        $this->newLine();
        $this->line("<fg=cyan;options=bold>▸ {$title}</>");
    }

    protected function kv(string $key, string $value): void
    {
        $this->line(sprintf('  <fg=gray>%-26s</> %s', $key, $value));
    }

    protected function ok(string $message): void
    {
        $this->line("  <fg=green>✔</> {$message}");
    }

    protected function warn2(string $message): void
    {
        $this->line("  <fg=yellow>!</> {$message}");
    }

    /**
     * Report usage and cost for a call.
     *
     * @param  object{inputTokens: int, outputTokens: int, cacheReadInputTokens?: ?int, cacheCreationInputTokens?: ?int}  $usage
     */
    protected function reportUsage(string $model, object $usage): void
    {
        $cacheRead = (int) ($usage->cacheReadInputTokens ?? 0);
        $cacheWrite = (int) ($usage->cacheCreationInputTokens ?? 0);

        $this->kv('model', $model);
        $this->kv('input tokens', (string) $usage->inputTokens);
        $this->kv('output tokens', (string) $usage->outputTokens);

        if ($cacheRead > 0 || $cacheWrite > 0) {
            $this->kv('cache read tokens', (string) $cacheRead);
            $this->kv('cache write tokens', (string) $cacheWrite);
        }

        $cost = $this->triage->cost($model, $usage->inputTokens, $usage->outputTokens, $cacheRead, $cacheWrite);
        $this->kv('cost', '$'.number_format($cost, 6));
    }

    /** Run a step body, turning SDK errors into readable console output. */
    protected function guard(callable $body): int
    {
        try {
            $body();

            return self::SUCCESS;
        } catch (\Anthropic\Core\Exceptions\AuthenticationException) {
            $this->newLine();
            $this->error('401 from the Claude API — ANTHROPIC_API_KEY is missing or invalid.');

            return self::FAILURE;
        } catch (\Anthropic\Core\Exceptions\RateLimitException) {
            $this->newLine();
            $this->error('429 rate limited. Wait a moment and re-run.');

            return self::FAILURE;
        } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
            $this->newLine();
            $this->error('Claude API error: '.$e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());
            $this->line('<fg=gray>'.$e->getFile().':'.$e->getLine().'</>');

            return self::FAILURE;
        }
    }
}
