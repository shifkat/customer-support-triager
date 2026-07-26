<?php

declare(strict_types=1);

namespace App\Services\Triage;

use Anthropic\Client;

/**
 * Builds the Anthropic client and answers cost/model questions about it.
 *
 * Everything reads through config('triage.*'), never env() directly, so
 * `php artisan config:cache` stays safe.
 */
final class TriageClient
{
    private ?Client $client = null;

    public function client(): Client
    {
        if (null !== $this->client) {
            return $this->client;
        }

        $key = config('triage.api_key');

        if (! is_string($key) || '' === trim($key)) {
            throw new \RuntimeException(
                'ANTHROPIC_API_KEY is not set. Add it to .env, then re-run. '
                .'If you have cached config, run `php artisan config:clear` too.'
            );
        }

        return $this->client = new Client(apiKey: $key);
    }

    /** Model id for a tier: classify, investigate or reply. */
    public function model(string $tier): string
    {
        $model = config("triage.models.{$tier}");

        if (! is_string($model) || '' === $model) {
            throw new \InvalidArgumentException("No model configured for tier '{$tier}'.");
        }

        return $model;
    }

    /**
     * Effort for a tier, or null when the tier's model does not accept it.
     *
     * Haiku 4.5 rejects `effort` outright, so the classify tier must omit the
     * parameter rather than pass a default.
     */
    public function effort(string $tier): ?string
    {
        $effort = config("triage.effort.{$tier}");

        return is_string($effort) && '' !== $effort ? $effort : null;
    }

    /**
     * USD cost of a call, from the configured per-MTok rates.
     *
     * Cached reads are billed at roughly a tenth of the input rate and cache
     * writes at 1.25x, which is what makes the step 7 caching numbers show up
     * as a real saving rather than a rounding artefact.
     */
    public function cost(string $model, int $inputTokens, int $outputTokens, int $cacheReadTokens = 0, int $cacheWriteTokens = 0): float
    {
        $rates = config("triage.pricing.{$model}");

        if (! is_array($rates)) {
            return 0.0;
        }

        $in = (float) $rates['input'] / 1_000_000;
        $out = (float) $rates['output'] / 1_000_000;

        return ($inputTokens * $in)
            + ($outputTokens * $out)
            + ($cacheReadTokens * $in * 0.1)
            + ($cacheWriteTokens * $in * 1.25);
    }
}
