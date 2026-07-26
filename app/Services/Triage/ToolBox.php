<?php

declare(strict_types=1);

namespace App\Services\Triage;

use Anthropic\Lib\Tools\BetaRunnableTool;
use Illuminate\Support\Facades\Storage;

/**
 * The triager's client-side tools (assignment steps 4 and 5).
 *
 * Two design choices worth calling out, because they are what the assignment
 * is actually testing:
 *
 * 1. `lookup_customer` and `list_recent_orders` are deliberately independent
 *    -- neither needs the other's output -- so the model can and does emit
 *    both tool_use blocks in a single assistant turn. That is what makes
 *    parallel execution possible at all.
 *
 * 2. Every tool returns only the fields the routing policy consumes, not the
 *    whole fixture record. Tool results are re-sent on every subsequent turn
 *    of the loop, so a fat result is paid for repeatedly (see the token notes
 *    in docs/SUBMISSION.md).
 *
 * Tool descriptions state *when* to call, not just what the tool does:
 * current Opus-tier models reach for tools conservatively, and a prescriptive
 * trigger condition measurably raises the call rate.
 */
final class ToolBox
{
    /**
     * Simulated I/O latency per tool call.
     *
     * These fixtures read a local JSON file in microseconds, but the tools
     * they stand in for -- a CRM lookup and a billing API query -- are network
     * round trips in the 0.5-1.5s range. Step 5's timing comparison is only
     * meaningful against a realistic figure, so we sleep for one.
     */
    public const SIMULATED_LATENCY_MS = 900;

    /** @var list<array{name: string, input: array<string, mixed>, ms: float}> */
    private array $calls = [];

    private bool $simulateLatency = true;

    public function withoutLatency(): self
    {
        $this->simulateLatency = false;

        return $this;
    }

    /** @return list<array{name: string, input: array<string, mixed>, ms: float}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function resetCalls(): void
    {
        $this->calls = [];
    }

    /**
     * Tool definitions in Claude API shape.
     *
     * Kept separate from the runnable wrappers because step 5 needs the raw
     * definitions to drive a hand-written loop, while step 4 hands the
     * runnable versions to the SDK tool runner.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'lookup_customer',
                'description' =>
                    'Look up a customer account by email address. Call this whenever a ticket '.
                    'mentions an email address, before deciding urgency or routing -- plan tier '.
                    'and account health change the answer. Does not require any other tool first.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string', 'description' => 'The customer email address as written in the ticket.'],
                    ],
                    'required' => ['email'],
                ],
            ],
            [
                'name' => 'list_recent_orders',
                'description' =>
                    'List a customer\'s recent orders and invoices, newest first. Call this for any '.
                    'billing, payment, charge or refund ticket. Accepts an email address directly, '.
                    'so it does NOT need lookup_customer to run first -- call both at the same time.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string', 'description' => 'The customer email address as written in the ticket.'],
                        'limit' => ['type' => 'integer', 'description' => 'How many orders to return. Defaults to 3.'],
                    ],
                    'required' => ['email'],
                ],
            ],
            [
                'name' => 'create_ticket',
                'description' =>
                    'Create the internal support ticket record. Call this once, last, after you have '.
                    'the routing policy decision. Returns the ticket id to quote in Slack and GitHub.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'subject' => ['type' => 'string', 'description' => 'Short subject line.'],
                        'urgency' => ['type' => 'string', 'enum' => ['P1', 'P2', 'P3', 'P4']],
                        'team' => ['type' => 'string', 'description' => 'Owning team from the routing policy.'],
                        'body' => ['type' => 'string', 'description' => 'Triage summary and findings.'],
                    ],
                    'required' => ['subject', 'urgency', 'team', 'body'],
                ],
            ],
        ];
    }

    /**
     * The same tools wrapped for the SDK tool runner.
     *
     * @return list<BetaRunnableTool>
     */
    public function runnable(): array
    {
        return array_map(
            fn (array $definition): BetaRunnableTool => new BetaRunnableTool(
                definition: $definition,
                run: fn (array $input): string => $this->execute($definition['name'], $input),
            ),
            $this->definitions(),
        );
    }

