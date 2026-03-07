# Development Guide

Developer documentation for the Mollie Payment Processor CiviCRM extension (`nl.stichtinggast.mollie`).

## Architecture

### Design Decisions

- **Mollie PHP SDK** — uses the official `mollie/mollie-api-php` SDK directly. Mollie's recurring flow (Customer → Mandate → Subscription) requires direct API access that payment abstraction layers don't support.
- **Mollie-managed recurring** — Mollie's Subscriptions API handles scheduling and charging. CiviCRM does not trigger recurring payments; Mollie does, and notifies CiviCRM via webhooks.
- **Mollie as source of truth** — for all subscription-managed fields (status, next charge date, amount). The sync job reconciles CiviCRM state from Mollie, not the other way around.
- **Webhook-driven status updates** — contribution status is always determined by fetching the full payment from Mollie's API after receiving a webhook. The redirect return URL is never used to determine payment outcome.
- **Idempotent webhook handling** — the handler checks `trxn_id` existence before creating contributions, making it safe to receive duplicate webhook calls.
- **Minimal custom schema** — one custom entity (`MollieCustomer`) maps contacts to Mollie customer IDs. All other Mollie references use standard CiviCRM fields:
  - `ContributionRecur.processor_id` — Mollie subscription ID
  - `PaymentToken` — Mollie mandate ID
  - `Contribution.trxn_id` — Mollie payment ID
- **CiviCRM APIv4** — used throughout, except for `Contribution.completetransaction` and `Contribution.repeattransaction` which are only available in APIv3 in CiviCRM 6.
- **Managed entities** — all configuration (payment processor type, saved searches, scheduled jobs, option values, message templates) is declared as managed entities, ensuring consistent state on install/upgrade.
- **SearchKit + Afform** — admin dashboard built entirely with SearchKit saved searches and Afform, avoiding custom Angular or PHP page controllers.
- **Rate-limited API calls** — the sync job uses automatic retry with backoff for Mollie 429 responses, respecting the `Retry-After` header.

### Payment Flows

#### One-Off Payment

**Initiation** (`doPayment()`, non-recurring):

1. CiviCRM creates a pending Contribution and calls `doPayment()`
2. Zero-amount check — if amount is 0, mark Completed immediately (no Mollie interaction)
3. Build Mollie payment params (amount, currency, description, webhookUrl, redirectUrl, locale)
4. `POST /v2/payments` — create Mollie payment
5. Store Mollie payment ID in `Contribution.trxn_id`
6. Redirect contact to `molliePayment->getCheckoutUrl()`

**Webhook** (`processOneOffOrFirstPaymentWebhook()`):

7. Mollie POSTs `id=<payment_id>` to the webhook endpoint
8. Fetch full payment from Mollie API (`payments->get()`)
9. Look up Contribution by `trxn_id`
10. Check for chargebacks first (see below)
11. Idempotency check — skip if Contribution is already Completed
12. **If paid**: call `Contribution.completetransaction` (APIv3), recording fee amount from settlement data
13. **If failed/cancelled/expired**: mark Contribution as Failed or Cancelled

#### Recurring Payment — First Payment

**Initiation** (`doPayment()`, recurring):

1. Same as one-off, plus:
2. `findOrCreateMollieCustomer()` — look up existing MollieCustomer record, or create a new Mollie customer via `POST /v2/customers` and store the mapping
3. Set `sequenceType: "first"` and `customerId` on the payment params
4. Store `contribution_recur_id` in Mollie payment metadata
5. Redirect to Mollie checkout — the contact completes the payment as usual

**Webhook — first payment success** (`handleFirstRecurringPaymentCompleted()`):

6. Webhook arrives, payment is fetched and matched to Contribution (same as one-off)
7. `completeContribution()` — mark the first Contribution as Completed
8. `verifyMandate()` — fetch mandates from `GET /v2/customers/{id}/mandates`, find one with status "valid" or "pending"
9. **If no valid mandate**: fail the ContributionRecur (the recurring series cannot proceed)
10. `createPaymentToken()` — store the mandate ID as a CiviCRM PaymentToken
11. **If installments = 1**: mark ContributionRecur as Completed (no subscription needed)
12. `createMollieSubscription()` — `POST /v2/customers/{id}/subscriptions` with:
    - Amount, currency, interval (mapped from CiviCRM frequency), description, webhookUrl, mandateId
    - `startDate` from `next_sched_contribution_date` if set
    - `times` = installments - 1 (first payment already made)
13. Store Mollie subscription ID in `ContributionRecur.processor_id`
14. Set ContributionRecur status to "In Progress"

**Webhook — first payment failure**:

