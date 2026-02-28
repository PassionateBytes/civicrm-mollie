# CiviCRM Mollie Payment Processor Extension — Requirements Document

## 1. Overview

### 1.1 Purpose

A dedicated CiviCRM extension for processing payments through Mollie, supporting both one-off and recurring donations. The extension integrates directly with Mollie's REST API (v2) via the official PHP SDK, bypassing the Omnipay abstraction layer.

### 1.2 Scope

- One-off payments (redirect-based, via Mollie Payments API)
- Recurring payments (via Mollie Customers, Mandates, and Subscriptions APIs)
- Webhook-driven payment status synchronization
- Admin visibility into payment metadata and Mollie-side state
- Recurring contribution lifecycle management (cancellation, amount changes)
- Customizable pre-payment reminder emails for upcoming recurring charges
- Receipts via CiviCRM's built-in contribution receipt mechanism
- Test mode support using Mollie test API credentials

### 1.3 Target Environment

- CiviCRM >= 6.0
- PHP >= 8.1
- Mollie API v2
- SDK: `mollie/mollie-api-php` (latest stable)

### 1.4 Extension Identity

- **Name**: `nl.stichtinggast.mollie` (or similar org-scoped key)
- **Label**: "Mollie Payment Processor"
- **Maintainer**: `ict@stichtinggast.nl`

---

## 2. Architecture

### 2.1 Design Principles

1. **Mollie-native**: Use the Mollie PHP SDK directly, not Omnipay. This gives full access to Customers, Mandates, and Subscriptions APIs with proper type safety.
2. **CiviCRM-native**: Follow CiviCRM 6 conventions — APIv4, managed entities, entity-types-php mixin, SearchKit/Afform for admin UIs, workflow messages for notifications.
3. **Mollie-managed recurring**: Delegate recurring payment scheduling to Mollie's Subscriptions API rather than using CiviCRM scheduled jobs to trigger individual charges. Mollie handles timing, retries, and failure-based cancellation.
4. **Minimal footprint**: Only store Mollie-side references (customer ID, mandate ID, subscription ID) needed to map CiviCRM records to Mollie state. Do not duplicate data Mollie already manages.
5. **Separation of concerns**: The payment processor class handles Mollie API interaction. CiviCRM business logic (contribution creation, status updates, emails) stays in CiviCRM's domain.

### 2.2 Extension Structure

```
nl.stichtinggast.mollie/
├── info.xml                          # Extension manifest
├── mollie.php                        # Hook implementations
├── mollie.civix.php                  # Civix auto-generated (do not edit)
├── composer.json                     # Dependency: mollie/mollie-api-php
├── managed/
│   ├── PaymentProcessorType.mgd.php  # Mollie processor type registration
│   ├── ScheduledJob.mgd.php          # Recurring sync & reminder jobs
│   ├── OptionValues.mgd.php          # Custom option values (if needed)
│   └── SavedSearches.mgd.php         # SearchKit admin displays
├── schema/
│   └── MollieCustomer.entityType.php # Mollie customer ↔ CiviCRM contact mapping
├── settings/
│   └── mollie.setting.php            # Extension settings
├── Civi/
│   ├── Mollie/
│   │   ├── Api4/                     # Custom APIv4 actions
│   │   ├── Token/                    # Custom tokens for email templates
│   │   └── WorkflowMessage/          # Workflow message definitions
│   └── Api4/
│       └── MollieCustomer.php        # APIv4 entity interface
├── CRM/
│   └── Core/
│       └── Payment/
│           └── Mollie.php            # Payment processor class
├── ang/
│   ├── MolliePaymentDashboard.aff.html   # Admin search display
│   └── MolliePaymentDashboard.aff.json   # Admin page routing
├── templates/
│   └── CRM/Mollie/
│       └── ...                       # Smarty templates (if needed)
├── xml/
│   └── Menu/
│       └── mollie.xml                # URL routes (webhook endpoint)
└── tests/
    └── phpunit/
        └── ...                       # Test suite
```

### 2.3 Dependencies

