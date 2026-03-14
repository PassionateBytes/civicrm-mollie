# Development Guide

Developer documentation for the Mollie Payment Processor CiviCRM extension (`nl.stichtinggast.mollie`).

## CiviCRM Developer Resources

If you're new to CiviCRM extension development, start here:

- [Extension Development Guide](https://docs.civicrm.org/dev/en/latest/extensions/) — structure, mixins, managed entities, hooks
- [Payment Processor Development](https://docs.civicrm.org/dev/en/latest/financial/paymentprocessors/create/) — the `CRM_Core_Payment` base class contract
- [APIv4 Reference](https://docs.civicrm.org/dev/en/latest/api/v4/usage/) — the primary API used throughout this extension
- [SearchKit & Afform](https://docs.civicrm.org/dev/en/latest/searchkit/) — used for the admin dashboard
- [Managed Entities](https://docs.civicrm.org/dev/en/latest/extensions/civix/#generate-entity) — auto-managed configuration (v4 format)
- [Workflow Message Templates](https://docs.civicrm.org/dev/en/latest/framework/message-templates/) — the reminder email template pattern

## Architecture

### Core Design Decisions

- **Mollie PHP SDK** — uses the official `mollie/mollie-api-php` SDK directly. Mollie's recurring flow (Customer → Mandate → Subscription) requires direct API access that payment abstraction layers don't support.
- **Mollie-managed recurring** — Mollie's Subscriptions API handles scheduling and charging. CiviCRM does not trigger recurring payments; Mollie does, and notifies CiviCRM via webhooks.
- **Mollie as source of truth** — for all subscription-managed fields (status, next charge date, amount). The sync job reconciles CiviCRM state from Mollie, not the other way around.
- **Webhook-driven status updates** — contribution status is always determined by fetching the full payment from Mollie's API after receiving a webhook. The redirect return URL is never used to determine payment outcome.
- **Idempotent webhook handling** — multiple layers of protection against duplicate processing: per-payment lock via `Civi::lockManager()`, contribution status checks, `FinancialTrxn.trxn_id` existence checks in `completeContribution()`, `handleChargeback()`, and `handleRefund()`, and `Contribution.trxn_id` existence checks for recurring installments.
- **Minimal custom schema** — one custom entity (`MollieCustomer`) maps contacts to Mollie customer IDs. All other Mollie references use standard CiviCRM fields:
  - `ContributionRecur.processor_id` — Mollie subscription ID
  - `PaymentToken` — Mollie mandate ID
  - `Contribution.trxn_id` — Mollie payment ID
- **CiviCRM APIv4** — used throughout, except for `Payment.create` and `Contribution.repeattransaction` which are only available in APIv3 in CiviCRM 6.
- **Managed entities** — all configuration (payment processor type, saved searches, scheduled jobs, option values, message templates) is declared as managed entities, ensuring consistent state on install/upgrade.
- **SearchKit + Afform** — admin dashboard built entirely with SearchKit saved searches and Afform, avoiding custom Angular or PHP page controllers.
- **Rate-limited API calls** — the sync job uses automatic retry with backoff for Mollie 429 responses, respecting the `Retry-After` header.

### Key Entry Points

| File                                               | Purpose                                                                       |
| -------------------------------------------------- | ----------------------------------------------------------------------------- |
| `CRM/Core/Payment/Mollie.php`                      | Payment processor — payment initiation, webhook handling, recurring lifecycle |
| `Civi/Api4/Action/MollieSync/Run.php`              | Sync job implementation                                                       |
| `Civi/Api4/Action/MollieRecurringReminder/Run.php` | Reminder job implementation                                                   |
| `Civi/Mollie/Token/ContributionRecurTokens.php`    | Custom token provider for `{contribution_recur.*}` tokens                     |
| `CRM/Mollie/WorkflowMessage/RecurringReminder.php` | Workflow message class for reminder emails                                    |
| `schema/MollieCustomer.entityType.php`             | MollieCustomer entity schema                                                  |
| `managed/*.mgd.php`                                | Managed entities (processor type, saved searches, scheduled jobs, templates)  |
| `mollie.php`                                       | Hook implementations                                                          |

### CiviCRM Conventions

- Translation: user-facing strings wrapped in `E::ts()`
- Logging: `\Civi::log('mollie')` with PSR-3 levels via `CRM_Mollie_Log`
- Mixins: `entity-types-php@2.0`, `mgd-php@2.0`, `setting-php@1.0`, `menu-xml@1.0`, `scan-classes@1.0`, `smarty@1.0`
- Schema management: `CiviMix\Schema\Mollie\AutomaticUpgrader` handles table creation/deletion from `schema/*.entityType.php` files. The custom `CRM_Mollie_Upgrader` class is reserved for `upgrade_NNNN()` migration steps only.

## Payment Flows

### One-Off Payment

**Initiation** (`doPayment()`, non-recurring):

1. CiviCRM creates a pending Contribution and calls `doPayment()`
2. Zero-amount check — if amount is 0, mark Completed immediately (no Mollie interaction; see [Zero-Amount Payments](#zero-amount-payments))
3. Build Mollie payment params (amount, currency, description, webhookUrl, redirectUrl, locale)
4. `POST /v2/payments` — create Mollie payment
5. Store Mollie payment ID in `Contribution.trxn_id`
6. Redirect contact to `molliePayment->getCheckoutUrl()`

**Webhook** (`processOneOffOrFirstPaymentWebhook()`):

7. Mollie POSTs `id=<payment_id>` to the webhook endpoint
8. Per-payment lock acquired via `Civi::lockManager()` (10s timeout)
9. Fetch full payment from Mollie API (`payments->get()`)
10. Look up Contribution by `trxn_id`
11. Check for post-payment events first (refunds, chargebacks — see below)
12. Idempotency check — skip if Contribution is already Completed
13. **If paid**: `completeContribution()` checks for existing `FinancialTrxn` by Mollie payment ID (defense-in-depth against concurrent webhooks), then calls `Payment.create` (APIv3) to record the payment and transition the contribution to Completed, recording fee amount from settlement data
14. **If failed/cancelled/expired**: mark Contribution as Failed or Cancelled

### Recurring Payment — First Payment

**Initiation** (`doPayment()`, recurring):

1. Same as one-off, plus:
2. `findOrCreateMollieCustomer()` — look up existing MollieCustomer record, or create a new Mollie customer via `POST /v2/customers` and store the mapping
3. Set `sequenceType: "first"` and `customerId` on the payment params
4. Store `contribution_recur_id` in Mollie payment metadata
5. Redirect to Mollie checkout — the contact completes the payment as usual

**Webhook — first payment success** (`handleFirstRecurringPaymentCompleted()`):

6. Webhook arrives, payment is fetched and matched to Contribution (same as one-off)
7. `completeContribution()` — mark the first Contribution as Completed
8. Read `mandateId` directly from the Mollie payment object (more precise than listing mandates, saves an API call)
9. **If no mandate ID**: fail the ContributionRecur (the recurring series cannot proceed)
10. `createPaymentToken()` — store the mandate ID as a CiviCRM PaymentToken
11. **If installments = 1**: mark ContributionRecur as Completed (no subscription needed)
12. `createMollieSubscription()` — `POST /v2/customers/{id}/subscriptions` with:
    - Amount, currency, interval (mapped from CiviCRM frequency), description, webhookUrl, mandateId
    - `startDate` from `next_sched_contribution_date` if set
    - `times` = installments - 1 (first payment already made)
13. Store Mollie subscription ID in `ContributionRecur.processor_id`
14. Set ContributionRecur status to "In Progress"
15. **On failure after subscription created**: cleanup logic cancels the Mollie subscription to prevent orphaned charges

**Webhook — first payment failure**:

- If the first payment is failed/cancelled/expired, the Contribution is marked Failed/Cancelled
- The ContributionRecur is also marked Failed/Cancelled — no mandate exists, so no subscription is possible

### Recurring Payment — Subsequent Installments

Mollie charges the contact automatically per the subscription schedule using `sequenceType: "recurring"`. Each charge triggers a webhook.

**Webhook** (`processRecurringPaymentWebhook()`):

1. Webhook arrives, payment is fetched from Mollie API
2. The payment includes `subscriptionId` — this distinguishes it from first/one-off payments
3. Idempotency check — skip if a Contribution with this `trxn_id` already exists
4. Look up ContributionRecur by `processor_id` (= Mollie subscription ID)
5. **If paid**: `Contribution.repeattransaction` (APIv3) creates a new Pending Contribution linked to the recurring series (cloning template data, line items, soft credits); then `Payment.create` records the payment with fee amount from settlement data and transitions to Completed; `next_sched_contribution_date` updated
6. **If failed/expired/cancelled**: `Contribution.repeattransaction` creates a Pending Contribution, then status is explicitly set to Failed; `failure_count` incremented on ContributionRecur

### Chargebacks and Refunds

Mollie re-sends the payment webhook when a chargeback or refund occurs. The payment stays "paid" but gains chargeback/refund data. These are handled in `routePaymentWebhook()` before the per-type handlers, since they apply to both one-off and recurring payments.

When a payment has both refunds and chargebacks, refunds are processed first (so CiviCRM sets Refunded/Partially paid), then chargebacks override the status.

**Chargebacks** (`handleChargeback()`):

1. Detects chargebacks on paid contributions before the idempotency skip
2. Per-chargeback idempotency — each chargeback's Mollie ID is checked against existing `FinancialTrxn.trxn_id`
3. Records a negative `Payment.create` for each new chargeback (proper financial bookkeeping)
4. Overrides the contribution status to "Chargeback" (a standard CiviCRM status, value 10)
5. Attaches a Note to the Contribution with full chargeback details (ID, amount, date, reason code) for staff reference

**Refunds** (`handleRefund()`):

1. Detects refunds on paid contributions before the idempotency skip
2. Only records refunds at the terminal `refunded` status — skips `processing` and `failed` states to avoid recording refunds that may later fail
3. Per-refund idempotency via `FinancialTrxn.trxn_id`
4. Records a negative `Payment.create` for each completed refund
5. CiviCRM automatically transitions the contribution status: full refund → Refunded, partial → Partially paid
6. Attaches a Note with refund details (ID, amount, status, remaining balance)

### Zero-Amount Payments

Zero-amount payments (`amount == 0`) short-circuit in `doPayment()` and complete immediately without contacting Mollie. This applies to both one-off and recurring payments.

For recurring series, this means the extension assumes the first payment always carries a real charge. The first payment's Mollie checkout creates the mandate (payment authorization), and the subscription is created afterward with `times = installments - 1` (subtracting the first payment).

A potential "authorize now, charge later" flow (€0.00 first payment to create a mandate without charging) is **not supported**. Implementing this would require bypassing the zero-amount short-circuit for recurring payments, revisiting the `times = installments - 1` logic, and handling the installment count difference.

## Webhook Handling

### Security

The webhook endpoint is unauthenticated. Security relies on:

1. Mollie only sends a payment ID, not status data
2. The handler fetches the full payment from Mollie's API using the stored API key
3. The handler is idempotent — it checks `trxn_id` existence before creating contributions
4. Must return HTTP 200 within 15 seconds

### Concurrency

Concurrent webhooks for the same payment ID are serialized using CiviCRM's lock manager (`Civi::lockManager()`, backed by MySQL `GET_LOCK`). The lock uses a 10-second blocking timeout. If the lock times out, HTTP 500 is returned so Mollie retries later. This prevents duplicate `FinancialTrxn` records from concurrent webhook deliveries.

### HTTP Response Codes

The handler classifies Mollie API fetch errors as transient or permanent:

- **Transient (return 500 → Mollie retries)**: network failure, 429, 500, 502, 503, 504
- **Permanent (return 200 → stop retries)**: 401, 403, 404, 410, 422

### Unmatched Payments

When a webhook cannot be matched to a CiviCRM contribution or recurring contribution, an "Unmatched Mollie Payment" activity is created with detailed payment information for staff investigation.

## Email Templates

The extension ships a workflow message template (`mollie_recurring_reminder`) for pre-payment reminder emails. It follows CiviCRM's standard managed template pattern:

- **Reserved template** (`update: always`) — kept in sync with the extension code on every upgrade. Serves as the factory default.
- **Editable template** (`update: never`) — created once on install, never overwritten. Admins can customize this via the CiviCRM UI. To revert to the default, use the "Revert to default" option in CiviCRM's Message Templates screen.

The template source is in `managed/MessageTemplate_RecurringReminder.mgd.php` and includes both HTML and plain text versions.

### Custom Tokens

The extension registers a custom token provider (`Civi/Mollie/Token/ContributionRecurTokens.php`) that activates when `contributionRecurId` is present in the token processor schema. This makes `{contribution_recur.*}` tokens available in the reminder template.

| Token                                               | Resolves to                             | Example            |
| --------------------------------------------------- | --------------------------------------- | ------------------ |
| `{contribution_recur.amount}`                       | Formatted amount with currency symbol   | `€ 25,00`          |
| `{contribution_recur.currency}`                     | ISO currency code                       | `EUR`              |
| `{contribution_recur.frequency_interval}`           | Frequency interval number               | `1`                |
| `{contribution_recur.frequency_unit}`               | Frequency unit (raw DB value)           | `month`            |
| `{contribution_recur.next_sched_contribution_date}` | Next charge date (formatted, date only) | `March 10th, 2026` |

In addition, all standard CiviCRM tokens are available in the template:

| Token namespace | Description                                                                         |
| --------------- | ----------------------------------------------------------------------------------- |
| `{contact.*}`   | Contact fields (e.g., `{contact.email_greeting_display}`, `{contact.display_name}`) |
| `{domain.*}`    | Domain/organization fields (e.g., `{domain.name}`)                                  |

The template also supports Smarty syntax for conditional logic. The default template uses this to format the frequency display (e.g., "Every month" vs "Every 3 month").

## Mollie API Quick Reference

| API           | Endpoint                                | Purpose                                     | Docs                                                                       |
| ------------- | --------------------------------------- | ------------------------------------------- | -------------------------------------------------------------------------- |
| Payments      | `POST /v2/payments`                     | Create one-off and first recurring payments | [Payments API](https://docs.mollie.com/reference/create-payment)           |
| Customers     | `POST /v2/customers`                    | Required for recurring flow                 | [Customers API](https://docs.mollie.com/reference/create-customer)         |
| Mandates      | `GET /v2/customers/{id}/mandates`       | Verify after first payment                  | [Mandates API](https://docs.mollie.com/reference/list-mandates)            |
| Subscriptions | `POST /v2/customers/{id}/subscriptions` | Mollie-managed recurring                    | [Subscriptions API](https://docs.mollie.com/reference/create-subscription) |

See also:

- [Recurring payments guide](https://docs.mollie.com/docs/recurring-payments)
- [Webhook handling](https://docs.mollie.com/docs/webhooks)
- [Mollie PHP SDK](https://github.com/mollie/mollie-api-php)

**Recurring flow**: Customer → First payment (`sequenceType: first`) → Mandate auto-created → Subscription → Mollie charges automatically (`sequenceType: recurring`)

**Webhook contract**: Mollie POSTs `id=<payment_id>` — always fetch the full payment object to verify status. Never trust the POST body alone.

## Development Workflow

### Prerequisites

- Docker (for running Composer and tests without local PHP)

### Makefile

The project includes a Makefile for common tasks, all running in Docker containers:

```bash
make install    # Install development dependencies via Composer
make test       # Run PHPUnit test suite with --testdox output
make clean      # Reset vendor/ directory to committed state
make            # Run all three in sequence (install, test, clean)
```

### Testing

The test suite uses PHPUnit with standalone stubs (no CiviCRM bootstrap required). Tests run in Docker via `make test`.

**Test architecture**:

- `tests/Stubs/CiviStubs.php` — minimal stubs for CiviCRM classes (Api4 entities, `CRM_Core_Payment`, `PropertyBag`, etc.) and a mock infrastructure (`Api4Mock`, `Api3Mock`) that captures API calls and returns configured results
- Each test file defines a testable subclass that overrides external dependencies (Mollie API client, CiviCRM API calls) while letting the business logic run
- Mollie SDK objects (`Payment`, `Subscription`, etc.) are used directly with properties set manually — no mocking of SDK internals

**Test files**:

| File                   | Covers                                                                     |
| ---------------------- | -------------------------------------------------------------------------- |
| `MollieDoPaymentTest`  | Payment initiation: zero-amount, one-off, recurring, API failure           |
| `MolliePaymentTest`    | Configuration, frequency mapping, date calculations                        |
| `MollieWebhookTest`    | Webhook routing, status handling, fees, lock behavior                      |
| `MollieProcessingTest` | Contribution completion, chargebacks, refunds, subscriptions, cancellation |
| `MollieSyncRunTest`    | Status sync, field updates, rate limiting, end-to-end sync flow            |
| `MollieReminderTest`   | Reminder job: settings, dedup, email, error handling                       |

**Adding tests**: Follow the existing pattern — create a testable subclass that stubs external dependencies, configure `Api4Mock::setResult()` for CiviCRM API responses, and assert against `Api4Mock::$calls` to verify what was written.

### Debugging

**Debug logging**: Enable in extension settings (**Administer > CiviContribute > Mollie Settings**). Logs are written to CiviCRM's log system under the `mollie` channel via `CRM_Mollie_Log`. Debug mode logs full Mollie API request/response data — disable in production.

**Webhook issues**: If contributions stay in Pending status, Mollie cannot reach the webhook URL. Check:

1. CiviCRM is publicly accessible
2. No firewall blocks incoming POST requests to the webhook endpoint
3. Enable debug logging and check for webhook entries
4. Check the Mollie dashboard (**Developers > Webhooks**) for delivery status and error codes

**Unmatched payments**: Check the Activities tab for "Unmatched Mollie Payment" activities — these contain full Mollie payment details for investigation.

### Implementation Notes

- Never log full API keys — only the last 4 characters
- All Mollie API calls must be wrapped in try/catch for `\Mollie\Api\Exceptions\ApiException`
- Zero-amount contributions are handled without Mollie interaction
- The `MollieDetail` admin browser uses `performHttpCall()` on the Mollie SDK client — this is a public but undocumented internal method, used because the typed SDK endpoints don't expose raw JSON for generic resource browsing
