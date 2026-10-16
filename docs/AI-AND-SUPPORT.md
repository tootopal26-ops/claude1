# The optional AI provider, AI report commentary, and the Support Assistant

> **Status of this document:** the backend described here — the AI provider adapter, the report-commentary endpoint and the
> support-assistant endpoints — is implemented and tested (`php tests/run.php AiServiceTest`, `ReportAiTest`,
> `SupportAssistantTest`, `AiSupportAccessTest`). The admin screens and the customer-facing chat widget that call it are the next
> piece of this phase; until they land, every feature below is reachable only through its HTTP API, not yet through a page in
> Admin. This note will be updated, and the phase ticked off in [AUDIT-AND-CHECKLIST.md](AUDIT-AND-CHECKLIST.md), once they do.

## 1. The one rule that matters

**A reply that carries a specific order's facts is never touched by AI.** Payment status, order status, and what an expired
session means are decided *only* by `App\Support\Status` and `App\Services\Orders::customerView()` — the exact same code the
order tracking page itself uses, so a chat answer can never disagree with the order page. AI, when an owner has switched it on,
is only ever allowed to *rephrase* an answer that rules already decided, for the handful of questions that carry no one's
specific facts (where to find an order id, the refund policy in general, delivery times in general, payment methods, a
greeting) — and even then, its reply is thrown away in favour of the plain answer if it is too long or if it invents a link
that was not already in the approved text. `tests/Integration/SupportAssistantTest.php` proves this by wiring a fake AI
provider that *would* answer something else entirely, and showing the order-specific reply never changes.

The same discipline applies to the daily report: AI commentary is validated, strictly-shaped prose stored *apart* from the
figures (`daily_reports.ai_*`) — it is never consulted to calculate or restate a number, and is cleared automatically the moment
a regeneration changes a figure it was commenting on ([WATCHDOG-AND-REPORTS.md](WATCHDOG-AND-REPORTS.md)).

## 2. AI is optional, off by default, and the application needs none of it

Every feature below has a fully deterministic path:

* No AI is configured → the daily report is exactly as deterministic as before this phase (see WATCHDOG-AND-REPORTS.md); the
  support assistant answers with the same rules-based templates it always would, clearly from rules, never claiming to be an
  AI model when it answers that way.
* AI is configured but the call fails, times out, or answers something that does not parse → the deterministic path is used
  and nothing claims otherwise. A customer is never shown an error; an admin asking for report commentary is told plainly what
  happened (*unavailable* / *invalid*) with the report itself completely unaffected.

## 3. The AI provider (`App\Ai`, `App\Services\AiService`)

One optional provider, configured once (Admin → AI & Support → Provider, permission `ai.configure`, **owner only** — this is the
one decision that decides whether any data leaves the server for a third party at all, so it is kept away from `manager`).

* **Adapter pattern:** business logic (`ReportAi`, `SupportAssistant`) depends only on `App\Ai\AiProviderInterface`
  (`complete(system, user, maxTokens): AiResult`), never on a vendor. Two adapters ship: `AnthropicProvider` (Anthropic's
  Messages API, `POST {base}/v1/messages`, `x-api-key` + `anthropic-version` headers) and `OpenAiCompatibleProvider` (the
  "chat completions" shape many hosted and self-hosted model gateways implement, `POST {base}/chat/completions`, bearer
  token). Swapping providers is a settings change, not a code change.
* **Transport:** `App\Ai\HttpJsonClient`, the same SSRF-guarded shape as every other outbound call in this codebase
  (`App\Smm\SmmClient`, `App\Payments\AlphaUpiApi`) — public addresses only (`App\Support\NetGuard`), the connection pinned to
  the address that was just checked (defeats DNS rebinding), no redirects followed, bounded connect/total time and response
  bytes, the API key scrubbed from any error text before it is ever stored or shown.
* **The key** is AES-256-GCM encrypted at rest (the same `App\Core\Crypto` as the payment gateway and SMM provider secrets),
  write-only in the admin API (`has_key: true/false`, never the key itself), never logged.
* **Every call is recorded** in `ai_usage_logs` — feature (`report`/`support`/`test`), provider, model, status
  (`ok`/`error`/`invalid`/`limited`), HTTP status, token counts when the provider reports them, latency, and an error
  *category* (`network`/`auth`/`rate_limit`/`server`/`format`) — **never the prompt, never the answer, never the key**. A
  disabled provider logs nothing (there was no attempt to record).
* **Never throws out to the caller:** `AiService::complete()` always returns a typed `AiResult`
  (`ok`/`disabled`/`unreachable`/`invalid`); a provider that throws unexpectedly is caught and turned into `unreachable`.

## 4. AI report commentary (`App\Services\ReportAi`, master prompt §6.2)

`POST /admin/api/reports/{date}/ai` (permission `reports.generate` — the same as regenerating a report; 10 per 10 minutes per
admin; idempotent per `Idempotency-Key`). Only runs when AI is configured **and** the separate `ai.use_for_reports` switch is
on (so enabling the provider for support does not silently also start sending report data, and vice versa).

* **What is sent:** a small, pre-aggregated JSON object built from the report's own stored snapshot — every metric's label,
  value, unit and basis; the comparison with the day before; the most-profitable / loss-making / unknown-cost service titles
  and contributions; unresolved alert titles and severities; the failure counts. **Never** an order id, a customer link, a
  target/username, a payment reference, an IP address, or a raw database row.
