<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\SlackStubServer;
use App\Mcp\Servers\SupportPolicyServer;
use App\Mcp\Tools\GetRoutingPolicyTool;
use App\Mcp\Tools\SlackListChannelsTool;
use App\Mcp\Tools\SlackPostMessageTool;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises the MCP servers through Laravel MCP's own test harness, so the
 * tools are invoked exactly as a real client would invoke them -- schema
 * validation included -- without spawning a process.
 */
final class McpServerTest extends TestCase
{
    public function test_routing_policy_tool_returns_a_full_decision(): void
    {
        $response = SupportPolicyServer::tool(GetRoutingPolicyTool::class, [
            'urgency' => 'P1',
            'topic' => 'data_loss',
            'plan_tier' => 'enterprise',
        ]);

        $response->assertOk();
        $response->assertSee('platform');
        $response->assertSee('#support-war-room');
    }

    public function test_routing_policy_tool_rejects_a_value_outside_the_taxonomy(): void
    {
        // Arguments arrive from a model, so validation is not optional.
        $response = SupportPolicyServer::tool(GetRoutingPolicyTool::class, [
            'urgency' => 'P9',
            'topic' => 'data_loss',
            'plan_tier' => 'enterprise',
        ]);

        $response->assertHasErrors();
    }

    public function test_routing_policy_tool_requires_every_argument(): void
    {
        $response = SupportPolicyServer::tool(GetRoutingPolicyTool::class, ['urgency' => 'P1']);

        $response->assertHasErrors();
    }

    public function test_slack_stub_records_the_message_and_admits_it_is_a_stub(): void
    {
        Storage::fake('local');

        $response = SlackStubServer::tool(SlackPostMessageTool::class, [
            'channel_id' => '#support-war-room',
            'text' => "P1 BILLING — renewal failing, 40 seats locked out\nowner payments, due in 5 minutes",
        ]);

        $response->assertOk();
        // The stub must never read as a real delivery.
        $response->assertSee('"stub":true');

        Storage::disk('local')->assertExists(SlackPostMessageTool::LOG_PATH);
        $this->assertStringContainsString(
            '#support-war-room',
            Storage::disk('local')->get(SlackPostMessageTool::LOG_PATH),
        );
    }

    public function test_slack_stub_lists_every_channel_the_policy_can_name(): void
    {
        $response = SlackStubServer::tool(SlackListChannelsTool::class, []);

        $response->assertOk();

        // Anything the routing table can select must be resolvable, or the
        // agent cannot turn a channel name into an id before posting.
        $response->assertSee('support-war-room');
        $response->assertSee('team-payments');
        $response->assertSee('team-docs');
    }

    public function test_slack_stub_tool_names_match_the_reference_server(): void
    {
        // If these drift, TRIAGE_SLACK_DRIVER=live silently stops working,
        // because the prompt and the tool runner both key off the tool name.
        $this->assertSame('slack_post_message', (new SlackPostMessageTool)->name());
        $this->assertSame('slack_list_channels', (new SlackListChannelsTool)->name());
    }

    public function test_policy_tool_name_is_stable(): void
    {
        $this->assertSame('get-routing-policy', (new GetRoutingPolicyTool)->name());
    }
}