| Dependency | Purpose |
|---|---|
| `mollie/mollie-api-php` | Official Mollie PHP SDK (Payments, Customers, Mandates, Subscriptions APIs) |

Installed via Composer. The extension's `composer.json` declares this dependency. CiviCRM's extension loader handles autoloading.

### 2.4 Data Model

#### 2.4.1 Custom Entity: `MollieCustomer`

Maps CiviCRM contacts to Mollie customer IDs. Required because Mollie's recurring flow requires a customer object.

| Field | Type | Description |
|---|---|---|
| `id` | `int unsigned` PK | Internal ID |
| `contact_id` | `int unsigned` FK → `civicrm_contact` | CiviCRM contact |
| `payment_processor_id` | `int unsigned` FK → `civicrm_payment_processor` | Processor instance (live or test) |
| `mollie_customer_id` | `varchar(32)` | Mollie customer ID (e.g., `cst_8wmqcHMN4U`) |
| `created_date` | `timestamp` | Record creation time |

Unique constraint on `(contact_id, payment_processor_id)` — one Mollie customer per contact per processor instance.

#### 2.4.2 Standard CiviCRM Entities Used

| Entity | Usage |
|---|---|
| `Contribution` | One-off payments and individual recurring installments |
| `ContributionRecur` | Recurring donation series. `processor_id` stores the Mollie subscription ID. |
| `PaymentProcessor` | Mollie API credentials (live key, test key) |
| `PaymentToken` | Stores the Mollie mandate ID linked to the recurring contribution |

> **Rationale**: Rather than creating additional custom entities for mandates and subscriptions, we store Mollie references in existing CiviCRM fields designed for this purpose (`processor_id`, `payment_token`). This keeps the data model lean and avoids orphan tracking tables.

---

## 3. Functional Requirements

### 3.1 Payment Processor Registration

#### 3.1.1 Processor Type Configuration

Register a `payment_processor_type` via managed entity with:

| Property | Value |
|---|---|
| `name` | `mollie` |
| `title` | `Mollie` |
| `billing_mode` | `4` (redirect / off-site notify) |
| `is_recur` | `true` |
| `payment_type` | `1` |
| `class_name` | `Payment_Mollie` |
| `user_name_label` | `Live API Key` |
| `password_label` | `Test API Key` |
| `signature_label` | _(unused)_ |

#### 3.1.2 Admin Configuration

When an admin creates a Mollie payment processor instance in CiviCRM:
- **Live API Key** (`user_name`): Mollie live API key (starts with `live_`)
- **Test API Key** (`password`): Mollie test API key (starts with `test_`)
- CiviCRM's built-in test/live mode toggle determines which key is used at runtime

### 3.2 One-Off Payments

#### 3.2.1 Payment Initiation Flow

1. Donor fills out CiviCRM contribution page (amount, contact info)
2. CiviCRM calls `doPayment()` on the payment processor
3. Extension creates a Mollie payment via `POST /v2/payments`:
   - `amount`: from contribution
   - `description`: configurable (contribution page title + contribution ID)
   - `redirectUrl`: CiviCRM's return URL (thank you page)
   - `webhookUrl`: extension's webhook endpoint
   - `metadata`: `{ "contribution_id": <id>, "contact_id": <id> }`
   - `locale`: derived from CiviCRM's contact language or site default
4. Extension stores the Mollie payment ID as `trxn_id` on the contribution
5. Extension sets contribution status to **Pending**
6. Donor is redirected to Mollie's hosted payment page
7. Donor completes (or cancels) payment on Mollie
8. Donor is redirected back to CiviCRM
9. Mollie sends webhook → extension processes it (see 3.4)

#### 3.2.2 Return URL Handling

When the donor returns to CiviCRM after the Mollie redirect:
- **Do not trust the return as payment confirmation.** The webhook is authoritative.
- Show the thank you page if the contribution is already Completed (webhook arrived first)
- Show a "processing" message if the contribution is still Pending
- Show a failure/cancellation message if the payment was cancelled or failed

#### 3.2.3 Zero-Amount Handling

