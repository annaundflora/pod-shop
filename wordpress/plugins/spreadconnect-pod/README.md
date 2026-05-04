# Spreadconnect POD

Print-on-demand fulfillment integration for WooCommerce via the Spreadconnect Fulfillment API v2.3.9. Headless-friendly WordPress plugin that ships full coverage of all 27 REST endpoints + 7 webhook events.

## Overview

`spreadconnect-pod` connects a WooCommerce store to a Spreadconnect (Spreadshirt) point-of-sale account so every WooCommerce order is mirrored as a Spreadconnect order, every Spreadconnect article is mirrored as a draft / published WooCommerce product, and every operational event (order processed, shipment sent, article updated, …) is reconciled by an inbound webhook. The plugin is German-localised by default (`spreadconnect-pod-de_DE.po`) but English is the source language of every UI string.

The plugin's runtime guarantees: HPOS-aware order persistence, Action-Scheduler-driven retries (1m / 5m / 15m), HMAC-verified webhook intake (8-second-ACK), Failed-Ops queue with admin-notice integration, and a single `manage_woocommerce`-gated Hub page that surfaces every operational metric (Connection / Catalog / Orders / Webhooks / Failed Ops).

## Setup

Setup is a **5-step pipeline**; every step links into the architecture spec for the underlying contracts. Run the steps in order on a fresh install — they are idempotent on re-run.

