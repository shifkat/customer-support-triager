<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Triage configuration
|--------------------------------------------------------------------------
|
| Single source of truth for the triage taxonomy, model tiers and routing
| table. docs/TRIAGE_POLICY.md is the prose version of this file; the
| classifier prompt and the routing engine both read from here, so the
| documented policy and the enforced policy cannot drift apart.
|
| Everything is read through config() rather than env() at runtime. Calling
| env() outside a config file breaks the moment `php artisan config:cache`
| runs in production -- env() returns null and every request silently fails.
|
*/

return [

    'api_key' => env('ANTHROPIC_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Model tiers (assignment step 3)
    |--------------------------------------------------------------------------
    |
    | Three tiers, chosen per task rather than one model everywhere. The
    | justification lives in docs/SUBMISSION.md; the numbers below are what
    | the code actually uses.
    |
    */

    'models' => [
        // Runs on every inbound ticket. Highest volume, narrowest task.
        'classify' => env('TRIAGE_MODEL_CLASSIFY', 'claude-haiku-4-5'),

        // Runs only on escalation. Multi-step tool + MCP orchestration with
        // irreversible side effects (files issues, posts to Slack).
        'investigate' => env('TRIAGE_MODEL_INVESTIGATE', 'claude-opus-5'),

        // Drafts the customer-facing reply. Tone-sensitive, not agentic.
        'reply' => env('TRIAGE_MODEL_REPLY', 'claude-sonnet-5'),
    ],

    /*
    | USD per million tokens, used to report real spend after a run.
    | Sonnet 5 is at its introductory rate through 2026-08-31.
    */
    'pricing' => [
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
    ],

    /*
    | Effort is only sent to models that accept it. Haiku 4.5 rejects the
    | `effort` parameter outright, so the classify tier must omit it.
    */
    'effort' => [
        'investigate' => env('TRIAGE_EFFORT_INVESTIGATE', 'high'),
        'reply' => env('TRIAGE_EFFORT_REPLY', 'medium'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation rule
    |--------------------------------------------------------------------------
    |
    | Haiku classifies everything. Opus is only woken up when one of these
    | holds -- this is the whole point of tiering, so it is a config value
    | rather than a magic number buried in a service.
    |
    */

    'escalation' => [
        'urgencies' => ['P1', 'P2'],
        'min_confidence' => 0.75,
    ],

    /*
    |--------------------------------------------------------------------------
    | Urgency taxonomy (assignment step 2)
    |--------------------------------------------------------------------------
    |
    | `triggers` are fed verbatim into the classifier prompt. Keeping them
    | here means tightening a trigger updates the prompt automatically.
    |
    */

    'urgency' => [
        'P1' => [
            'label' => 'Critical',
            'sla_first_response_mins' => 15,
            'triggers' => [
                'complete outage or the product is unusable',
                'confirmed or suspected data loss or corruption',
                'security incident, credential leak, or unauthorised access',
                'payment processing is failing for all customers',
            ],
        ],
        'P2' => [
            'label' => 'High',
            'sla_first_response_mins' => 60,
            'triggers' => [
                'a core workflow is broken with no workaround',
                'billing error affecting a single customer, such as a double charge',
                'authentication failures blocking a customer from signing in',
                'a paying customer is explicitly threatening to churn',
            ],
        ],
        'P3' => [
            'label' => 'Normal',
            'sla_first_response_mins' => 480,
            'triggers' => [
                'a bug with a documented or obvious workaround',
                'degraded performance that is annoying but not blocking',
                'a configuration problem specific to one account',
            ],
        ],
        'P4' => [
            'label' => 'Low',
            'sla_first_response_mins' => 1440,
            'triggers' => [
                'how-to question answerable from the documentation',
                'feature request or product feedback',
                'general enquiry with no reported failure',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Topic taxonomy -> owning team
    |--------------------------------------------------------------------------
    */

    'topics' => [
        'billing' => ['team' => 'payments', 'description' => 'invoices, charges, refunds, subscriptions, plan changes'],
        'auth' => ['team' => 'identity', 'description' => 'login, SSO, MFA, sessions, API keys, permissions'],
        'data_loss' => ['team' => 'platform', 'description' => 'missing, deleted, or corrupted customer data'],
        'integration' => ['team' => 'integrations', 'description' => 'webhooks, third-party connectors, public API errors'],
        'performance' => ['team' => 'platform', 'description' => 'slowness, timeouts, rate limits, degraded throughput'],
        'how_to' => ['team' => 'docs', 'description' => 'usage questions answerable from documentation'],
        'feature_request' => ['team' => 'docs', 'description' => 'requests for functionality that does not exist yet'],
        'other' => ['team' => 'platform', 'description' => 'anything that does not fit the categories above'],
    ],

    'plan_tiers' => ['free', 'pro', 'business', 'enterprise'],

    /*
    |--------------------------------------------------------------------------
    | Routing table (backs the custom MCP server, step 11)
    |--------------------------------------------------------------------------
    |
    | Resolution order, applied by App\Services\Triage\RoutingPolicy:
    |   1. team + channel from the topic
    |   2. urgency overrides the channel and sets the SLA
    |   3. plan tier tightens the SLA and can force human escalation
    |
    | Expressed as data rather than nested ifs so the policy is inspectable
    | and unit-testable without a model in the loop.
    |
    */

    'routing' => [
        'team_channels' => [
            'payments' => '#team-payments',
            'identity' => '#team-identity',
            'platform' => '#team-platform',
            'integrations' => '#team-integrations',
            'docs' => '#team-docs',
        ],

        // Urgency wins over the team channel: P1/P2 go to a shared war room
        // so nothing critical sits unseen in a quiet team channel.
        'urgency_channels' => [
            'P1' => '#support-war-room',
            'P2' => '#support-escalations',
        ],

        // Enterprise and business customers get a tighter first response.
        'plan_sla_multiplier' => [
            'enterprise' => 0.25,
            'business' => 0.5,
            'pro' => 1.0,
            'free' => 2.0,
        ],

        // A GitHub issue is only worth filing for real defects. Support
        // questions and feature chatter would just create noise.
        'github_issue_topics' => ['data_loss', 'integration', 'performance', 'auth'],
        'github_issue_urgencies' => ['P1', 'P2', 'P3'],

        // Always put a human in the loop for these, regardless of confidence.
        'always_human_urgencies' => ['P1'],
        'always_human_topics' => ['data_loss'],
        'always_human_plans' => ['enterprise'],

        'policy_version' => '2026.07.1',
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub MCP (step 9) -- hosted connector, no local MCP client needed
    |--------------------------------------------------------------------------
    |
    | The server is already public HTTPS, so Anthropic connects to it directly
    | via the `mcp_servers` request parameter. Only the three issue tools are
    | allowlisted: the server exposes ~90 tools and loading all of them would
    | dominate the prompt (see the token notes in docs/SUBMISSION.md).
    |
    */

    'github' => [
        'pat' => env('GITHUB_PAT'),
        'repo' => env('GITHUB_REPO'),
        'mcp_url' => env('GITHUB_MCP_URL', 'https://api.githubcopilot.com/mcp/'),
        'allowed_tools' => ['create_issue', 'search_issues', 'get_issue'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Slack MCP (step 10) -- client-side over stdio
    |--------------------------------------------------------------------------
    |
    | driver=live spawns the reference Slack MCP server over npx.
    | driver=stub spawns our own SlackStubServer with identical tool
    | signatures, so the pipeline is demonstrable before a Slack app exists.
    | Switching between them is a .env change and nothing else.
    |
    */

    'slack' => [
        'driver' => env('TRIAGE_SLACK_DRIVER', 'stub'),
        'bot_token' => env('SLACK_BOT_TOKEN'),
        'team_id' => env('SLACK_TEAM_ID'),
        'fallback_channel' => env('SLACK_FALLBACK_CHANNEL', '#support-triage'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom MCP server (step 11) -- stdio
    |--------------------------------------------------------------------------
    |
    | Consumed over stdio: the policy engine is internal, so it gets no open
    | port and no auth surface, and its lifetime is scoped to the CLI run.
    | routes/ai.php also registers it over HTTP for remote clients.
    |
    */

    'policy_server' => [
        'name' => 'support-policy',
        'php_binary' => env('TRIAGE_PHP_BINARY', PHP_BINARY),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web search server tool (step 8)
    |--------------------------------------------------------------------------
    |
    | Domain-scoped so the agent quotes our documentation rather than a
    | random blog, and capped so a single ticket cannot run away with the
    | search budget.
    |
    */

    'web_search' => [
        'max_uses' => (int) env('TRIAGE_SEARCH_MAX_USES', 3),
        'allowed_domains' => array_values(array_filter(
            explode(',', (string) env('TRIAGE_SEARCH_DOMAINS', 'docs.stripe.com,status.stripe.com'))
        )),
    ],

];
