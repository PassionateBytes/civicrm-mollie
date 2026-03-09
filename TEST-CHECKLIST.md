# Manual Testing Checklist

Testing checklist for the CiviCRM Mollie payment processor extension.
Use Mollie's test mode for all tests unless noted otherwise.

## Prerequisites

- [x] Extension is installed and enabled in CiviCRM
- [x] Mollie payment processor configured with valid **test** API key
- [x] Mollie webhook URL is reachable from the internet (or use ngrok/similar)
- [x] Mollie dashboard open for verifying transactions

---

## 1. Extension Setup

### Installation & Configuration

- [x] Extension installs without errors (`cv ext:install nl.stichtinggast.mollie`)
- [x] Managed entities created: payment processor type, scheduled jobs, activity types, saved searches, navigation menu
- [x] Mollie settings page loads (`/civicrm/admin/mollie/settings`)
- [x] Payment description template can be saved and retrieved
- [x] Debug logging toggle works (check CiviCRM log channel `mollie`)
- [x] Reminder settings (enabled, days before) can be saved

### Payment Processor Config

- [x] Payment processor validates correctly with a valid test key
- [x] Validation error shown when API key is empty
- [x] Validation error shown when test key used in live mode (or vice versa)

---

## 2. One-Off Payments

### Successful Payment

- [x] Contribution form shows Mollie as payment option
- [x] Submitting redirects to Mollie checkout page
- [x] Contribution in CiviCRM has status **Pending** and `trxn_id` set before redirect
- [x] Completing payment on Mollie redirects back to CiviCRM thank-you page
- [x] Webhook fires and contribution status changes to **Completed**
- [x] `trxn_id` matches Mollie payment ID (format: `tr_...`)
- [ ] Fee amount recorded (if settlement data available)
- [x] Financial records (FinancialTrxn, FinancialItem) created correctly
- [x] Contribution receipt email sent (if configured)

### Failed/Cancelled/Expired Payment

- [x] Cancelling payment on Mollie checkout triggers webhook
- [x] Contribution status changes to **Cancelled**
- [x] Letting payment expire triggers webhook, status changes to **Failed**
- [x] `cancel_date` is set on the contribution

### Idempotency

- [x] Re-sending the same webhook does not create duplicate records
- [x] Already-completed contribution is skipped gracefully

---

## 3. Recurring Payments — First Payment

### Successful First Payment

- [x] Recurring contribution form submits and redirects to Mollie
- [x] Payment created with `sequenceType: first` (verify in Mollie dashboard)
- [x] MollieCustomer record created (or existing one reused)
- [x] After payment: mandate created on Mollie customer
- [x] PaymentToken record created in CiviCRM with mandate ID
- [x] Mollie subscription created with correct amount, interval, and description
- [x] ContributionRecur updated with `processor_id` (format: `sub_...`) and status **In Progress**
- [x] `next_sched_contribution_date` set on the recurring contribution
- [x] First contribution marked **Completed**

### Failed First Payment

- [x] Failing the first payment triggers webhook
- [x] First contribution marked **Failed** (or **Cancelled**)
- [x] ContributionRecur marked **Failed** with `cancel_date` and `end_date` set
- [x] No mandate or subscription created on Mollie
- [x] `next_sched_contribution_date` cleared

### Single Installment (installments = 1)

- [x] First payment completes and ContributionRecur marked **Completed** (no subscription created)

---

## 4. Recurring Payments — Subsequent Installments

> **Deferred to live.** Mollie does not auto-trigger subscription payments in test mode, and manually created recurring payments lack the `subscriptionId` field needed to route through the correct code path. Verified against Mollie API docs that `subscriptionId` is present on all subscription-generated payments in live mode.

### Successful Installment

- [ ] Mollie sends webhook for automatic recurring charge
- [ ] New contribution created via `repeattransaction` + `Payment.create`
- [ ] Contribution has correct amount, status **Completed**, and unique `trxn_id`
- [ ] Fee amount recorded
- [ ] Line items, financial type, and soft credits cloned from original contribution
- [ ] `next_sched_contribution_date` updated on ContributionRecur

