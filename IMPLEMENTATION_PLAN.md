# Implementation Plan — CiviCRM Mollie Payment Processor Extension

## Decisions & Notes

- **Webhook URL**: `civicrm/payment/ipn/mollie/{processor_id}` — processor_id embedded in URL for API key resolution
- **Scaffolding**: Manual creation, no civix dependency
- **Managed entities**: APIv4 format (`'version' => 4, 'values' => [...]`)
- **API calls**: APIv4 everywhere except `Contribution.completetransaction` and `Contribution.repeattransaction` (v3 only in CiviCRM 6)
- **Recurring**: Mollie-managed via Subscriptions API; CiviCRM does NOT trigger recurring charges

---

## Stage 1: Extension Scaffolding & Foundation

**Goal**: Bare-minimum installable extension that CiviCRM recognizes.

**Files**:
- `info.xml` — extension manifest with mixins, classloader, compatibility
- `mollie.civix.php` — auto-load utilities (ExtensionUtil class, ts helper)
- `mollie.php` — hook implementations (config, install, enable, composer autoload)
- `composer.json` — declares `mollie/mollie-api-php` dependency
- `.gitignore` — vendor/, composer.lock

**Commit**: `feat: extension scaffolding and foundation`

---

## Stage 2: Payment Processor Type & Settings

**Goal**: Mollie appears as a payment processor option in CiviCRM admin. Extension settings registered.

**Files**:
- `managed/PaymentProcessorType.mgd.php` — registers Mollie processor type (billing_mode=4, is_recur=true)
- `settings/mollie.setting.php` — extension settings (payment_description, reminder_enabled, reminder_days, debug_logging)
- `CRM/Core/Payment/Mollie.php` — skeleton payment processor class:
  - Constructor, `checkConfig()`, `getMollieApiClient()` (SDK initialization)
  - Capability methods: `supportsRecurring()`, `supportsCancelRecurring()`, `supportsEditRecurringContribution()`, `getEditableRecurringScheduleFields()`, `getPaymentFormFields()`, `getBillingAddressFields()`

**Commit**: `feat: payment processor type registration and settings`

---

## Stage 3: One-Off Payment Flow & Webhook

**Goal**: Complete one-off payment lifecycle — initiation, Mollie redirect, webhook processing, status updates.

**Files**:
- `xml/Menu/mollie.xml` — webhook route `civicrm/payment/ipn/mollie/{processor_id}`
- `CRM/Core/Payment/Mollie.php` — extend with:
  - `doPayment()` — one-off flow (create Mollie payment, set Pending, redirect)
  - `handlePaymentNotification()` — webhook entry point
  - Private helpers: `processOneOffPayment()`, `getWebhookUrl()`, `getReturnUrl()`
  - Zero-amount handling
  - Error handling with `PaymentProcessorException`

**Commit**: `feat: one-off payment flow with webhook handling`

---

## Stage 4: MollieCustomer Entity

**Goal**: Custom entity mapping CiviCRM contacts to Mollie customer IDs, exposed via APIv4.

**Files**:
- `schema/MollieCustomer.entityType.php` — entity schema (contact_id FK, payment_processor_id FK, mollie_customer_id, created_date)
- `Civi/Api4/MollieCustomer.php` — APIv4 entity class (auto-generated CRUD via entity-types-php mixin)

**Commit**: `feat: MollieCustomer entity for contact-to-customer mapping`

---

## Stage 5: Recurring Payment Flow

**Goal**: Full recurring lifecycle — first payment with mandate, subscription creation, subsequent payment handling.

**Files**:
- `CRM/Core/Payment/Mollie.php` — extend with:
  - `doPayment()` recurring branch (find/create Mollie customer, first payment with sequenceType=first)
  - Webhook: mandate verification, Mollie subscription creation (Step C from requirements)
  - Webhook: subsequent recurring payments (create Contribution via repeattransaction)
  - Frequency mapping helper (CiviCRM → Mollie interval format)
  - PaymentToken creation for mandate storage

**Commit**: `feat: recurring payment flow with Mollie subscriptions`

---

## Stage 6: Recurring Lifecycle Management

**Goal**: Cancellation, amount changes, and daily status sync with Mollie.

**Files**:
- `CRM/Core/Payment/Mollie.php` — extend with:
  - `cancelSubscription()` — cancel Mollie subscription via API
  - `changeSubscriptionAmount()` — patch Mollie subscription amount
- `managed/ScheduledJob.mgd.php` — MollieSync scheduled job
- `Civi/Mollie/Api4/MollieSync.php` — sync job implementation (fetch subscription statuses, update ContributionRecur)

**Commit**: `feat: recurring lifecycle management and status sync job`

---

## Stage 7: Pre-Payment Reminders

**Goal**: Configurable reminder emails before upcoming recurring charges.

**Files**:
- `managed/ScheduledJob.mgd.php` — add MollieRecurringReminder job
- `managed/OptionValues.mgd.php` — Activity type for "Mollie Reminder Sent"
- `Civi/Mollie/Api4/MollieRecurringReminder.php` — reminder job logic
- `Civi/Mollie/WorkflowMessage/RecurringReminder.php` — workflow message definition
- `templates/CRM/Mollie/RecurringReminder.tpl` — default email template (if needed beyond workflow message)

**Commit**: `feat: pre-payment reminder emails for recurring donations`

---

## Stage 8: Admin UI (SearchKit/Afform)

**Goal**: Admin dashboard with Mollie payment, subscription, and customer overviews.

**Files**:
- `managed/SavedSearches.mgd.php` — SearchKit saved searches + displays:
  - MolliePayments: Contribution + Mollie metadata
  - MollieSubscriptions: ContributionRecur filtered to Mollie processor
  - MollieCustomers: MollieCustomer entity overview
- `ang/MolliePaymentDashboard.aff.html` — Afform page layout
- `ang/MolliePaymentDashboard.aff.json` — Afform route config (`civicrm/admin/mollie`)

**Commit**: `feat: SearchKit admin dashboard for Mollie payments`

---

## Implementation Reminders

- Wrap all user-facing strings in `E::ts()`
- Use `\Civi::log('mollie')` with PSR-3 levels (info/warning/error)
- Never log full API keys — only last 4 chars
- Webhook handler must be idempotent (check trxn_id existence)
- Webhook must return 200 within 15 seconds
- All Mollie API calls in try/catch for `\Mollie\Api\Exceptions\ApiException`
- PaymentToken stores mandate ID; ContributionRecur.processor_id stores subscription ID
- `is_test` flag determines live vs test API key at runtime