If the contribution amount is zero (e.g., free event registration), skip the Mollie payment entirely and mark the contribution as Completed immediately.

### 3.3 Recurring Payments

#### 3.3.1 Recurring Payment Initiation Flow

1. Donor selects "recurring" on the contribution page and chooses frequency
2. CiviCRM calls `doPayment()` with `is_recur = true`
3. Extension flow:

   **Step A — Find or Create Mollie Customer**
   - Look up `MollieCustomer` for this `contact_id` + `payment_processor_id`
   - If not found, create a Mollie customer via `POST /v2/customers` with the contact's name and email, then store the mapping

   **Step B — Create First Payment**
   - Create a Mollie payment with `sequenceType: "first"` and `customerId`
   - Same redirect/webhook flow as one-off, but the first payment establishes a mandate
   - Store the Mollie payment ID as `trxn_id` on the first contribution

   **Step C — On Successful First Payment (in webhook handler)**
   - Verify a valid mandate was created (`GET /v2/customers/{id}/mandates`)
   - Store the mandate ID in a `PaymentToken` record linked to the `ContributionRecur`
   - Create a Mollie subscription via `POST /v2/customers/{id}/subscriptions`:
     - `amount`: from `ContributionRecur.amount`
     - `interval`: mapped from CiviCRM's `frequency_interval` + `frequency_unit` to Mollie's format (e.g., `"1 month"`, `"2 weeks"`)
     - `description`: configurable
     - `webhookUrl`: extension's webhook endpoint
     - `startDate`: next scheduled date per CiviCRM's calculation
     - `times`: from `ContributionRecur.installments` (omit if open-ended)
     - `mandateId`: the mandate from the first payment
     - `metadata`: `{ "contribution_recur_id": <id>, "contact_id": <id> }`
   - Store the Mollie subscription ID in `ContributionRecur.processor_id`
   - Update `ContributionRecur` status to **In Progress**

   **Step D — Subsequent Payments (Mollie-initiated)**
   - Mollie automatically creates payments on the subscription schedule
   - Each payment triggers a webhook
   - Webhook handler creates a new `Contribution` linked to the `ContributionRecur`
   - (See 3.4 for webhook details)

#### 3.3.2 Frequency Mapping

| CiviCRM `frequency_unit` | CiviCRM `frequency_interval` | Mollie `interval` |
|---|---|---|
| `day` | N | `"{N} days"` |
| `week` | N | `"{N} weeks"` |
| `month` | N | `"{N} months"` |
| `year` | 1 | `"12 months"` |

#### 3.3.3 Supported Payment Methods for Recurring

Only payment methods that support mandates can be used for recurring:
- iDEAL (creates SEPA Direct Debit mandate)
- Credit Card
- PayPal
- Bancontact, KBC/CBC, Belfius (all create SEPA DD mandates)

> **Note**: SEPA Direct Debit must be enabled in the Mollie account for iDEAL-based recurring to work, since subsequent charges use SEPA DD.

#### 3.3.4 Installment Limit

If `ContributionRecur.installments` is set (e.g., "12 monthly payments"), pass `times` to the Mollie subscription. When Mollie completes all installments, it marks the subscription as `completed`. The sync job (3.5) picks this up and marks the `ContributionRecur` as **Completed**.

### 3.4 Webhook Handling

#### 3.4.1 Webhook Endpoint

Register a route at `civicrm/payment/ipn/mollie`. This endpoint:
- Receives `POST` with `id=<mollie_payment_id>` (form-encoded)
- Returns `200 OK` within 15 seconds
- Processes the payment inline

#### 3.4.2 Webhook Processing Logic