* **What is required back:** strict JSON, one shape only — `{summary, changes[], causes[], next_steps[], uncertainties[]}` —
  parsed out of the reply even if the model wrapped it in prose or a markdown fence. `summary` ≤ 700 characters; each list
  ≤ 6 items of ≤ 240 characters; anything else (wrong type, a missing `summary`, no JSON object found at all) is `invalid` and
  **nothing is shown** — the figures were never at risk, since the parser only ever writes to the `ai_*` columns.
* **The system prompt** instructs the model to use only the numbers it was given, to call a change that merely coincided with
  another a *possible correlation* rather than asserting a cause, and to say plainly in `uncertainties` when the data is too
  thin to conclude much — never to guess.
* Stored on `daily_reports`: `ai_status` (`none`/`ok`/`unavailable`/`invalid`), `ai_provider`, `ai_model`, `ai_summary` (the
  validated JSON, re-encoded — never the model's raw text), `ai_generated_at`. Cleared back to `none` the moment
  `DailyReport::generate(..., force: true)` changes a figure the commentary might have described.

## 5. The Support Assistant (`App\Services\SupportAssistant`, master prompt §7)

A stateless-except-for-its-own-key customer chat. There is no customer account: a conversation is identified by a random
128-bit key the browser keeps (`localStorage`, exactly like an order's access token), created by `POST /api/support/start`
(rate-limited 20/hour per IP) and used for every later `POST /api/support/{key}/message` and `GET /api/support/{key}/history`.
`GET /api/support/config` lets the widget hide itself without a failed POST when the owner has switched the whole feature off
(`support.enabled`, default on).

### Ownership is proven, never assumed

A message may optionally carry `order_token` — the **same 128-bit access token** `/order/{token}` requires, never a bare order
id (`SMB-XXXXXXXX` alone proves nothing and is never accepted as a lookup key, matching every other order-facing endpoint in
this application). A wrong or unknown token is treated exactly like no token at all: the assistant asks the customer to paste
the order link, and never errors in a way that would hint whether a given code belongs to *someone else's* real order.

### What it can answer (rules, always available, no AI required)

| Intent | Needs a verified order? | Answer |
|---|---|---|
| Payment status / order status / what an expired session means | Only answered *with* one; without one, asks for the order link | `Status::customerLabel()` of the real order, plus one honest sentence for `FAILED` / `EXPIRED` / otherwise |
| Where to find an order id | No | Format and where it is saved (payment page, the browser's own "Your orders") |
| Refund policy | No | Reviewed by a person, case by case — never a promised amount or a guaranteed refund |
| Delivery times | No | General, mode-independent, truthful ranges; always points at the order's own page for the real answer |
| Payment methods | No | UPI only, stated plainly |
| A greeting / anything unclear | No | States its own scope (exactly §7.1) and asks the customer to pick one |

### Where AI may help, and where it is architecturally barred from trying

Only the rows marked "No" above are ever candidates for AI phrasing (`ai.use_for_support`, off by default, independent of
`ai.use_for_reports`) — any message that resolved a verified order is **never** passed to AI, regardless of which intent it
matched. When AI is used, it is given the already-decided answer and told only to phrase it more warmly in at most 60 words,
never to add a fact; its reply is rejected (and the deterministic text used verbatim, `mode: "rules"`) if it is empty, over
500 characters, looks like code or JSON, or contains a URL that was not already in the approved answer.

### Limits and retention

A message is 1–1,000 characters; a conversation closes itself after 200 messages. Rate limits: 60 messages / 10 minutes per
IP, 20 / 10 minutes per conversation, 60 history reads / minute. Retention (`support.retention_days`, default 30, 1–365,
`support.configure`) sets each conversation's `expires_at`; the `cleanup` job deletes conversations (and, by cascade, their
messages) past it — the same job and the same column that already existed from Phase 2's migration.

### Roles

| Permission | owner | manager | support | analyst |
|---|---|---|---|---|
| `support.view` — read conversations and counts | ✔ | ✔ | ✔ | – |
| `support.configure` — on/off switch, retention | ✔ | ✔ | – | – |
| `ai.configure` — the provider, its key, usage stats, and the two per-feature "use AI for…" switches | ✔ | – | – | – |

## 6. What this is *not*

* Not a general-purpose chatbot: outside its stated scope (§7.1) it says so and asks the customer to rephrase — it never
  invents a service guarantee, a refund, a delivery time, or a completed order that the order record does not confirm.
* Not a channel for secrets: the assistant and the report commentary alike never see a password, a payment credential, an API
  key, or more of an order than `Orders::customerView()` already exposes on the order page itself.
* Not tested against a live AI provider. Both adapters were exercised against a fake implementing `AiProviderInterface`
  (no network in `AiServiceTest`/`ReportAiTest`/`SupportAssistantTest`) — their HTTP mechanics (SSRF guard, pinned
  resolution, byte cap) are the same already-proven code as the SMM and payment-gateway clients, but the vendor-specific
  request/response shapes themselves have not been run against `api.anthropic.com` or a real OpenAI-compatible endpoint.
