<?php

declare(strict_types=1);

namespace App\Services\Triage\Data;

/**
 * A classification plus what it cost, so every step can report real numbers
 * rather than claiming a saving it never measured.
 */
final readonly class ClassificationOutcome
{
    public function __construct(
        public TriageResult $result,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public float $elapsedMs,
        public string $stopReason,
    ) {}

    /** Total prompt size: uncached + cache reads + cache writes. */
    public function promptTokens(): int
    {
        return $this->inputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }
}
