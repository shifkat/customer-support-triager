<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Triage\RoutingPolicy;
use Tests\TestCase;

/**
 * The routing engine is deterministic and model-free, which is the whole
 * reason it exists -- so it can be tested without an API key and audited
 * without reading a transcript.
 */
final class RoutingPolicyTest extends TestCase
{
    private function policy(): RoutingPolicy
    {
        return $this->app->make(RoutingPolicy::class);
    }

    public function test_topic_selects_the_owning_team(): void
    {
        $this->assertSame('payments', $this->policy()->resolve('P3', 'billing', 'pro')->team);
        $this->assertSame('identity', $this->policy()->resolve('P3', 'auth', 'pro')->team);
        $this->assertSame('integrations', $this->policy()->resolve('P3', 'integration', 'pro')->team);
        $this->assertSame('docs', $this->policy()->resolve('P3', 'how_to', 'pro')->team);
    }

    public function test_p1_and_p2_override_the_team_channel(): void
    {
        $this->assertSame('#support-war-room', $this->policy()->resolve('P1', 'billing', 'pro')->slack_channel);
        $this->assertSame('#support-escalations', $this->policy()->resolve('P2', 'billing', 'pro')->slack_channel);

        // P3 has no override, so it stays with the owning team.
        $this->assertSame('#team-payments', $this->policy()->resolve('P3', 'billing', 'pro')->slack_channel);
    }

    public function test_plan_tier_scales_the_sla(): void
    {
        // P2 base SLA is 60 minutes.
        $this->assertSame(15, $this->policy()->resolve('P2', 'billing', 'enterprise')->sla_first_response_mins);
        $this->assertSame(30, $this->policy()->resolve('P2', 'billing', 'business')->sla_first_response_mins);
        $this->assertSame(60, $this->policy()->resolve('P2', 'billing', 'pro')->sla_first_response_mins);
        $this->assertSame(120, $this->policy()->resolve('P2', 'billing', 'free')->sla_first_response_mins);
    }

    public function test_sla_never_collapses_below_the_floor(): void
    {
        // P1 enterprise is 15 * 0.25 = 3.75, which must clamp to the 5 minute floor.
        $this->assertSame(5, $this->policy()->resolve('P1', 'data_loss', 'enterprise')->sla_first_response_mins);
    }

    public function test_github_issues_are_only_required_for_real_defects(): void
    {
        $this->assertTrue($this->policy()->resolve('P2', 'integration', 'pro')->requires_github_issue);
        $this->assertTrue($this->policy()->resolve('P1', 'data_loss', 'pro')->requires_github_issue);

        // Support questions and feature chatter would just be tracker noise.
        $this->assertFalse($this->policy()->resolve('P4', 'how_to', 'pro')->requires_github_issue);
        $this->assertFalse($this->policy()->resolve('P3', 'feature_request', 'pro')->requires_github_issue);

        // Billing is handled by humans, not by filing an engineering defect.
        $this->assertFalse($this->policy()->resolve('P2', 'billing', 'pro')->requires_github_issue);
    }

    public function test_human_review_is_forced_by_urgency_topic_or_plan(): void
    {
        $this->assertTrue($this->policy()->resolve('P1', 'how_to', 'free')->escalate_to_human, 'P1 always needs a human');
        $this->assertTrue($this->policy()->resolve('P4', 'data_loss', 'free')->escalate_to_human, 'data loss always needs a human');
        $this->assertTrue($this->policy()->resolve('P4', 'how_to', 'enterprise')->escalate_to_human, 'enterprise always needs a human');

        $this->assertFalse($this->policy()->resolve('P4', 'how_to', 'free')->escalate_to_human);
    }

    public function test_unknown_enum_values_fall_back_to_the_safest_bucket(): void
    {
        // Tool arguments arrive from a model, so an unknown value is a real
        // possibility. Falling back beats throwing and stalling the pipeline.
        $decision = $this->policy()->resolve('P9', 'nonsense', 'platinum');

        $this->assertSame('platform', $decision->team, 'unknown topic falls back to other -> platform');
        $this->assertSame('#team-platform', $decision->slack_channel, 'no urgency override, so the team channel stands');

        // Unknown urgency falls back to P3 (480 min base) and unknown plan to
        // free (2.0 multiplier), so the safest bucket is the *slowest* one.
        $this->assertSame(960, $decision->sla_first_response_mins);
    }

    public function test_case_is_normalised(): void
    {
        $lower = $this->policy()->resolve('p1', 'DATA_LOSS', 'Enterprise');
        $exact = $this->policy()->resolve('P1', 'data_loss', 'enterprise');

        $this->assertEquals($exact->toArray(), $lower->toArray());
    }

    public function test_rationale_explains_every_layer(): void
    {
        $decision = $this->policy()->resolve('P1', 'data_loss', 'enterprise');

        $this->assertNotEmpty($decision->rationale);
        $this->assertStringContainsString('platform team', $decision->rationale[0]);
        $this->assertStringContainsString('war-room', implode(' ', $decision->rationale));
        $this->assertStringContainsString('human review required', implode(' ', $decision->rationale));
    }

    public function test_sla_for_humans_formats_sensibly(): void
    {
        $this->assertSame('5 minutes', $this->policy()->resolve('P1', 'data_loss', 'enterprise')->slaForHumans());
        $this->assertSame('8 hours', $this->policy()->resolve('P3', 'performance', 'pro')->slaForHumans());
    }
}
