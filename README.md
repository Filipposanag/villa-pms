# Villa PMS

A self-contained **WordPress booking plugin** for a single short-stay property (villa / apartment). It handles direct online bookings with **Stripe** card payments, keeps availability in sync with **Booking.com** and **Airbnb** over **iCal**, manages refunds, per-day pricing and guest emails — without relying on an external channel manager or SaaS.

Built from scratch in plain PHP against the WordPress plugin APIs. Stripe secrets are stored in WordPress options and are never committed to the code.

> Used in production by [isleofolive.gr](https://isleofolive.gr) (Isle of Olive, Corfu).

---

## Features

**Bookings & payments**
- Front-end booking calendar via the `[villa_booking]` shortcode
- Stripe Checkout (cards + Apple/Google Pay), with **server-side verification** of session, booking id, exact amount in cents and currency before a booking is ever marked paid
- Mandatory Stripe **webhook signature verification** — unsigned events are rejected (no booking is marked paid from an unverified event)
- Short, self-expiring holds for pending bookings (~40 min) with a grace window so slightly delayed webhooks still confirm instead of losing the dates
- Rate-limited public booking endpoint (10 attempts / 15 min), with no readable IP storage

**Refunds**
- Full refunds through the Stripe Refund API with a **per-attempt idempotency key**
- Refund state tracked **separately** from booking status (`pending` / `requires_action` / `processing` / `refunded` / `failed`), reconciled hourly against Stripe
- An unknown or empty refund status is never treated as success; DB-write failures surface as explicit reconciliation warnings instead of silent success

**Channel sync (iCal)**
- Two separate export feeds, each with its own secret token: the Airbnb feed includes Booking.com reservations and vice-versa, so a booking on one platform blocks the other
- No sync loop: the plugin's own events carry a `vpms-` UID prefix and are ignored on import
- Sync every 15 minutes (or on demand); per-feed status, last success/error and event counts shown in admin

**Pricing, email & privacy**
- Per-day pricing overrides on top of a default nightly rate, min/max nights, max guests, cleaning fee, currency
- Guest and owner emails (confirmation, arrival, thank-you) from a configurable sender
- GDPR: server-side privacy-consent capture, per-booking data erasure/anonymisation, and integration with WordPress Export/Erase Personal Data; iCal feeds and logs never contain personal data

---

## Tech

| | |
|---|---|
| Platform | WordPress plugin, plain PHP (requires PHP 8.1+) |
| Payments | Stripe Checkout + Refund API, signed webhooks |
| Sync | iCal export/import, WP-Cron (or real server cron) |
| Storage | Custom tables via `dbDelta`, idempotent activation/upgrade migrations |
| Data access | REST endpoints (`vpms/v1`), nonces, server-side validation throughout |

## Structure

```
villa-pms/
├── villa-pms.php        # Bootstrap: constants, includes, activation/upgrade
├── includes/
│   ├── db.php           # Schema & migrations (dbDelta)
│   ├── frontend.php     # [villa_booking] shortcode & booking UI
│   ├── rest.php         # REST endpoints
│   ├── stripe.php       # Checkout, webhooks, refunds
│   ├── ical.php         # Two-feed export/import sync
│   ├── emails.php       # Guest & owner notifications
│   ├── stats.php        # Admin statistics
│   ├── admin.php        # Settings & bookings screens
│   └── privacy.php      # GDPR export/erase
├── CHANGELOG.md
└── INSTALL.el.md        # Full setup & operations guide (Greek)
```

## Installation

WordPress admin → Plugins → Add New → Upload Plugin → upload the plugin folder as a zip and activate. Full setup (Stripe keys, webhook endpoint, iCal feeds, pricing) is documented in **[INSTALL.el.md](INSTALL.el.md)**.

## License

© 2026 Filippos Anagnostopoulos. All rights reserved.
