<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Stand-in for the reference Slack MCP server's `slack_post_message`.
 *
 * Name, arguments and result shape are copied verbatim from
 * @modelcontextprotocol/server-slack so that flipping
 * TRIAGE_SLACK_DRIVER=stub -> live swaps the transport target and changes
 * nothing else in the triager. This exists because a Slack workspace app is
 * a credential we do not have yet -- the MCP plumbing either side of it is
 * identical, and the stub says so in its own output rather than pretending
 * a message was delivered.
 */
#[Name('slack_post_message')]
#[Description('Post a new message to a Slack channel')]
final class SlackPostMessageTool extends Tool
{
    /** Where the stub records messages, so demos and tests can assert on them. */
    public const LOG_PATH = 'triage/slack-outbox.jsonl';

    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'channel_id' => 'required|string',
            'text' => 'required|string',
        ]);

        $record = [
            'ts' => sprintf('%.6f', microtime(true)),
            'channel' => $validated['channel_id'],
            'text' => $validated['text'],
            'delivered_by' => 'stub',
        ];

        Storage::disk('local')->append(
            self::LOG_PATH,
            (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        Log::info('slack stub post_message', $record);

        return Response::structured([
            'ok' => true,
            'channel' => $validated['channel_id'],
            'ts' => $record['ts'],
            'stub' => true,
            'note' => 'Recorded by SlackStubServer, not delivered. Set TRIAGE_SLACK_DRIVER=live with a bot token to post for real.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'channel_id' => $schema->string()
                ->description('The ID of the channel to post to')
                ->required(),

            'text' => $schema->string()
                ->description('The message text to post')
                ->required(),
        ];
    }
}
