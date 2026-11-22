# Shopee Affiliate Link Tool (prototype)

Prototype tool that generates Shopee Affiliate links for **my own** affiliate account and tracks the resulting orders per user. It is the first piece of the affiliate module of a larger platform.

> **Status: work in progress / early prototype.** Runs in TEST mode only. Not production software.

*Part of a larger AI travel planning + affiliate platform currently in development.*

## What works now

Based on the code in this repo:

- **Telegram bot** (`public/webhook.php`): a user pastes a Shopee product link (including `s.shopee.vn` / `shope.ee` short links) and gets back a tracked link. Each link carries its own tracking token (`subId`).
- **Web dashboard** (`public/`): Telegram Login, plus an optional guest mode with device-local data that is merged into the account after login. It lists links, orders, wallet balance and notifications, and has a light/dark theme.
- **Admin panel**: approve users, approve/reject withdrawal requests, feature flags, and a link-creation test button.
- **Accounting**: per-user ledger with database transactions, configurable customer share, withdrawal requests.
- **Outbox + cron worker** (`private/worker.php`): queued Telegram/Zalo messages, cleanup, relay health alerts.
- **Zalo OA channel** (optional, `public/zalo_webhook.php`).
- **Tests**: `tests.php` for database-level tests (run against a separate TEST database); `tests_ui.php` and `link-relay-extension/test/` for offline tests.

## In progress

- **Reliable link creation.** Three link modes exist in code:
  - `custom_link`: server-side call to the affiliate dashboard API. Currently **rejected by Shopee (HTTP 403)**, so it does not work.
  - `relay`: a small Chrome extension (`link-relay-extension/`) creates the link inside my own logged-in affiliate tab. It has a durable server-side queue, so requests wait instead of failing. Built and unit-tested, not yet proven in daily use.
  - `open_api`: Shopee Affiliate Open API client. Written and unit-tested against mocked responses; **waiting for official API credentials**. The request signature format must still be checked against Shopee's docs.
- **Order/commission import.** Conversion handling works with TEST events (`private/import_test.php`). The automatic sync from the affiliate report is not verified against real data yet.

## packages/affiliate (in progress)

TypeScript package (pnpm workspace, zod, vitest). It is the start of the platform's `packages/affiliate`:

- `src/schema.ts`: zod `Offer` schema with `offerId`, `partner`, `deeplink` (https only), `geo {lat, lng}`, `priceVnd` (≥ 0), `currency`, `lastVerifiedAt`.
- `src/registry.ts`: in-memory registry with `addOffer`, `getOffer(offerId)`, `listOffersNear(lat, lng, radiusKm)`. Postgres comes later.
- `src/resolver.ts`: `resolveItinerary(json)` maps each `offer_id` to its deeplink from the registry. Unknown IDs are dropped and logged. The output is built from scratch, so **no raw URL from the input (e.g. LLM output) can reach it**.

```bash
pnpm install
pnpm typecheck
pnpm test
```

CI (`.github/workflows/ci.yml`) runs the same three steps on every push to `main` and on pull requests.

## Roadmap

- Offer registry: Postgres storage (in-memory version done in `packages/affiliate`)
- Partner sync workers (Agoda, Klook, Booking, GetYourGuide, ...)
- Nightly dead-link checker
- Click tracking
- Integration into a Turborepo monorepo as `packages/affiliate`

## Tech stack

- PHP 8 (no framework), PDO + MySQL/MariaDB, cURL
- Plain HTML/CSS/JS for the web UI
- Telegram Bot API, Zalo Official Account API
- Chrome extension (Manifest V3, plain JavaScript) for the relay mode
- Designed for ordinary shared hosting (cPanel) + one cron job

## Setup

Configuration lives in a PHP file, not `.env`.

1. Create a MySQL database. Upload `shopee-bot-v05/public/` to the web root and `shopee-bot-v05/private/` to a folder **outside** the web root.
2. Copy `private/config.example.php` to `private/config.php` and fill in your own values. These include the bot token, database credentials, admin IDs and `link_mode`. Never commit `config.php`.
3. Run `php private/setup.php` once to create the tables.
4. Set the Telegram webhook to `https://<your-domain>/webhook.php`.
5. Add a cron job: `* * * * * php /path/to/private/worker.php`.
6. Optional, for relay mode: load `link-relay-extension/` as an unpacked extension and set the same `relay_key` in the extension and in `config.php` (see its README).
7. Tests: `php tests.php` (TEST database only), `php tests_ui.php`, and `node link-relay-extension/test/relay-main.test.js link-relay-extension/relay-main.js`.

More detailed (Vietnamese) deployment notes are in `shopee-bot-v05/DEPLOY.md`.
