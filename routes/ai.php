<?php

declare(strict_types=1);

use App\Mcp\Servers\SlackStubServer;
use App\Mcp\Servers\SupportPolicyServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP servers
|--------------------------------------------------------------------------
|
| The transport the triager uses is a deliberate choice, not a default:
|
|   local (stdio)  How the triager consumes both servers. They are internal,
|                  so stdio gives them no open port, no auth surface, and a
|                  process lifetime scoped to the CLI run. Nothing has to be
|                  running beforehand for a demo to work.
|
|   web (HTTP)     Streamable HTTP, so the same server is reachable by remote
|                  MCP clients or Anthropic's hosted connector -- which is
|                  exactly how the GitHub MCP server is consumed in step 9 --
|                  without maintaining a second implementation.
|
| Inspect either with:  php artisan mcp:inspector support-policy
|
*/

Mcp::local('support-policy', SupportPolicyServer::class);
Mcp::web('/mcp/support-policy', SupportPolicyServer::class)->middleware(['throttle:60,1']);

Mcp::local('slack-stub', SlackStubServer::class);