- If the first payment is failed/cancelled/expired, the Contribution is marked Failed/Cancelled
- The ContributionRecur is also marked Failed/Cancelled — no mandate exists, so no subscription is possible

#### Recurring Payment — Subsequent Installments

Mollie charges the contact automatically per the subscription schedule using `sequenceType: "recurring"`. Each charge triggers a webhook.

**Webhook** (`processRecurringPaymentWebhook()`):

1. Webhook arrives, payment is fetched from Mollie API
2. The payment includes `subscriptionId` — this distinguishes it from first/one-off payments
3. Idempotency check — skip if a Contribution with this `trxn_id` already exists
4. Look up ContributionRecur by `processor_id` (= Mollie subscription ID)
5. **If paid**: `Contribution.repeattransaction` (APIv3) creates a new Completed Contribution linked to the recurring series; fee amount recorded from settlement data; `next_sched_contribution_date` updated
6. **If failed/expired/cancelled**: `Contribution.repeattransaction` creates a Failed Contribution; `failure_count` incremented on ContributionRecur

#### Chargebacks

Mollie re-sends the payment webhook when a chargeback is filed. The payment stays "paid" but gains chargeback data. The handler:

1. Detects chargebacks before the idempotency skip (payment is "paid" but has chargebacks)
2. Marks the Contribution status as "Chargeback"
3. Fetches chargeback details from Mollie (amount, date, reason code)
4. Attaches a Note to the Contribution with full chargeback details for staff reference
5. Applies to both one-off and recurring installment payments

### Key Entry Points

| File | Purpose |
|------|---------|
| `CRM/Core/Payment/Mollie.php` | Payment processor — payment initiation, webhook handling, recurring lifecycle |
| `Civi/Api4/Action/MollieSync/Run.php` | Sync job implementation |
| `Civi/Api4/Action/MollieRecurringReminder/Run.php` | Reminder job implementation |
| `schema/MollieCustomer.entityType.php` | MollieCustomer entity schema |
| `managed/*.mgd.php` | Managed entities (processor type, saved searches, scheduled jobs, templates) |
| `mollie.php` | Hook implementations |

### CiviCRM Conventions

- Translation: user-facing strings wrapped in `E::ts()`
- Logging: `\Civi::log('mollie')` with PSR-3 levels
- Mixins: `entity-types-php@2.0`, `mgd-php@1.0`, `setting-php@1.0`, `menu-xml@1.0`, `scan-classes@1.0`, `smarty-v2@1.0`

### Mollie API Quick Reference

| API | Endpoint | Purpose | Docs |
|-----|----------|---------|------|
| Payments | `POST /v2/payments` | Create one-off and first recurring payments | [Payments API](https://docs.mollie.com/reference/create-payment) |
| Customers | `POST /v2/customers` | Required for recurring flow | [Customers API](https://docs.mollie.com/reference/create-customer) |
| Mandates | `GET /v2/customers/{id}/mandates` | Verify after first payment | [Mandates API](https://docs.mollie.com/reference/list-mandates) |
| Subscriptions | `POST /v2/customers/{id}/subscriptions` | Mollie-managed recurring | [Subscriptions API](https://docs.mollie.com/reference/create-subscription) |

See also:
- [Recurring payments guide](https://docs.mollie.com/docs/recurring-payments)
- [Webhook handling](https://docs.mollie.com/docs/webhooks)
- [Mollie PHP SDK](https://github.com/mollie/mollie-api-php)

**Recurring flow**: Customer → First payment (`sequenceType: first`) → Mandate auto-created → Subscription → Mollie charges automatically (`sequenceType: recurring`)

**Webhook contract**: Mollie POSTs `id=<payment_id>` — always fetch the full payment object to verify status. Never trust the POST body alone.

### Webhook Security

The webhook endpoint is unauthenticated. Security relies on:
1. Mollie only sends a payment ID, not status data
2. The handler fetches the full payment from Mollie's API using the stored API key
3. The handler is idempotent — it checks `trxn_id` existence before creating contributions
4. Must return HTTP 200 within 15 seconds

## Development Workflow

### Prerequisites

- Docker (for running Composer and tests without local PHP)

### Makefile

The project includes a Makefile for common tasks, all running in Docker containers:

```bash
make install    # Install development dependencies via Composer
make test       # Run PHPUnit test suite with --testdox output
make clean      # Reset vendor/ directory to committed state
make            # Run all three in sequence
```

### Implementation Notes

- Never log full API keys — only the last 4 characters
- All Mollie API calls must be wrapped in try/catch for `\Mollie\Api\Exceptions\ApiException`
- Zero-amount contributions are handled without Mollie interaction