### Failed Installment

- [ ] Failed recurring charge triggers webhook
- [ ] New contribution created with status **Failed**
- [ ] `failure_count` incremented on ContributionRecur
- [ ] ContributionRecur stays **In Progress** (Mollie will retry)

### Idempotency

- [ ] Duplicate webhook for same recurring payment ID is skipped

---

## 5. Chargebacks

- [ ] Chargeback on a one-off payment: contribution status changes to **Chargeback**
- [ ] Chargeback note attached to contribution with amount, date, and reason
- [ ] Chargeback on a recurring installment: same behavior
- [ ] Already-chargebacked contribution is not re-processed

---

## 6. Subscription Lifecycle

### Cancel Subscription

- [x] Cancelling via CiviCRM UI calls Mollie API and cancels subscription
- [x] ContributionRecur status changes to **Cancelled**
- [x] Success message shown to user
- [x] Cancelling a subscription without Mollie customer shows appropriate error

### Change Amount

- [x] Editing recurring contribution amount updates subscription on Mollie
- [x] New amount reflected in Mollie dashboard
- [x] Success message shown to user

---

## 7. Dashboard

- [x] Dashboard accessible via CiviCRM navigation menu
- [x] **Payments tab**: shows contributions with `trxn_id` matching Mollie format
- [x] Payment IDs link to Mollie dashboard
- [x] Contact names link to CiviCRM contact view
- [x] **Subscriptions tab**: shows recurring contributions with Mollie processor IDs
- [x] Subscription IDs link to Mollie customer page
- [x] Reminder sent date and link shown (if applicable)
- [x] Status styling: cancelled/failed/completed rows are dimmed
- [x] **Customers tab**: shows MollieCustomer records with active subscription count
- [x] Customer IDs link to Mollie dashboard
- [x] Test record filter checkbox works on all three tabs
- [x] Test records highlighted with warning style

---

## 8. Scheduled Jobs

### Mollie Subscription Sync (`MollieSync.run`)

- [x] Job appears in CiviCRM Scheduled Jobs list
- [x] Running the job syncs subscription statuses from Mollie
- [x] Detects auto-cancelled subscriptions (update CiviCRM to Cancelled)
- [ ] Detects completed subscriptions (update CiviCRM to Completed)
- [ ] Detects suspended subscriptions
- [x] Syncs `next_sched_contribution_date` and `amount` changes
- [x] Retries Mollie cancellation for locally-cancelled subscriptions that failed to reach Mollie
- [ ] Handles Mollie API rate limiting (429 retry with backoff)

### Mollie Recurring Reminder (`MollieRecurringReminder.run`)

- [x] Job appears in Scheduled Jobs (disabled by default)
- [x] Does nothing when reminders are disabled in settings
- [x] When enabled: sends reminder emails within the configured window (tested via redirect-to-database)
- [x] Does not send duplicate reminders for the same charge date
- [x] Creates Activity record for each sent reminder

---

## 9. Unmatched Webhook Handling

- [x] Webhook for unknown `trxn_id` (one-off): Activity created with payment details
- [ ] Webhook for unknown `subscriptionId` (recurring): Activity created with subscription details
- [x] Activity includes Mollie dashboard links for payment and customer
- [x] Activity type is `mollie_unmatched_payment` with warning icon
- [x] Contact resolved from payment metadata or MollieCustomer lookup
- [x] Falls back to domain contact if no contact can be resolved

---

## 10. Edge Cases

- [x] Zero-amount contribution completes immediately (no Mollie redirect)
- [x] Webhook received before donor returns from Mollie (race condition handled — webhook is authoritative, return URL only shows thank-you page)
- [x] Multiple Mollie payment processors (live + test) don't interfere — each processor uses its own API key
- [x] Extension disable/re-enable preserves data
- [x] Mollie API errors logged and handled gracefully (no PHP fatals)
- [x] Locale passed to Mollie based on contact's preferred language
