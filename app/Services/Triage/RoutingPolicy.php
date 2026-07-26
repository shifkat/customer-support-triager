<?php

declare(strict_types=1);

namespace App\Services\Triage;

use App\Services\Triage\Data\RoutingDecision;

/**
 * The routing engine behind the custom MCP server (assignment step 11).
 *
 * Deterministic and model-free by design: given the same three inputs it
 * always returns the same decision, so it can be unit-tested without an API
 * key and audited without reading a transcript. The model decides *what the
 * ticket is*; this class decides *what happens next*.
 */
final class RoutingPolicy
{
    /** @param array<string, mixed> $config The `triage` config array. */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('triage'));
    }

    /**
     * Resolve a routing decision.
     *
     * Applied in order, each layer able to override the last:
     *   1. topic     -> owning team and its channel
     *   2. urgency   -> base SLA, and a war-room channel for P1/P2
     *   3. plan tier -> SLA multiplier, and forced human review
     */
    public function resolve(string $urgency, string $topic, string $planTier): RoutingDecision
    {
        $urgency = $this->normalise($urgency, array_keys($this->config['urgency']), 'P3');
        $topic = $this->normalise($topic, array_keys($this->config['topics']), 'other');
        $planTier = $this->normalise($planTier, $this->config['plan_tiers'], 'free');

        $routing = $this->config['routing'];
        $rationale = [];

        // 1. Topic decides the owning team.
        $team = $this->config['topics'][$topic]['team'];
        $channel = $routing['team_channels'][$team] ?? $this->config['slack']['fallback_channel'];
        $rationale[] = "topic '{$topic}' is owned by the {$team} team";

        // 2. Urgency sets the SLA and can pull the announcement into a war room.
        $baseSla = (int) $this->config['urgency'][$urgency]['sla_first_response_mins'];
        if (isset($routing['urgency_channels'][$urgency])) {
            $channel = $routing['urgency_channels'][$urgency];
            $rationale[] = "{$urgency} overrides the team channel to {$channel}";
        }

        // 3. Plan tier tightens the clock.
        $multiplier = (float) ($routing['plan_sla_multiplier'][$planTier] ?? 1.0);
        $sla = max(5, (int) round($baseSla * $multiplier));
        if (1.0 !== $multiplier) {
            $rationale[] = sprintf(
                "plan '%s' scales the %d minute SLA by %.2f to %d minutes",
                $planTier, $baseSla, $multiplier, $sla
            );
        } else {
            $rationale[] = "plan '{$planTier}' keeps the standard {$sla} minute SLA";
        }

        // A GitHub issue is only worth filing for real defects -- support
        // questions and feature chatter would just be noise in the tracker.
        $requiresIssue = in_array($topic, $routing['github_issue_topics'], true)
            && in_array($urgency, $routing['github_issue_urgencies'], true);
        $rationale[] = $requiresIssue
            ? "topic '{$topic}' at {$urgency} is a tracked defect, so an issue is required"
            : "topic '{$topic}' at {$urgency} does not warrant a GitHub issue";

        $humanReasons = [];
        if (in_array($urgency, $routing['always_human_urgencies'], true)) {
            $humanReasons[] = "urgency {$urgency}";
        }
        if (in_array($topic, $routing['always_human_topics'], true)) {
            $humanReasons[] = "topic {$topic}";
        }
        if (in_array($planTier, $routing['always_human_plans'], true)) {
            $humanReasons[] = "plan {$planTier}";
        }

        if ($humanReasons !== []) {
            $rationale[] = 'human review required due to '.implode(' and ', $humanReasons);
        }

        return new RoutingDecision(
            team: $team,
            slack_channel: $channel,
            sla_first_response_mins: $sla,
            requires_github_issue: $requiresIssue,
            escalate_to_human: $humanReasons !== [],
            policy_version: (string) $routing['policy_version'],
            rationale: $rationale,
        );
    }

    /**
     * Accept a value only if the taxonomy knows it.
     *
     * MCP tool arguments arrive from a model, so an unknown enum value is a
     * real possibility. Falling back to the safest bucket beats throwing and
     * stalling the pipeline mid-ticket.
     *
     * @param  list<string>  $allowed
     */
    private function normalise(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));

        foreach ($allowed as $candidate) {
            if (strtolower((string) $candidate) === $value) {
                return (string) $candidate;
            }
        }

        return $fallback;
    }
}
