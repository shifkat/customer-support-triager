<?php

declare(strict_types=1);

namespace App\Services\Triage\Data;

/**
 * The routing engine's answer: who owns this ticket, where it gets announced,
 * how fast it must be answered, and which side effects are authorised.
 *
 * Immutable -- once the policy has ruled, downstream steps read it, they do
 * not renegotiate it.
 */
final readonly class RoutingDecision implements \JsonSerializable
{
    public function __construct(
        public string $team,
        public string $slack_channel,
        public int $sla_first_response_mins,
        public bool $requires_github_issue,
        public bool $escalate_to_human,
        public string $policy_version,
        /** @var list<string> */
        public array $rationale = [],
    ) {}

    /**
     * @return array{
     *     team: string,
     *     slack_channel: string,
     *     sla_first_response_mins: int,
     *     requires_github_issue: bool,
     *     escalate_to_human: bool,
     *     policy_version: string,
     *     rationale: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'team' => $this->team,
            'slack_channel' => $this->slack_channel,
            'sla_first_response_mins' => $this->sla_first_response_mins,
            'requires_github_issue' => $this->requires_github_issue,
            'escalate_to_human' => $this->escalate_to_human,
            'policy_version' => $this->policy_version,
            'rationale' => $this->rationale,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Human-readable SLA, e.g. "15 minutes" or "8 hours". */
    public function slaForHumans(): string
    {
        if ($this->sla_first_response_mins < 60) {
            return "{$this->sla_first_response_mins} minutes";
        }

        $hours = $this->sla_first_response_mins / 60;

        return rtrim(rtrim(number_format($hours, 1), '0'), '.').' hours';
    }
}