    /**
     * Execute a tool by name and return a JSON string result.
     *
     * Errors are returned as data rather than thrown: the caller turns them
     * into a tool_result with is_error true, which lets the model recover.
     * Dropping a failed result instead would leave a dangling tool_use and a
     * 400 on the next turn.
     *
     * @param  array<string, mixed>  $input
     */
    public function execute(string $name, array $input): string
    {
        $started = microtime(true);

        if ($this->simulateLatency) {
            usleep(self::SIMULATED_LATENCY_MS * 1000);
        }

        $result = match ($name) {
            'lookup_customer' => $this->lookupCustomer((string) ($input['email'] ?? '')),
            'list_recent_orders' => $this->listRecentOrders((string) ($input['email'] ?? ''), (int) ($input['limit'] ?? 3)),
            'create_ticket' => $this->createTicket($input),
            default => ['error' => "Unknown tool '{$name}'."],
        };

        $this->calls[] = [
            'name' => $name,
            'input' => $input,
            'ms' => (microtime(true) - $started) * 1000,
        ];

        return (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function lookupCustomer(string $email): array
    {
        $customer = $this->customers()[strtolower(trim($email))] ?? null;

        if (null === $customer) {
            return ['found' => false, 'email' => $email, 'note' => 'No account matches this email. Treat as a free-tier prospect.'];
        }

        // Thin projection: plan tier and health drive routing; MRR and CSM
        // give the agent enough to justify an escalation. Everything else in
        // the record would just be re-sent on every turn for nothing.
        return [
            'found' => true,
            'customer_id' => $customer['customer_id'],
            'name' => $customer['name'],
            'plan_tier' => $customer['plan_tier'],
            'mrr_usd' => $customer['mrr_usd'],
            'account_age_days' => $customer['account_age_days'],
            'health_score' => $customer['health_score'],
            'csm' => $customer['csm'],
        ];
    }

    /** @return array<string, mixed> */
    private function listRecentOrders(string $email, int $limit): array
    {
        $customer = $this->customers()[strtolower(trim($email))] ?? null;

        if (null === $customer) {
            return ['found' => false, 'email' => $email, 'orders' => []];
        }

        $orders = $this->orders()[$customer['customer_id']] ?? [];

        $projected = array_map(
            static fn (array $o): array => [
                'order_id' => $o['order_id'],
                'created_at' => $o['created_at'],
                'amount_usd' => $o['amount_usd'],
                'status' => $o['status'],
                'failure_code' => $o['failure_code'],
            ],
            array_slice($orders, 0, max(1, $limit)),
        );

        return [
            'found' => true,
            'customer_id' => $customer['customer_id'],
            'orders' => $projected,
        ];
    }

    /** @param array<string, mixed> $input */
    private function createTicket(array $input): array
    {
        $id = 'TCK-'.strtoupper(substr(md5((string) json_encode($input).microtime(true)), 0, 8));

        Storage::disk('local')->append('triage/tickets.jsonl', (string) json_encode([
            'ticket_id' => $id,
            'created_at' => now()->toIso8601String(),
            'subject' => $input['subject'] ?? '',
            'urgency' => $input['urgency'] ?? '',
            'team' => $input['team'] ?? '',
            'body' => $input['body'] ?? '',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['ticket_id' => $id, 'created' => true];
    }

    /** @return array<string, array<string, mixed>> */
    private function customers(): array
    {
        return $this->fixture('customers.json');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function orders(): array
    {
        return $this->fixture('orders.json');
    }

    /** @return array<string, mixed> */
    private function fixture(string $file): array
    {
        $path = storage_path('app/fixtures/'.$file);

        if (! is_file($path)) {
            throw new \RuntimeException("Missing fixture: {$path}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
