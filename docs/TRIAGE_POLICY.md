# Triage Policy

The prose version of `config/triage.php`. The config file is the executable
copy — the classifier prompt is generated from it and the routing engine reads
it directly, so this document and the running system cannot drift apart.

---

## 1. What "correct triage" means here

A ticket is correctly triaged when four questions have defensible answers:

| Question | Answered by | Where |
|---|---|---|
| How bad is it? | urgency band | model, schema-constrained |
| What is it about? | topic | model, schema-constrained |
| Who owns it? | team | routing engine, deterministic |
| How fast must we respond? | SLA | routing engine, deterministic |

The split matters. The model is good at reading a ticket and saying *what it
is*; it should not be improvising *what happens next*. Urgency and topic are
model judgements. Team, channel, SLA, and whether to file an issue are policy,
and policy lives in code the support organisation can audit and change without
touching a prompt.

## 2. Urgency bands

Assign the **highest** band whose triggers are met. Judge by the impact
described, not by how upset the customer sounds — an angry tone about a
cosmetic bug is still P3.

### P1 — Critical (15 min first response)
- Complete outage, or the product is unusable
- Confirmed or suspected data loss or corruption
- Security incident, credential leak, or unauthorised access
- Payment processing failing for all customers

### P2 — High (60 min)
- A core workflow is broken with no workaround
- Billing error affecting a single customer, e.g. a double charge
- Authentication failures blocking a customer from signing in
- A paying customer explicitly threatening to churn

### P3 — Normal (8 hours)
- A bug with a documented or obvious workaround
- Degraded performance that is annoying but not blocking
- A configuration problem specific to one account

### P4 — Low (24 hours)
- How-to question answerable from the documentation
- Feature request or product feedback
- General enquiry with no reported failure

## 3. Topics and owning teams

| Topic | Covers | Team |
|---|---|---|
| `billing` | invoices, charges, refunds, subscriptions, plan changes | payments |
| `auth` | login, SSO, MFA, sessions, API keys, permissions | identity |
| `data_loss` | missing, deleted or corrupted customer data | platform |
| `integration` | webhooks, third-party connectors, public API errors | integrations |
| `performance` | slowness, timeouts, rate limits, degraded throughput | platform |
| `how_to` | usage questions answerable from documentation | docs |
| `feature_request` | functionality that does not exist yet | docs |
| `other` | anything that does not fit above | platform |

## 4. Additional classified fields

- **`plan_tier`** — `free` / `pro` / `business` / `enterprise`. What the ticket
  states or clearly implies; defaults to `free` when silent.
- **`sentiment`** — `angry` / `frustrated` / `neutral` / `positive`. Recorded
  for reporting. It deliberately does **not** feed the urgency band.
- **`confidence`** — 0.0–1.0. Below 0.75 forces escalation to the stronger model.
- **`needs_human`** — set when the ticket alleges data loss, a security problem,
  or legal action.
- **`summary`** — one sentence, ≤200 characters, stating the concrete problem.
- **`customer_email`** / **`order_id`** — extracted only if they literally
  appear in the text. Never inferred.

## 5. Routing resolution

Three layers, applied in order, each able to override the last.

**Layer 1 — topic picks the team and its channel.**
`billing → payments → #team-payments`

**Layer 2 — urgency overrides the channel and sets the base SLA.**
P1 and P2 are pulled into a shared war room so nothing critical sits unseen in
a quiet team channel:

| Urgency | Channel | Base SLA |
|---|---|---|
| P1 | `#support-war-room` | 15 min |
| P2 | `#support-escalations` | 60 min |
| P3 | team channel | 480 min |
| P4 | team channel | 1440 min |

**Layer 3 — plan tier scales the clock**, with a hard floor of 5 minutes:

| Plan | Multiplier |
|---|---|
| enterprise | 0.25 |
| business | 0.5 |
| pro | 1.0 |
| free | 2.0 |

So a P1 `data_loss` ticket from an enterprise customer resolves to: platform
team, `#support-war-room`, 15 × 0.25 = 3.75 → floored to **5 minutes**.

## 6. Side-effect rules

**File a GitHub issue** only when the topic is a tracked defect
(`data_loss`, `integration`, `performance`, `auth`) *and* urgency is P1–P3.
Billing disputes are handled by humans, not by filing an engineering defect;
how-to questions and feature requests would just be tracker noise.

**Require human review** when any of these hold:
- urgency is P1
- topic is `data_loss`
- plan tier is `enterprise`

## 7. Escalation to the stronger model

Every ticket is classified on the cheap tier. The expensive tier is woken only
when one of these holds:

- urgency is P1 or P2
- confidence is below 0.75
- the classifier set `needs_human`

Rationale in `docs/SUBMISSION.md` §(b).

## 8. Handling unknown values

Tool arguments arrive from a model, so an out-of-taxonomy value is a real
possibility rather than a theoretical one. `RoutingPolicy` normalises case and
falls back to the **safest** bucket rather than throwing: unknown urgency → P3,
unknown topic → `other`, unknown plan → `free`. Falling back keeps the pipeline
moving; throwing would strand the ticket mid-run.

Note that "safest" means slowest, not fastest: an unknown-everything ticket
resolves to 480 × 2.0 = 960 minutes, so it is never silently promoted into a
tighter SLA than it earned.

## 9. Changing the policy

Edit `config/triage.php` and bump `routing.policy_version`. Every routing
decision carries that version, so a decision made last week can be explained
against the policy that was actually in force. `tests/Feature/RoutingPolicyTest.php`
pins the behaviour of all three layers.
