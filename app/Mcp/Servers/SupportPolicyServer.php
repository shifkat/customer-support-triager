<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetRoutingPolicyTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Custom MCP server for assignment step 11.
 *
 * Exposes the support organisation's routing policy as a tool so the triager
 * cannot invent SLAs or route tickets to the wrong team. Registered in
 * routes/ai.php over both transports:
 *
 *   Mcp::local(...) -- stdio, and how the triager itself consumes it. The
 *   policy engine is internal, so stdio gives it no open port, no auth
 *   surface, and a lifetime scoped to the CLI run.
 *
 *   Mcp::web(...) -- Streamable HTTP, so the same server is reachable by
 *   remote MCP clients or Anthropic's hosted connector without needing a
 *   second implementation.
 */
#[Name('Support Policy Server')]
#[Version('1.0.0')]
#[Instructions(
    'Authoritative source for support routing policy. Given a triaged '.
    "ticket's urgency, topic and customer plan tier, it returns the owning ".
    'team, the Slack channel to notify, the first-response SLA, and whether '.
    'a GitHub issue or human review is required. Treat its answer as binding: '.
    'do not substitute your own judgement about routing or SLAs.'
)]
final class SupportPolicyServer extends Server
{
    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        GetRoutingPolicyTool::class,
    ];
}