```
Receive payment ID
  → Fetch full payment from Mollie API: GET /v2/payments/{id}
  → Determine payment type:

  IF payment has NO subscriptionId (one-off or first recurring):
    → Look up Contribution by trxn_id = mollie_payment_id
    → IF payment.status == "paid":
        → Complete contribution (contribution.completetransaction API)
        → Store fee_amount if available from Mollie response
        → IF this was a first recurring payment (sequenceType == "first"):
            → Verify mandate exists and is valid
            → Create Mollie subscription (Step C from 3.3.1)
    → IF payment.status == "failed" or "canceled" or "expired":
        → Mark contribution as Failed or Cancelled

  IF payment HAS subscriptionId (recurring installment):
    → Look up ContributionRecur by processor_id = subscriptionId
    → IF payment.status == "paid":
        → Create new Contribution linked to the ContributionRecur
          (using Contribution.repeattransaction API or direct creation)
        → Set trxn_id = mollie_payment_id
        → Update ContributionRecur.next_sched_contribution_date
    → IF payment.status == "failed":
        → Create a Failed contribution record for audit trail
        → Log the failure reason
        → (Mollie handles retry/cancellation logic per its policies)
```

#### 3.4.3 Idempotency

Mollie may send duplicate webhooks. The handler must be idempotent:
- Check if a contribution with the given `trxn_id` already exists in Completed status
- If so, skip processing and return `200 OK`

#### 3.4.4 Security

- Webhooks carry only a payment ID, no sensitive data
- The handler always fetches the payment from Mollie's API to verify status (never trusts the webhook body alone)
- The webhook endpoint must be accessible without CiviCRM authentication (Mollie calls it server-to-server)
- Validate that the payment belongs to the configured Mollie account

### 3.5 Recurring Contribution Lifecycle Management

#### 3.5.1 Cancellation

When a staff member cancels a recurring contribution in CiviCRM:
- Implement `doCancelRecurring()` on the payment processor
- Call Mollie's `DELETE /v2/customers/{customerId}/subscriptions/{subscriptionId}`
- Update `ContributionRecur` status to **Cancelled**
- Mandate remains valid on Mollie's side (Mollie's policy) — only the subscription is stopped

#### 3.5.2 Amount Change

When a staff member updates the recurring contribution amount:
- Implement `changeSubscriptionAmount()` on the payment processor
- Call Mollie's `PATCH /v2/customers/{customerId}/subscriptions/{subscriptionId}` with updated `amount`
- Update `ContributionRecur.amount`

#### 3.5.3 Status Synchronization (Scheduled Job)

Register a scheduled job (`MollieSync`) that runs daily:
- For each active `ContributionRecur` with a Mollie subscription ID:
  - Fetch subscription status from Mollie (`GET /v2/customers/{id}/subscriptions/{id}`)
  - If Mollie status is `completed` → mark `ContributionRecur` as **Completed**
  - If Mollie status is `canceled` (auto-canceled by Mollie due to payment failures) → mark `ContributionRecur` as **Cancelled**, notify staff
  - If Mollie status is `suspended` (mandate became invalid) → mark `ContributionRecur` as **Failed**, notify staff

This job handles edge cases where Mollie changes subscription state without a webhook (e.g., auto-cancellation after repeated SEPA failures).

### 3.6 Pre-Payment Reminders

#### 3.6.1 Reminder Scheduled Job

Register a scheduled job (`MollieRecurringReminder`) that runs daily:
- Query all active `ContributionRecur` records with Mollie subscriptions
- Calculate the next charge date from `next_sched_contribution_date`
- If the next charge is within the configurable reminder window (default: 7 days) and no reminder has been sent for this cycle:
  - Send a reminder email using a CiviCRM workflow message template
  - Record that the reminder was sent (via CiviCRM Activity)

#### 3.6.2 Reminder Configuration (Extension Settings)

| Setting | Type | Default | Description |
|---|---|---|---|
| `mollie_reminder_enabled` | `boolean` | `false` | Enable/disable pre-payment reminders |
| `mollie_reminder_days_before` | `integer` | `7` | Days before the charge date to send the reminder |

#### 3.6.3 Reminder Email Template

Register a workflow message (`mollie_recurring_reminder`) with a default template that staff can customize in CiviCRM's message template admin (**Mailings → Message Templates → System Workflow Messages**). The template has access to tokens:

