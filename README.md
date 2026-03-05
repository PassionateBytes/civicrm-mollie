# Mollie Payment Processor for CiviCRM

A CiviCRM extension (`nl.stichtinggast.mollie`) for processing one-off and recurring donations through [Mollie](https://mollie.com). Built for [Stichting GAST](https://www.stichtinggast.nl), a volunteer-run NGO in Nijmegen, Netherlands.

## Requirements

- CiviCRM >= 6.0
- PHP >= 8.1
- A Mollie account with API keys

## Features

- **One-off payments** via Mollie's hosted checkout (redirect-based)
- **Recurring donations** via Mollie Customers, Mandates, and Subscriptions APIs
- **Webhook-driven** status synchronization — payment state is always verified against Mollie's API
- **Mollie-managed recurring** — Mollie handles scheduling, retries, and charging
- **Pre-payment reminders** — configurable email notifications before upcoming recurring charges
- **Admin dashboard** — SearchKit-based overview of payments, subscriptions, and customers
- **Daily sync job** — catches subscription status changes that webhooks may miss
- **Test mode** — full support for Mollie test API keys

## Installation

1. Copy or clone this extension into your CiviCRM extensions directory.
2. Run `composer install` in the extension directory to install the Mollie PHP SDK.
3. Enable the extension in CiviCRM (**Administer > System Settings > Extensions**).
4. Configure a Mollie payment processor instance (**Administer > CiviContribute > Payment Processors**) with your Mollie API key.

## Configuration

Extension settings are available at **Administer > CiviContribute > Mollie Settings** (`civicrm/admin/mollie/settings`):

| Setting | Default | Description |
|---------|---------|-------------|
| Payment Description | `Donation #{contribution.id}` | Template for bank statement descriptions |
| Enable Pre-Payment Reminders | Off | Send emails before recurring charges |
| Reminder Days Before Charge | 7 | How many days before the charge to send the reminder |
| Enable Debug Logging | Off | Verbose Mollie API logging (disable in production) |

## Permission Model

This extension uses standard CiviCRM permissions — no custom permissions are defined. The table below shows which permissions are required for each component.

### Admin UI

| Component | Permission | Where Enforced |
|-----------|------------|----------------|
| Payment Dashboard (`civicrm/admin/mollie`) | `access CiviContribute` | Afform, Navigation |
| Settings Form (`civicrm/admin/mollie/settings`) | `administer payment processors` | Menu XML, Navigation |

### MollieCustomer API (APIv4)

| Action | Permission |
|--------|------------|
| `get` | `access CiviContribute` |
| `meta` | `access CiviCRM` |
| `create`, `update`, `delete` | `administer payment processors` |

### MollieCustomer Entity (Schema-Level)

Access requires either `access CiviContribute` **or** `administer CiviCRM`.

### Search Displays

All three search displays (Payments, Subscriptions, Customers) have `acl_bypass` set to `FALSE` — they respect CiviCRM's standard contact and contribution ACLs.

### Webhook Endpoint

The webhook endpoint (`civicrm/payment/ipn/mollie`) is unauthenticated by design. Mollie sends payment IDs via server-to-server POST; the handler always verifies payment status by fetching the full payment object from Mollie's API.

### Scheduled Jobs

The two scheduled jobs (`MollieSync` and `MollieRecurringReminder`) run under CiviCRM's system job runner with no additional permission requirements.

## Architecture

See [REQUIREMENTS.md](REQUIREMENTS.md) for the full requirements and architecture document.

### Key Design Decisions

- **Mollie PHP SDK directly** — no Omnipay abstraction. Omnipay's token-based recurring model doesn't fit Mollie's Customer/Mandate/Subscription flow.
- **Mollie-managed recurring** — Mollie's Subscriptions API handles scheduling and charging. CiviCRM does not trigger individual recurring payments.
- **Single custom entity** (`MollieCustomer`) — maps CiviCRM contacts to Mollie customer IDs. Other Mollie references use standard CiviCRM fields (`ContributionRecur.processor_id`, `PaymentToken`, `Contribution.trxn_id`).
- **Webhook-driven** — payment status is always determined by Mollie webhook + API verification, never by redirect return.

## License

AGPL-3.0 — see [LICENSE](http://www.gnu.org/licenses/agpl-3.0.html).

## Maintainer

Stichting GAST — [ict@stichtinggast.nl](mailto:ict@stichtinggast.nl)
