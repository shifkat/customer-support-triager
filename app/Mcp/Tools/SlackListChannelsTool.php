<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Stand-in for the reference Slack MCP server's `slack_list_channels`.
 *
 * Returns the channels the routing policy can name, so the agent can resolve
 * a channel name like "#support-war-room" to an id before posting -- the same
 * two-step dance the live server requires.
 */
#[Name('slack_list_channels')]
#[Description('List public channels in the workspace with pagination')]
final class SlackListChannelsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $limit = (int) ($request->get('limit') ?? 100);

        $channels = [];

        // Every channel the routing table can point at, plus the fallback.
        $names = array_values(array_unique(array_merge(
            array_values((array) config('triage.routing.team_channels', [])),
            array_values((array) config('triage.routing.urgency_channels', [])),
            [(string) config('triage.slack.fallback_channel')],
        )));

        foreach ($names as $i => $name) {
            $bare = ltrim((string) $name, '#');
            $channels[] = [
                'id' => 'C'.strtoupper(substr(md5($bare), 0, 9)),
                'name' => $bare,
                'is_channel' => true,
                'num_members' => 8 + $i,
            ];
        }

        return Response::structured([
            'ok' => true,
            'stub' => true,
            'channels' => array_slice($channels, 0, max(1, $limit)),
            'response_metadata' => ['next_cursor' => ''],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of channels to return (default 100, max 200)')
                ->default(100),

            'cursor' => $schema->string()
                ->description('Pagination cursor for next page of results'),
        ];
    }
}