- `{contact.display_name}`, `{contact.first_name}`, etc.
- `{contribution_recur.amount}` — recurring amount
- `{contribution_recur.frequency_interval}` + `{contribution_recur.frequency_unit}` — frequency
- `{contribution_recur.next_sched_contribution_date}` — upcoming charge date

Default template content (staff-editable):

```
Subject: Upcoming donation charge

Dear {contact.display_name},

This is a reminder that your recurring donation of {contribution_recur.amount}
will be charged on {contribution_recur.next_sched_contribution_date}.

If you wish to modify or cancel your recurring donation, please contact us.

Thank you for your continued support.
```

### 3.7 Receipts

Contribution receipts are handled by CiviCRM's built-in receipt mechanism:
- When `completeTransaction` is called (via webhook), CiviCRM sends the standard contribution receipt if `is_email_receipt` is enabled on the contribution page
- No custom receipt logic is needed in this extension
- For recurring installments, ensure the `is_email_receipt` flag is propagated from the `ContributionRecur` to each new `Contribution`

### 3.8 Admin Visibility

#### 3.8.1 Payment Metadata Display

Staff need visibility into Mollie-side data when viewing contributions. Extend the contribution view (via SearchKit or hook) to show:

- **Mollie Payment ID** (from `Contribution.trxn_id`)
- **Payment Method** used (iDEAL, credit card, etc.)
- **Fee Amount** (if available from Mollie)

For recurring contributions, also show:
- **Mollie Subscription ID** (from `ContributionRecur.processor_id`)
- **Mollie Customer ID** (from `MollieCustomer` entity)
- **Mandate ID and Status** (from `PaymentToken`)
- **Subscription Status on Mollie** (active, suspended, completed, canceled)

#### 3.8.2 SearchKit Admin Dashboard

Provide a SearchKit-based admin page at `civicrm/admin/mollie` (registered via Afform) with:

1. **Mollie Payments Overview**: A saved search joining `Contribution` with Mollie metadata, showing:
   - Contact name, amount, date, Mollie payment ID, payment method, status
   - Filterable by date range, status, payment method

2. **Recurring Subscriptions Overview**: A saved search on `ContributionRecur` filtered to the Mollie processor, showing:
   - Contact name, amount, frequency, next charge date, Mollie subscription status, mandate type
   - Filterable by status (active, completed, cancelled, failed)
   - Actions: cancel subscription, view on Mollie dashboard (link)

3. **Mollie Customers**: A saved search on `MollieCustomer`, showing:
   - Contact name, Mollie customer ID, number of active subscriptions
   - Useful for debugging customer-level issues

### 3.9 Test Mode

#### 3.9.1 Dual API Key Model

CiviCRM's payment processor configuration natively supports test vs. live instances:
- Live processor uses the Live API Key (`user_name`)
- Test processor uses the Test API Key (`password`)

At runtime, `$this->_paymentProcessor['is_test']` determines which credentials to use. The extension initializes the Mollie SDK client with the appropriate key.

#### 3.9.2 Test Mode Behavior

When using test credentials:
- All Mollie API calls go to the same endpoints but operate in Mollie's test environment
- Test payments can be completed without real money
- Test mode contributions in CiviCRM are flagged with `is_test = 1`
- Webhooks still fire for test payments (Mollie sends them to the configured webhook URL)
- Mollie provides test card numbers and iDEAL bank options for simulating success/failure

#### 3.9.3 Webhook URL in Test Mode

The webhook URL must be reachable from Mollie's servers, even in test mode. For local development, this requires a tunnel service (e.g., ngrok) or a publicly accessible staging server.

### 3.10 Error Handling

#### 3.10.1 API Errors

