<?php

declare(strict_types=1);

namespace App\Services\Triage\Data;

use Anthropic\Core\Attributes\Required;
use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

/**
 * The classifier's output shape.
 *
 * Passed to the API as outputConfig: ['format' => TriageResult::class], which
 * constrains generation to this schema. That removes the two failure modes a
 * hand-rolled JSON prompt has: the model inventing a field, and the parse step
 * throwing on a stray prose preamble. It also replaces the few-shot examples
 * the prompt would otherwise need (see the token notes in docs/SUBMISSION.md).
 *
 * Assistant prefills -- the old way to force JSON -- return a 400 on current
 * models, so structured output is the only supported route here.
 */
final class TriageResult implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Required(enum: ['P1', 'P2', 'P3', 'P4'])]
    #[Constrained(description: 'Urgency band. P1 critical, P2 high, P3 normal, P4 low.')]
    public string $urgency;

    #[Required(enum: ['billing', 'auth', 'data_loss', 'integration', 'performance', 'how_to', 'feature_request', 'other'])]
    #[Constrained(description: 'Primary topic of the ticket.')]
    public string $topic;

    #[Required(enum: ['free', 'pro', 'business', 'enterprise'])]
    #[Constrained(description: 'Plan tier the customer is on. Use "free" when the ticket gives no indication.')]
    public string $plan_tier;

    #[Required(enum: ['angry', 'frustrated', 'neutral', 'positive'])]
    #[Constrained(description: 'Customer sentiment as expressed in the ticket text.')]
    public string $sentiment;

    #[Constrained(description: 'One sentence, max 200 characters, stating the concrete problem. No greeting, no restatement of the ticket.', maxLength: 200)]
    public string $summary;

    #[Constrained(description: 'Confidence in the urgency and topic assignment, from 0.0 to 1.0.', minimum: 0, maximum: 1)]
    public float $confidence;

    #[Constrained(description: 'True when a human must review before any automated action is taken.')]
    public bool $needs_human;

    #[Constrained(description: 'Customer email address if one appears in the ticket, otherwise null.', format: 'email')]
    public ?string $customer_email = null;

    #[Constrained(description: 'Order or invoice identifier if one appears in the ticket, otherwise null.')]
    public ?string $order_id = null;

    /**
     * Does this classification warrant waking the expensive model?
     *
     * @param  array{urgencies: list<string>, min_confidence: float|int}  $rule
     */
    public function shouldEscalate(array $rule): bool
    {
        return in_array($this->urgency, $rule['urgencies'], true)
            || $this->confidence < (float) $rule['min_confidence']
            || $this->needs_human;
    }

    /**
     * Why it escalated, for the run summary. Empty list means no escalation.
     *
     * @param  array{urgencies: list<string>, min_confidence: float|int}  $rule
     * @return list<string>
     */
    public function escalationReasons(array $rule): array
    {
        $reasons = [];

        if (in_array($this->urgency, $rule['urgencies'], true)) {
            $reasons[] = "urgency {$this->urgency} is in the escalation band";
        }

        if ($this->confidence < (float) $rule['min_confidence']) {
            $reasons[] = sprintf('confidence %.2f is below the %.2f threshold', $this->confidence, $rule['min_confidence']);
        }

        if ($this->needs_human) {
            $reasons[] = 'classifier flagged needs_human';
        }

        return $reasons;
    }
}