1. **`composer install`** — From the plugin directory, install PHP runtime dependencies (PSR-4 autoload root `SpreadconnectPod\\` → `includes/`). See [Bootstrap layer](../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md#architecture-layers).
2. **Activate the plugin in WP-Admin** — Plugins → "Spreadconnect POD" → Activate. Activation declares HPOS compatibility and runs the schema `dbDelta` for the four custom tables. See [Database Schema](../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md#database-schema).
3. **Settings → API Key + Test Connection** — Open Spreadconnect → Settings, paste the API key, click "Test This Key" to verify against `GET /authentication`, then "Save Changes" to persist. See [API Design — Outbound](../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md#api-design).
4. **Copy the One-Time Webhook Secret** — On the first save with a valid key, the Settings page reveals the freshly generated webhook secret **once**. Copy it immediately to a password manager — re-display is impossible (only `regenerate` rotates it). See [Security](../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md#security).
5. **Trigger the initial Catalog Sync** — Hub → Catalog → "Sync now". This enqueues a `spreadconnect/sync_catalog` Action-Scheduler job; progress is visible inline (live log + counts). See [Server Logic — Catalog Sync](../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md#server-logic).

A successful run leaves you with: a green Connection card, a non-zero "Linked products" count, all 7 webhook subscriptions registered, and zero entries in the Failed-Ops queue.

## Features

The plugin is organised into 10 implementation slices (Discovery slice numbering — see [discovery.md](../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/discovery.md) for the full narrative):

- **Plugin Foundation** — Bootstrap, HPOS-declare, custom-table schema (`dbDelta`), text-domain loader, default options, uninstall cleanup.
- **API Client + Authentication** — `SpreadconnectClient` over WP HTTP API with Bearer auth, single 429 retry, fail-fast on 4xx, `SpreadconnectTransientError` re-thrown for Action-Scheduler retry.
- **Webhook Receiver + Subscriptions** — `/wp-json/spreadconnect/v1/webhook` REST route with HMAC verification, deterministic event-id hashing, `SubscriptionManager` diff-and-repair against the 7 expected events.
- **Catalog-Sync** — Per-article `sync_article` jobs, image sideload, attribute provisioning (`pa_groesse` / `pa_farbe`), sync-history aggregate counters, idempotent re-runs.
- **Order-Lifecycle** — `OrderStateMachine` compare-and-set on `_spreadconnect_state`, submit / confirm / cancel-mirror jobs, auto-confirm timer, shipment-sent → tracking persist.
- **Stock-Sync** — Hybrid pull: 5-minute live cache + recurring `scheduled_stock_sync` interval (settings-configurable).
- **Hub-Page + Settings** — Single `manage_woocommerce`-gated admin page with Dashboard / Catalog / Orders / Webhooks / Failed-Ops / Subscriptions / Logs / Settings sub-sections; inline Test-Connection AJAX; settings export/import JSON.
- **Inline UX** — Product-edit meta-box (search & link, cost / margin, stock), product-list columns, order-edit meta-box (state, shipping-type select, confirm/cancel actions), order-list bulk re-send.
- **Failure-Recovery** — `FailedOpsRepo` queue with three resolution paths (resolve / dismiss / external-id capture), bulk re-send coordinator, `AdminNoticeStore` with severity-aware persistent notices, e-mail notifier.
- **Logs + Polish** — `WcLoggerAdapter` over `wc_get_logger()` with whitelisted sources, daily `purge_old_logs` retention job, German `de_DE.po`, README.

## Architecture

The plugin follows a 5-layer architecture; see [Full Architecture](../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md) for schema diagrams, data-flow charts, and the complete service map.

- **Bootstrap** — Plugin activation / deactivation / uninstall, HPOS declare, hook registration. Example: `SpreadconnectPod\Bootstrap\Plugin`.
- **Domain** — Pure functions and value objects (no I/O, no `$wpdb`). Example: `SpreadconnectPod\Order\OrderStateMachine`.
- **Application** — Action-Scheduler job handlers and multi-step coordinators (idempotent, retry-safe). Example: `SpreadconnectPod\Subscription\SubscriptionManager`.
- **Infrastructure** — HTTP client, persistence repositories, logging adapter, transient cache. Example: `SpreadconnectPod\Api\SpreadconnectClient`.
- **Adapter** — REST routes (webhook receiver, sync-progress), admin pages and meta-boxes, AJAX handlers, list-column hooks. Example: `SpreadconnectPod\Hub\View\Dashboard`.

## Development

The plugin's tests live alongside the code in `tests/` (PHPUnit 11 + Brain\Monkey 2.6 + Patchwork). To run the full plugin test suite:

```bash
cd wordpress/plugins/spreadconnect-pod
composer install
vendor/bin/phpunit
```

Repo-wide slice tests (`tests/slices/pod-shop-mvp/slice-NN-*.php`) run via the root-level Composer command:

```bash
composer test
```

Translation files live under `languages/`. After editing source strings, regenerate the POT and update the German `.po`:

```bash
wp i18n make-pot wordpress/plugins/spreadconnect-pod \
  wordpress/plugins/spreadconnect-pod/languages/spreadconnect-pod.pot \
  --domain=spreadconnect-pod --skip-js
```

Coding conventions: PHP 8.2 strict types, PSR-4 autoload, `final class` by default, only `static` methods on stateless adapters, `declare(strict_types=1);` at the top of every file. Follow the patterns documented in `architecture.md` "Architecture Layers" and never widen a class's mutability without an explicit slice spec.

## Troubleshooting

Common issues and where to look:

- **"Cannot reach Spreadconnect" on the Dashboard Connection card** — Re-run Settings → "Test This Key". A 401 means the API key is wrong; a 5xx / network error means upstream is down. Inspect `WooCommerce → Status → Logs` filtered by `spreadconnect-api-client` for the raw request log.
- **Webhooks return 401** — HMAC mismatch. Confirm the secret stored at Spreadconnect matches the one this plugin generated (Settings → "Regenerate Secret" rotates both sides via the `webhook_secret_rotated` hook). Check `wp_spreadconnect_webhook_log` rows with `hmac_status='invalid'` for the offending IP / event-type.
- **Catalog sync stops at "in_progress"** — A worker died mid-run. Open Hub → Catalog and click "Sync now" again — the new run completes idempotently. Inspect the row in `wp_spreadconnect_sync_history` for the `__error__` detail entry.
- **Failed-Ops queue grows but no e-mail arrives** — Confirm Settings → Failure Notifications recipients are non-empty and the underlying WP `wp_mail()` is functional (test with another plugin like Mail-SMTP).
- **`pa_groesse` / `pa_farbe` attributes missing** — They are auto-provisioned on activation and on every sync. If they were manually deleted, run "Sync now" — `AttributeProvisioner` recreates them idempotently.

For escalation paths see [Error Handling Strategy](../../specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md#architecture-layers).

## License

This plugin is distributed under the GPL-2.0-or-later license — same as WordPress core. See the plugin header in `spreadconnect-pod.php` for the canonical license declaration; the GPL text is available at <https://www.gnu.org/licenses/gpl-2.0.html>.