- All Mollie API calls must be wrapped in try/catch for `\Mollie\Api\Exceptions\ApiException`
- On failure in `doPayment()`: throw `\Civi\Payment\Exception\PaymentProcessorException` with a user-facing message
- Log the full API error (including Mollie's error code and field) to CiviCRM's log

#### 3.10.2 Webhook Errors

- If the webhook handler fails, return `500` so Mollie retries (up to 10 times over ~26 hours)
- If the contribution cannot be found (orphan webhook), log a warning and return `200` (no point in retry)
- If the Mollie API call to fetch payment details fails, return `500` for retry

#### 3.10.3 Logging

Use CiviCRM's PSR-3 logger (`\Civi::log('mollie')`):
- **Info**: Successful payment completions, subscription creations, cancellations
- **Warning**: Webhook for unknown contribution, mandate verification failures, sync mismatches
- **Error**: API exceptions, webhook processing failures

Provide an extension setting to enable verbose/debug logging for troubleshooting.

---

## 4. Non-Functional Requirements

### 4.1 CiviCRM 6 Compliance

| Mixin | Purpose |
|---|---|
| `entity-types-php@2.0` | `MollieCustomer` entity definition |
| `mgd-php@1.0` | Managed entities (processor type, jobs, saved searches) |
| `setting-php@1.0` | Extension settings |
| `menu-xml@1.0` | Route registration (webhook endpoint) |
| `scan-classes@1.0` | Auto-discovery of workflow messages, tokens |

- All API calls use APIv4 syntax (except where CiviCRM core requires APIv3, e.g., `completetransaction`)
- Managed entities use APIv4 format (`'version' => 4`, `'values' => [...]`)

### 4.2 Security

- Never log full API keys; only log last 4 characters for identification
- Webhook endpoint does not require CiviCRM session/authentication
- Webhook handler validates payment ownership by fetching from Mollie's API
- No Mollie credentials stored outside CiviCRM's `payment_processor` table
- Extension does not transmit or store PCI-sensitive card data (all card handling is on Mollie's side)

### 4.3 Performance

- Webhook processing must complete (return 200) within 15 seconds (Mollie's timeout)
- The sync scheduled job should batch API calls and respect Mollie's rate limits
- `MollieCustomer` lookups are indexed on `(contact_id, payment_processor_id)`

### 4.4 Internationalization

- All user-facing strings wrapped in `E::ts()` for translation
- Mollie locale parameter set from CiviCRM contact preferred language where available
- Support for EUR currency (primary), with no hard-coded currency assumptions

### 4.5 Testing

- PHPUnit tests for:
  - Payment processor configuration validation (`checkConfig()`)
  - Frequency mapping logic (CiviCRM → Mollie interval strings)
  - Webhook processing logic (mocked Mollie API responses)
  - Cancellation flow
  - Reminder scheduling logic
- Use Mollie test API keys in CI environments
- Mock the Mollie SDK client for unit tests (avoid live API calls in CI)

---

## 5. Payment Processor Class API

The payment processor class (`CRM_Core_Payment_Mollie extends CRM_Core_Payment`) implements:

| Method | Purpose |
|---|---|
| `checkConfig()` | Validate that API key is configured and non-empty |
| `doPayment()` | Initiate one-off or first recurring payment; redirect to Mollie |
| `handlePaymentNotification()` | Webhook entry point; process Mollie payment status updates |
| `supportsRecurring()` | Return `true` |
| `supportsCancelRecurring()` | Return `true` |
| `supportsEditRecurringContribution()` | Return `true` |
| `getEditableRecurringScheduleFields()` | Return `['amount']` |
| `doCancelRecurring()` | Cancel Mollie subscription; update ContributionRecur |
| `changeSubscriptionAmount()` | Update Mollie subscription amount |
| `getPaymentFormFields()` | Return `[]` (no on-site form; billing_mode=4) |
| `getBillingAddressFields()` | Return `[]` (address collected on Mollie side) |

---

## 6. Managed Entities Summary

| Entity Type | Name | Purpose |
|---|---|---|
| `PaymentProcessorType` | `mollie` | Registers Mollie as a payment processor option |
| `Job` | `MollieSync` | Daily sync of Mollie subscription statuses |
| `Job` | `MollieRecurringReminder` | Daily check for upcoming charges, sends reminder emails |
| `SavedSearch` + `SearchDisplay` | `MolliePayments` | Admin view of Mollie-processed contributions |
| `SavedSearch` + `SearchDisplay` | `MollieSubscriptions` | Admin view of active recurring contributions |
| `SavedSearch` + `SearchDisplay` | `MollieCustomers` | Admin view of Mollie customer mappings |
| `OptionValue` | _(if needed)_ | Activity type for "Reminder Sent" tracking |

---

## 7. Extension Settings

Registered via `settings/mollie.setting.php`:

| Setting | Type | Default | Description |
|---|---|---|---|
| `mollie_payment_description` | `string` | `"Donation #{contribution.id}"` | Template for payment description shown on Mollie/bank statements |
| `mollie_reminder_enabled` | `boolean` | `false` | Enable pre-payment reminder emails |
| `mollie_reminder_days_before` | `integer` | `7` | Days before charge to send reminder |
| `mollie_debug_logging` | `boolean` | `false` | Enable verbose API logging |

---

## 8. Workflow Messages

| Workflow Name | Trigger | Purpose |
|---|---|---|
| `mollie_recurring_reminder` | MollieRecurringReminder scheduled job | Customizable email sent N days before a recurring charge |

Staff can edit the template content via **Mailings → Message Templates → System Workflow Messages** in CiviCRM.

---

## 9. Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Webhook URL unreachable (firewall, DNS, downtime) | Payments complete on Mollie but CiviCRM stays Pending | MollieSync job catches up by checking subscription payment history; alert on prolonged Pending state |
| Mollie auto-cancels subscription after repeated failures | Donor subscription lost without staff awareness | MollieSync job detects `canceled` status and creates CiviCRM activity/notification for staff |
| SEPA Direct Debit not enabled in Mollie account | iDEAL-based recurring fails on second charge | `checkConfig()` or first-use validation warns about SEPA DD requirement |
| Mandate becomes invalid (bank account closed) | Subscription suspended, no charges | MollieSync detects `suspended` status, notifies staff |
| Mollie rate limiting on sync job | Sync fails mid-batch | Implement rate-limit-aware batching with exponential backoff |
| Duplicate webhook delivery | Contribution completed twice or duplicate records | Idempotent webhook handler (check trxn_id existence before processing) |

---

## 10. Out of Scope (initial version)

- **Refunds**: Can be added later via `doRefund()` calling Mollie's Refunds API.
- **Mollie Orders API**: Only the Payments API is used. The Orders API (for e-commerce with line items, shipping) is not needed for donations.
- **Self-service donor portal**: No donor-facing UI for managing recurring donations. Managed by staff or via direct communication.
- **Multi-currency**: EUR only for initial release. The extension should not hard-code EUR, but multi-currency testing is deferred.
- **Mollie Connect / OAuth**: The extension uses API key authentication, not OAuth.

---

## 11. References

- [Mollie API — Payments](https://docs.mollie.com/reference/create-payment)
- [Mollie API — Customers](https://docs.mollie.com/reference/create-customer)
- [Mollie API — Mandates](https://docs.mollie.com/reference/create-mandate)
- [Mollie API — Subscriptions](https://docs.mollie.com/reference/create-subscription)
- [Mollie Recurring Payments Guide](https://docs.mollie.com/docs/recurring-payments)
- [Mollie Webhooks](https://docs.mollie.com/reference/webhooks)
- [Mollie PHP SDK](https://github.com/mollie/mollie-api-php)
- [CiviCRM Developer Guide — Payment Processors](https://docs.civicrm.org/dev/en/latest/extensions/payment-processors/overview/)
- [CiviCRM Developer Guide — Entity Schema Definition](https://docs.civicrm.org/dev/en/latest/framework/entities/schema-definition/)
- [CiviCRM Developer Guide — Workflow Messages](https://docs.civicrm.org/dev/en/latest/framework/message/)
- [CiviCRM Developer Guide — Tokens](https://docs.civicrm.org/dev/en/latest/framework/token/)
- [CiviCRM Developer Guide — SearchKit](https://docs.civicrm.org/dev/en/latest/searchkit/overview/)
- [CiviCRM Developer Guide — Scheduled Jobs](https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/)
