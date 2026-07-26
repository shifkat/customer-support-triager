<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Triage\RoutingPolicy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

/**
 * The workflow-specific tool for assignment step 11.
 *
 * The model is good at deciding what a ticket *is*. It should not be
 * improvising who owns it or how fast it must be answered -- those are
 * business policy, and policy belongs in a deterministic engine the support
 * org can audit and change without touching a prompt. This tool is the seam
 * between the two.
 *
 * The description is written prescriptively ("call this after ... never
 * guess") because current Opus-tier models are conservative about reaching
 * for tools; stating the trigger condition measurably raises the call rate.
 */
#[Name('get-routing-policy')]
#[Title('Get Support Routing Policy')]
#[Description(
    'Resolve the support routing policy for a triaged ticket. Call this after '.
    'classifying a ticket and before taking any action on it: it returns the '.
    'owning team, the Slack channel to notify, the first-response SLA in '.
    'minutes, whether a GitHub issue must be filed, and whether a human must '.
    'review. Never guess these values -- always call this tool.'
)]
final class GetRoutingPolicyTool extends Tool
{
    public function handle(Request $request, RoutingPolicy $policy): ResponseFactory
    {
        $validated = $request->validate([
            'urgency' => 'required|string|in:P1,P2,P3,P4',
            'topic' => 'required|string|in:billing,auth,data_loss,integration,performance,how_to,feature_request,other',
            'plan_tier' => 'required|string|in:free,pro,business,enterprise',
        ]);

        $decision = $policy->resolve(
            urgency: $validated['urgency'],
            topic: $validated['topic'],
            planTier: $validated['plan_tier'],
        );

        return Response::structured($decision->toArray());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'urgency' => $schema->string()
                ->enum(['P1', 'P2', 'P3', 'P4'])
                ->description('Urgency band from triage. P1 critical, P2 high, P3 normal, P4 low.')
                ->required(),

            'topic' => $schema->string()
                ->enum(['billing', 'auth', 'data_loss', 'integration', 'performance', 'how_to', 'feature_request', 'other'])
                ->description('Primary topic from triage.')
                ->required(),

            'plan_tier' => $schema->string()
                ->enum(['free', 'pro', 'business', 'enterprise'])
                ->description('The customer plan tier. Use "free" if unknown.')
                ->required(),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'team' => $schema->string()
                ->description('Engineering team that owns this ticket.')
                ->required(),

            'slack_channel' => $schema->string()
                ->description('Slack channel the triage card must be posted to.')
                ->required(),

            'sla_first_response_mins' => $schema->integer()
                ->description('Minutes allowed before a first response breaches SLA.')
                ->required(),

            'requires_github_issue' => $schema->boolean()
                ->description('Whether a GitHub issue must be filed for this ticket.')
                ->required(),

            'escalate_to_human' => $schema->boolean()
                ->description('Whether a human must review before the ticket is auto-answered.')
                ->required(),

            'policy_version' => $schema->string()
                ->description('Version of the routing policy that produced this decision.')
                ->required(),

            'rationale' => $schema->array()
                ->description('Ordered explanation of how each policy layer shaped the decision.')
                ->required(),
        ];
    }
}
