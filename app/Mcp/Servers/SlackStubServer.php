<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\SlackListChannelsTool;
use App\Mcp\Tools\SlackPostMessageTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Local stand-in for @modelcontextprotocol/server-slack (assignment step 10).
 *
 * Exposes the same two tool names and argument shapes as the real server, so
 * the triager's MCP code path is exercised end to end before a Slack app
 * exists. TRIAGE_SLACK_DRIVER=live points the identical stdio client at the
 * real npx server instead; nothing else in the codebase changes.
 *
 * Messages are recorded to storage/app/private/triage/slack-outbox.jsonl, and
 * every response carries "stub": true so no reader mistakes it for delivery.
 */
#[Name('Slack Stub Server')]
#[Version('1.0.0')]
#[Instructions(
    'Stub Slack workspace used for local development. Tool names and '.
    'arguments match the reference Slack MCP server exactly. Messages are '.
    'recorded locally rather than delivered.'
)]
final class SlackStubServer extends Server
{
    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        SlackPostMessageTool::class,
        SlackListChannelsTool::class,
    ];
}
