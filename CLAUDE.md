# CiviCRM Mollie Payment Processor Extension

## Project Overview

A CiviCRM extension (`nl.stichtinggast.mollie`) for processing one-off and recurring donations through Mollie. Built for Stichting GAST, a volunteer-run NGO in Nijmegen, Netherlands that supports undocumented refugees.

See `REQUIREMENTS.md` for the full requirements document.

## Organization Context

- **Organization**: Stichting GAST (https://www.stichtinggast.nl)
- **Maintainer**: `ict@stichtinggast.nl`
- **CiviCRM instance**: Hosted on greenhost.nl VPS
- **Payment provider**: Mollie (https://mollie.com)

## Technical Stack

- **CiviCRM**: >= 6.0
- **PHP**: >= 8.1
- **Mollie SDK**: `mollie/mollie-api-php` (official PHP SDK, NOT omnipay)
- **Extension key**: `nl.stichtinggast.mollie`

## Architecture Decisions

- **Mollie PHP SDK directly** — no Omnipay abstraction. Omnipay's token-based recurring model doesn't fit Mollie's Customer → Mandate → Subscription flow.
- **Mollie-managed recurring** — Mollie's Subscriptions API handles scheduling and charging. CiviCRM does not trigger individual recurring payments via cron.
- **Single custom entity** (`MollieCustomer`) — maps CiviCRM contacts to Mollie customer IDs. All other Mollie references (subscription ID, mandate ID, payment ID) are stored in standard CiviCRM fields (`ContributionRecur.processor_id`, `PaymentToken`, `Contribution.trxn_id`).
- **Webhook-driven** — payment status is always determined by Mollie webhook + API verification, never by redirect return.

## CiviCRM Extension Conventions

- Use CiviCRM 6 patterns: APIv4, managed entities (v4 format), entity-types-php mixin, SearchKit/Afform for admin UIs
- Payment processor class: `CRM_Core_Payment_Mollie extends CRM_Core_Payment`
- Mixins: `entity-types-php@2.0`, `mgd-php@1.0`, `setting-php@1.0`, `menu-xml@1.0`, `scan-classes@1.0`
- Translation: wrap user-facing strings in `E::ts()`
- Logging: `\Civi::log('mollie')` with PSR-3 levels
- Generate scaffolding with `civix` where applicable

## Key File Locations

- Payment processor class: `CRM/Core/Payment/Mollie.php`
- Custom entity schema: `schema/MollieCustomer.entityType.php`
- Managed entities: `managed/*.mgd.php`
- Extension settings: `settings/mollie.setting.php`
- Webhook route: `xml/Menu/mollie.xml`
- Admin UI (Afform): `ang/MolliePaymentDashboard.aff.*`
- Hook implementations: `mollie.php`

## Reference Material

- The parent directory (`~/Projects/stichting-gast/`) contains other CiviCRM extensions and infrastructure configs
- The omnipay payment processor reference is at `~/Projects/stichting-gast/.references/omnipaypaymentprocessor/` — useful for understanding CiviCRM's payment processor interface, but this extension intentionally diverges from its Omnipay-based approach
- CiviCRM core may be available at `~/Projects/stichting-gast/.references/civicrm-core/` for looking up base class APIs

## Mollie API Quick Reference

- Payments API: `POST /v2/payments` (one-off and first recurring)
- Customers API: `POST /v2/customers` (required for recurring)
- Mandates API: `GET /v2/customers/{id}/mandates` (verify after first payment)
- Subscriptions API: `POST /v2/customers/{id}/subscriptions` (Mollie-managed recurring)
- Webhooks: Mollie POSTs `id=<payment_id>` — always fetch full payment to verify status
- Recurring flow: Customer → First payment (`sequenceType: "first"`) → Mandate auto-created → Subscription → Mollie charges automatically (`sequenceType: "recurring"`)
