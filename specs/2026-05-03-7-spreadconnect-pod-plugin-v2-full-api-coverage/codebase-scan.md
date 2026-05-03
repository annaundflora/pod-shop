# Codebase Scan

**Feature:** Spreadconnect POD Plugin v2 — Full API Coverage
**Scan Date:** 2026-05-03
**Discovery:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/discovery.md`
**Wireframes:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/wireframes.md`
**Gate 0:** `gates/compliance-discovery-wireframe.md` (PASS)

---

## Identified Patterns

| #  | Pattern                                            | Locations                                                                                                                                      | Count | Type   |
|----|----------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------|-------|--------|
| 1  | Spreadconnect API client (Bearer + 3x retry + 429) | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-api-client.php`                                                              | 1     | EXTEND |
| 2  | PSR-4 namespaced WC plugin bootstrap               | `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php`, `wordpress/plugins/wp-custom-fields/wp-custom-fields.php`                          | 2     | EXTEND |
| 3  | WC `processing` order hook → outbound API submit   | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-order-service.php:23`                                                        | 1     | EXTEND |
| 4  | Async dispatcher via `wp_schedule_single_event`    | `wordpress/plugins/pinterest-capi/includes/class-pinterest-capi-hooks.php:46`                                                                  | 1     | AVOID  |
| 5  | REST route registration (custom namespace)         | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-tracking-service.php:21-40`                                                  | 1     | EXTEND |
| 6  | Webhook signature verification (stub)              | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-tracking-service.php:86-104`                                                 | 1     | AVOID  |
| 7  | WP Settings API page (Options screen)              | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-settings.php`, `wordpress/plugins/pinterest-capi/admin/settings-page.php`    | 2     | AVOID  |
| 8  | Order meta access via legacy `update_post_meta`    | `class-spreadconnect-order-service.php:25,69,92,95`, `class-spreadconnect-tracking-service.php:148,153-154`                                    | 2     | AVOID  |
| 9  | Variation attribute lookup (German + English)      | `frontend/lib/product/variant-utils.ts:4-5`, `class-spreadconnect-order-service.php:114-117`                                                   | 2     | REUSE  |
| 10 | Order-meta keys naming `_spreadconnect_*`          | `class-spreadconnect-order-service.php:25,69`, `class-spreadconnect-tracking-service.php:148-154,181`, `slice-05-pod-anbindung-spreadconnect.php` | 4     | REUSE  |
| 11 | PHPUnit + Brain\Monkey test bootstrap (PSR-4)      | `wordpress/plugins/spreadconnect-pod/tests/bootstrap/bootstrap.php`, `wordpress/plugins/spreadconnect-pod/composer.json`                       | 1     | REUSE  |
| 12 | Slice-based PHPUnit tests (`tests/slices/...`)     | `wordpress/plugins/spreadconnect-pod/tests/slices/pod-shop-mvp/`, `tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php`         | 2     | REUSE  |
| 13 | `error_log()` for service logging (no WC_Logger)   | `class-spreadconnect-api-client.php:91`, `class-spreadconnect-order-service.php:26,33,42,57,71`, `class-spreadconnect-tracking-service.php`    | 12    | AVOID  |
| 14 | Multiple-class PSR-4 plugin layout                 | `wordpress/plugins/spreadconnect-pod/includes/`, `wordpress/plugins/pinterest-capi/includes/`                                                  | 2     | EXTEND |
| 15 | Bilingual attr slug fallback (`pa_groesse`)        | `frontend/lib/product/variant-utils.ts:4-5`                                                                                                    | 1     | REUSE  |
| 16 | "POD Shop" admin-notice on missing dependency      | `spreadconnect-pod.php:34-39`                                                                                                                  | 1     | REUSE  |
| 17 | mu-plugin REST/CORS/redirect snippets              | `wordpress/mu-plugins/cors-graphql.php`, `wordpress/mu-plugins/headless-redirect.php`                                                          | 2     | NEW    |
| 18 | WP-CLI idempotent setup script                     | `scripts/setup.sh`                                                                                                                             | 1     | REUSE  |
| 19 | Plugin-level `composer.json` + `phpunit.xml`       | `wordpress/plugins/spreadconnect-pod/composer.json`, `wordpress/plugins/pinterest-capi/composer.json`                                          | 2     | EXTEND |
| 20 | `register_post_meta()` with WPGraphQL exposure     | `wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php:72-76`                                                                    | 1     | REUSE  |
| 21 | Patchwork redefinable-internals (`sleep`, `hash_equals`) | `wordpress/plugins/spreadconnect-pod/patchwork.json`                                                                                     | 1     | REUSE  |

---

## Existing Abstractions

| Abstraction                                       | Location                                                                                            | Used by                              | Recommendation | Rationale |
|---------------------------------------------------|-----------------------------------------------------------------------------------------------------|--------------------------------------|----------------|-----------|
| `SpreadconnectPod\SpreadconnectApiClient`         | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-api-client.php`                   | OrderService, TrackingService        | EXTEND         | v1 covers only `POST /orders` + `GET /orders/{id}`; retry/Backoff/429 logic is sound but must be extended to all 27 endpoints, ContentType-Bearer header must be replaced (currently sends raw key, not `Bearer`), and the **inner 3×-retry must be removed** per Discovery's "single retry-layer" decision. |
| `SpreadconnectPod\SpreadconnectOrderService`      | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-order-service.php`                | `woocommerce_order_status_processing` hook | EXTEND   | Order-submit pattern + idempotency-skip (`_spreadconnect_order_id` exists) is the right shape, but is synchronous, uses `update_post_meta` (HPOS-non-compliant), and lacks Action-Scheduler dispatch + state-machine writes. |
| `SpreadconnectPod\SpreadconnectTrackingService`   | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-tracking-service.php`             | `rest_api_init`                      | AVOID          | Webhook handler does not implement HMAC-SHA256 (only API-key fallback + WP_DEBUG bypass), is fully synchronous (returns 200 not 202 `[accepted]`), has no idempotency hash, no event log table, no async schedule. Pattern is inadequate for v2; Slice 3 must replace it. |
| `SpreadconnectPod\SpreadconnectSettings`          | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-settings.php`                     | `admin_menu`, `admin_init`           | AVOID          | Sits under `Settings → Spreadconnect POD` (`add_options_page`); v2 requires `WooCommerce → Spreadconnect` Hub via `add_submenu_page('woocommerce', ...)` with sectioned routing. Field set is also a tiny 2-field subset. |
| `Pinterest_CAPI_Service` (HTTP wrapper)           | `wordpress/plugins/pinterest-capi/includes/class-pinterest-capi-service.php`                        | `Pinterest_CAPI_Hooks`               | REUSE pattern  | Demonstrates same structural style: PSR-4-ish service class, Bearer auth, `wp_remote_post` + JSON encode, `error_log` for failures. Use as second reference plugin pattern (NOT same namespace, no PSR-4). |
| `wp_schedule_single_event` async dispatcher       | `wordpress/plugins/pinterest-capi/includes/class-pinterest-capi-hooks.php:46`                       | order-completed handler              | AVOID          | WP-Cron is the simpler dispatcher and OK for fire-and-forget Pinterest events. v2 must use **Action Scheduler** (Discovery: PHP-timeout-immune, retry-fähig, observable, DB-persistent; bundled with WC). Do not reuse the WP-Cron pattern for SC. |
| `_spreadconnect_*` post-meta keys                 | service classes + slice tests                                                                       | OrderService, TrackingService, tests | REUSE          | Discovery explicitly says "v1's order-meta keys (`_spreadconnect_order_id`, `_spreadconnect_tracking_number`, `_spreadconnect_tracking_url`) are reused for consistency". |
| Brain\Monkey + PHPUnit + WP/WC stub bootstrap     | `wordpress/plugins/spreadconnect-pod/tests/bootstrap/bootstrap.php`                                 | All slice tests                      | REUSE          | Already-working harness for testing without a live WP. Stub set must grow (WC_Product_Variable, WC_Product_Variation, WC_Logger, ActionScheduler_Store, FeaturesUtil) but the pattern is correct. |
| Patchwork `redefinable-internals`                 | `wordpress/plugins/spreadconnect-pod/patchwork.json`                                                | Brain\Monkey via Patchwork           | REUSE          | Already lists `sleep`, `hash_equals`, `error_log`. Must add anything new the v2 client touches (e.g. `microtime`, `random_bytes`). |
| `pinterest-capi/admin/settings-page.php` style    | `wordpress/plugins/pinterest-capi/admin/settings-page.php`                                          | one-off settings menu                | AVOID          | Procedural one-file settings UI — does not scale to the 7-section Hub-Page in Discovery. v2 needs class-based section routing + `manage_woocommerce` capability + WC submenu. |

---

## Recommendations

### REUSE (existing abstractions to keep)

| #  | What                                                       | Where                                                                                                | Why |
|----|------------------------------------------------------------|------------------------------------------------------------------------------------------------------|-----|
| 1  | Bilingual attribute slug constants `pa_groesse`/`pa_farbe` | Mirror constants from `frontend/lib/product/variant-utils.ts:4-5` into a shared PHP constant         | Single source of truth across frontend + plugin (CLAUDE.md convention); Discovery says "fix `pa_groesse`/`pa_farbe`". |
| 2  | Order-meta key namespace `_spreadconnect_*`                | Same naming used in `class-spreadconnect-order-service.php` and tests                                | Discovery → Current State Reference: "v1's Order-Meta-Schlüssel werden in v2 weiterverwendet". |
| 3  | Brain\Monkey test bootstrap                                | `wordpress/plugins/spreadconnect-pod/tests/bootstrap/bootstrap.php`                                  | Already PHPUnit 11 + Brain\Monkey 2.6 with WP_Error/WP_REST_*/WC_Order/WC_Product_Variation stubs; extend the stub set, do not rebuild. |
| 4  | Slice-test directory layout                                | `tests/slices/pod-shop-mvp/`                                                                         | CLAUDE.md mandates "Slice-based organization" for both Vitest + PHPUnit; v2's 10 slices add `slice-01-foundation.php` … `slice-10-logs-polish.php` here. |
| 5  | `composer.json` PSR-4 + classmap split                     | `wordpress/plugins/spreadconnect-pod/composer.json`                                                  | Already runs `vendor/bin/phpunit`; PSR-4 root `SpreadconnectPod\` is registered. |
| 6  | Patchwork redefinable-internals list                       | `wordpress/plugins/spreadconnect-pod/patchwork.json`                                                 | Existing entries (`sleep`, `hash_equals`, `error_log`) are exactly what an HMAC + retry test suite needs. |
| 7  | mu-plugin pattern for cross-cutting WP-only concerns       | `wordpress/mu-plugins/cors-graphql.php`, `wordpress/mu-plugins/headless-redirect.php`                | If v2 needs the webhook URL excluded from `headless-redirect.php`, the mu-plugin already has an opt-out list (`/wc-api/`, `/checkout`); add `/wp-json/spreadconnect/` if not implicitly handled (REST_REQUEST already excluded — verify). |
| 8  | `register_post_meta()` + WPGraphQL exposure pattern        | `wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php:72-76`                          | If v2 ever wants to expose `_spreadconnect_cost`/`_spreadconnect_state` to GraphQL (out-of-scope today, but explicitly Out-of-Scope frontend per Discovery), reuse this exact `FIELDS` array shape. |
| 9  | Idempotency-skip pattern (`if meta exists, return`)        | `class-spreadconnect-order-service.php:25-28`                                                        | Discovery → Order Lifecycle confirms: "Idempotent: Skip wenn `_spreadconnect_order_id` existiert". The shape is correct; only swap to HPOS-API. |
| 10 | German `WP_Error` code prefix `spreadconnect_*`            | `class-spreadconnect-api-client.php:110,125,131`, plus tests                                         | Existing tests assert on `spreadconnect_max_retries` / `spreadconnect_http_error` / `spreadconnect_missing_article_id`; keep this prefix style for new error codes. |
| 11 | German `wp_mail` admin-notify-on-failure                   | `class-spreadconnect-order-service.php:157-168`                                                      | Discovery → Failure Handling specifies email notifications; the existing `notify_admin_on_failure` shape is the seed. v2 wraps in a real notifier with recipient list. |
| 12 | WP-CLI activation in `scripts/setup.sh`                    | `scripts/setup.sh:122-127`                                                                           | Already activates `spreadconnect-pod` plugin idempotently; v2 inherits this — Slice 1 just needs activation hook handlers (Tables, Defaults). |

### EXTEND (existing code base to extend)

| #  | What                                            | Where                                                                                          | Extension needed |
|----|-------------------------------------------------|------------------------------------------------------------------------------------------------|------------------|
| 1  | `SpreadconnectApiClient`                        | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-api-client.php`              | Greenfield-rewrite **inside the same class name** keeping retry/429-header/X-RateLimit-Remaining logic. Add: (a) `Authorization: Bearer <key>` (currently sends raw `$api_key`), (b) typed wrapper methods for **all 27 endpoints** (Auth, Articles, Orders, Subscriptions, Simulations, ProductTypes, Stock, Designs-Read), (c) **remove inner 3× retry** per Discovery decision "Single Retry-Layer = Action Scheduler only" — keep only single 429-retry-after-header attempt + fail-fast on 4xx/5xx. (d) ETag/If-None-Match support for `GET /productTypes/{id}` long-cache. |
| 2  | `SpreadconnectOrderService`                     | `wordpress/plugins/spreadconnect-pod/includes/class-spreadconnect-order-service.php`           | Replace `update_post_meta` with `$order->update_meta_data()` + `$order->save()` (HPOS); split into `OrderSubmitJob`, `OrderConfirmJob`, `OrderCancelJob` Action-Scheduler handlers; add `submitting`/`NEW`/`CONFIRMED`/`PROCESSED`/`CANCELLED`/`failed_to_submit` state-machine writes; remove direct `wp_mail` (move to FailureNotifier service). Keep idempotency-skip and `notify_admin_on_failure` shape. |
| 3  | Plugin bootstrap `spreadconnect-pod.php`        | `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php`                                    | Add `before_woocommerce_init` HPOS-declare, `register_activation_hook` (Custom-Tables `dbDelta`, default options), `register_deactivation_hook` (unschedule recurring AS actions), `uninstall.php`. Replace direct `require_once` chain with Composer-PSR-4 autoload + service-container bootstrap; bump `Version: 2.0.0`. Keep WooCommerce-missing admin-notice fallback. |
| 4  | REST namespace `spreadconnect/v1`               | `class-spreadconnect-tracking-service.php:22,33`                                               | Keep namespace `spreadconnect/v1`. Add routes: `/sync-progress` (AJAX-poll, capability-protected), `/health` (already there, expand). Webhook route stays `/webhook` but receives full HMAC + 202 `[accepted]` body + async schedule. |
| 5  | Plugin-level `composer.json`                    | `wordpress/plugins/spreadconnect-pod/composer.json`                                            | Already PSR-4 + PHPUnit 11 + Brain\Monkey. Add: switch `autoload.classmap` → `autoload.psr-4` (`SpreadconnectPod\\`) since CLAUDE.md says "Custom WP plugins use PSR-4: `SpreadconnectPod\` namespace autoloaded from `wordpress/plugins/spreadconnect-pod/includes/`". Add dev-dep on `woocommerce/action-scheduler` only if needed for unit tests; runtime AS comes bundled with WC. |
| 6  | Test bootstrap stubs                            | `wordpress/plugins/spreadconnect-pod/tests/bootstrap/bootstrap.php`                            | Add stubs for: `WC_Product_Variable`, `WC_Logger`, `Automattic\WooCommerce\Utilities\FeaturesUtil`, `ActionScheduler_Store` (or `as_schedule_single_action`/`as_unschedule_action` Brain\Monkey functions), `WC_Order::update_meta_data()`/`save()`, `wpdb` minimal stub. Patchwork additions: `random_bytes`, `microtime`, `time`. |
| 7  | German attr-slug helper                         | `class-spreadconnect-order-service.php:114-117`                                                | Replace ad-hoc `pa_size`/`size`/`groesse` chain with constant `SC_SIZE_ATTR_SLUG = 'pa_groesse'` and `SC_COLOR_ATTR_SLUG = 'pa_farbe'` per Discovery → Business Rules ("Attribut-Slugs **fix**: `pa_groesse`, `pa_farbe`"). Keep fallback during migration. |
| 8  | Slice-test parallel directory                   | `tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php`                           | This duplicate exists at root **and** in plugin's tests — keep plugin-internal copies authoritative; the root `tests/slices/pod-shop-mvp/` directory inherits the same naming for new slices `slice-01..slice-10` (PHP for SC slices, .test.ts for FE). |

### NEW (new implementation needed)

| #  | What | Why new |
|----|------|---------|
| 1  | Action Scheduler integration (`as_schedule_single_action`, `as_enqueue_async_action`, `as_unschedule_action`) for all custom hooks: `spreadconnect/sync_catalog`, `/sync_article`, `/create_order`, `/confirm_order`, `/cancel_order_mirror`, `/scheduled_stock_sync`, `/handle_article_removed`, `/fetch_tracking`, `/process_webhook_event`, `/purge_old_logs`, `/auto_subscription_check` | No code in either plugin uses Action Scheduler today (only `wp_schedule_single_event` in pinterest-capi:46, AVOIDed). Discovery mandates Action Scheduler as single retry layer. |
| 2  | Custom DB tables `wp_spreadconnect_failed_ops`, `wp_spreadconnect_webhook_log`, `wp_spreadconnect_sync_history` with `dbDelta`, indexes per Discovery's Data section, `uninstall.php` drop | No `dbDelta`/`CREATE TABLE` in repo today. Schema (incl. `uniq_event_id` UNIQUE for webhook idempotency) is fully specified in Discovery. |
| 3  | HMAC-SHA256 verifier with `hash_equals()` over raw body, header `X-SPRD-SIGNATURE`, base64 (no `sha256=` prefix) | Existing `verify_webhook_signature` is a stub bypassed via `WP_DEBUG` (line 99); patchwork.json already pre-declares `hash_equals` so the test path is ready. |
| 4  | Webhook-ID deterministic hash `sha256(eventType + ":" + entity.id + ":" + sha256(raw_body))` + UNIQUE constraint enforcement + 8s/202/`[accepted]` ACK contract | SC delivers no `eventId`; this idempotency strategy exists nowhere in v1. |
| 5  | HPOS compatibility declaration via `Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` on `before_woocommerce_init` | Not declared anywhere. WC 8.2+ enforces this. |
| 6  | `WC_Logger` adapter with sources `spreadconnect-api-client`, `spreadconnect-order-service`, `spreadconnect-webhook-receiver`, `spreadconnect-sync-job` | All current logging uses `error_log()` (12 call sites). Discovery mandates structured `WC_Logger`. |
| 7  | Hub-Page under `WooCommerce → Spreadconnect` with section routing (Dashboard / Catalog / Orders / Webhooks / Failed / Logs / Settings / Subscriptions) | Nothing today uses `add_submenu_page('woocommerce', ...)`. Both existing settings pages use `add_options_page`. |
| 8  | WC Product-Edit + Order-Edit Meta-Boxes (`add_meta_box`) | No `add_meta_box` calls anywhere in repo. |
| 9  | WC Product-List + Order-List custom columns (dual hooks `manage_edit-product_columns` + `manage_woocommerce_page_wc-orders_columns` for HPOS + legacy) | No `manage_*_columns` filters anywhere in repo. |
| 10 | Bulk-Action handlers (`bulk_actions-edit-product`, `bulk_actions-woocommerce_page_wc-orders` + `_legacy`) | None. |
| 11 | WC Variable-Product upsert flow (`WC_Product_Variable` + `WC_Product_Variation`) with image-sideload from `media_sideload_image()` and required `wp-admin/includes/{media,file,image}.php` boilerplate | No CRUD on variable products in plugins; only frontend reads them via WPGraphQL. |
| 12 | Live progress AJAX endpoint + client (`/wp-json/spreadconnect/v1/sync-progress`) | New REST route + JS poller for sync UI. |
| 13 | HMAC secret manager (`spreadconnect_webhook_secret` option, generate via `random_bytes(32)` + base64, "show-once" reveal panel, regenerate-with-resubscribe) | No secret-mgmt UI exists. |
| 14 | Subscriptions auto-register/repair flows (`POST /subscriptions`, `DELETE /subscriptions/{id}`, drift-detection) | New domain. |
| 15 | Persistent dismissible-but-not-without-resolution Admin Notices (per-op-type dismiss policy with `dismiss_resolution_modal`) | No admin-notice infrastructure beyond the WC-missing notice in `spreadconnect-pod.php:35`. |
| 16 | Live stock cache (transient API, 5min TTL) + bulk `GET /stock` refresh | No transient usage anywhere. |
| 17 | Failure-Recovery DLQ UI + per-op-type Resolution-Modal | New. |
| 18 | i18n: `spreadconnect-pod` text-domain + `de_DE.po` + `load_plugin_textdomain` | No `__()`/`_e()` wrappers in current PHP code; current strings are German hardcoded. |
| 19 | `uninstall.php` (drop tables, delete options, optionally delete subscriptions) | Not present. |

### AVOID (known debt or feature replaces)

| # | What                                                                | Decision Log Entry          | Alternative |
|---|---------------------------------------------------------------------|-----------------------------|-------------|
| 1 | `update_post_meta`/`get_post_meta` for **order** data (`_spreadconnect_order_id` etc.) at `class-spreadconnect-order-service.php:25,69` and `class-spreadconnect-tracking-service.php:148-154,181` | (no `.decisions.md`) Feature replaces this — Discovery → HPOS-Compliance: "Order-Meta **ausschließlich** via `$order->update_meta_data($key, $value)` + `$order->save()`. Kein `update_post_meta()` für Order-Daten." | `$order = wc_get_order($id); $order->update_meta_data($key, $value); $order->save();` and `wc_get_orders(['meta_query' => ...])` for reverse-lookup. |
| 2 | `verify_webhook_signature()` API-key-fallback + WP_DEBUG bypass at `class-spreadconnect-tracking-service.php:86-104` | Feature replaces this — Discovery → Webhooks: HMAC-SHA256 over raw body, header `X-SPRD-SIGNATURE`, `hash_equals()` constant-time-compare, no debug bypass. | New `WebhookSignatureVerifier` service + `permission_callback` returning bool from constant-time compare. |
| 3 | `wp_schedule_single_event` for SC dispatching (pattern from `pinterest-capi/includes/class-pinterest-capi-hooks.php:46`) | Feature replaces this — Discovery → Out-of-Process: Action Scheduler mandatory (DB-persistent, retry-fähig, observable). | `as_enqueue_async_action('spreadconnect/...', $args, 'spreadconnect')` + `add_action('spreadconnect/...', [$jobHandler, 'handle'])`. |
| 4 | `add_options_page` for Spreadconnect settings (`class-spreadconnect-settings.php:11-19`) | Feature replaces this — Discovery → UI Patterns: WC-Submenu Hub. | `add_submenu_page('woocommerce', __('Spreadconnect', 'spreadconnect-pod'), ...)` with internal `?section=...` routing. |
| 5 | Inner 3× HTTP retry with `sleep()` (`class-spreadconnect-api-client.php:53-135`) | Feature replaces this — Discovery → API & Security: "Single Retry-Layer (Entscheidung): Retries leben **nur** auf der Job-Ebene (Action Scheduler)". | Keep only one retry on HTTP 429 honoring `X-RateLimit-Retry-After-Seconds`; fail-fast otherwise; let Action Scheduler handle 1min/5min/15min job-retries. |
| 6 | `error_log()` calls (12 total in `class-spreadconnect-*` files) | Feature replaces this — Discovery → UI Patterns: WC_Logger with source `spreadconnect-*`. | `wc_get_logger()->info($msg, ['source' => 'spreadconnect-api-client', 'context' => $context])`. |
| 7 | Single-class settings file procedural rendering (`pinterest-capi/admin/settings-page.php`) | Feature replaces — Hub-Page must be class-based with section dispatcher. | `Hub\Controller` + per-section view classes (`SettingsView`, `DashboardView`, …). |
| 8 | German `WP_Error` messages mixed with English context (e.g. `class-spreadconnect-api-client.php:127,131`) | Feature replaces this — Discovery → i18n: "Source-Strings **English**, Translation `de_DE.po` mitgeliefert". | All new `__()` strings English; `de_DE.po` for translations. |
| 9 | Defaulting `spreadconnect_use_staging` to `true` (`spreadconnect-pod.php:42`) | Feature replaces this — Discovery → Settings: "default off in production". | Default `false`; staging is opt-in via Settings checkbox. |

---

## Conventions Detected

| Convention                                                                     | Evidence                                                                                                                                                       | Count   |
|--------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|---------|
| File naming: `class-{kebab-name}.php`                                          | `class-spreadconnect-api-client.php`, `class-spreadconnect-order-service.php`, `class-spreadconnect-tracking-service.php`, `class-spreadconnect-settings.php`, `class-pinterest-capi-service.php`, `class-pinterest-capi-hooks.php`, `class-custom-fields.php` | 7 files |
| Class naming: `Spreadconnect{Domain}{Suffix}` (PSR-4)                          | `SpreadconnectApiClient`, `SpreadconnectOrderService`, `SpreadconnectTrackingService`, `SpreadconnectSettings`                                                  | 4 classes |
| Namespace: `SpreadconnectPod\` (PSR-4 root)                                    | All four `class-spreadconnect-*.php` files declare `namespace SpreadconnectPod;`                                                                                | 4 files |
| Plugin guard: `if (!defined('ABSPATH')) { exit; }` at top of every PHP file    | All include files in spreadconnect-pod, pinterest-capi, wp-custom-fields, mu-plugins                                                                            | 12+ files |
| Method naming: `snake_case` for public PHP methods (per WP convention)         | `handle_order_processing`, `build_order_items`, `verify_webhook_signature`, `apply_tracking`, `find_wc_order_by_sc_id`, `register_settings`, `add_settings_page` | 15+ methods |
| Strict type hints on params + return types (PHP 8.2)                           | `class-spreadconnect-*.php` all use `: void`/`: array`/`: string`/`: bool`/`?int`                                                                              | 4 files |
| `WP_Error` codes prefixed `spreadconnect_*`                                    | `spreadconnect_max_retries`, `spreadconnect_http_error`, `spreadconnect_json_error`, `spreadconnect_missing_article_id`                                         | 4 codes |
| Option keys prefixed `spreadconnect_*`                                         | `spreadconnect_api_key`, `spreadconnect_use_staging` (existing) — Discovery extends with 16 more keys                                                           | 2 → 18  |
| Order-meta keys prefixed `_spreadconnect_*`                                    | `_spreadconnect_order_id`, `_spreadconnect_tracking_number`, `_spreadconnect_tracking_url`, `_spreadconnect_article_id`                                         | 4 keys  |
| REST namespace: `{plugin-slug}/v1` (e.g. `spreadconnect/v1`)                   | `class-spreadconnect-tracking-service.php:22,33`                                                                                                                | 1 plugin |
| Test files: `{ClassName}Test.php` co-located in `tests/slices/{epic}/`         | `SpreadconnectApiClientTest.php`, `SpreadconnectOrderServiceTest.php`, `SpreadconnectTrackingServiceTest.php`                                                  | 3 files |
| Test PHP namespace: `SpreadconnectPod\Tests\`                                   | All three test files                                                                                                                                            | 3 files |
| Test pattern: Brain\Monkey `setUp/tearDown` + `Functions\when()->justReturn()`+ `Functions\when()->alias(callable)` | `SpreadconnectApiClientTest.php` (and slice file twin)                                                                                                          | 2 patterns |
| Test naming: `test_ac{N}_{snake_case_description}` mapped to slice ACs         | `test_ac1_*`, `test_ac3_*`, `test_ac4_*`, `test_ac5_*`                                                                                                          | 4+ tests |
| German UI strings hardcoded today, English Source target                       | All current `add_order_note`, `error_log`, settings labels are German; Discovery says English source + de_DE.po                                                 | -- |
| Settings page sub-menu: `add_options_page` (legacy), v2 must use `add_submenu_page('woocommerce', ...)` | `class-spreadconnect-settings.php:12`, `pinterest-capi/admin/settings-page.php:13`                                                                              | 2 plugins |
| Composer plugin layout: `composer.json` + `phpunit.xml` per plugin            | `wordpress/plugins/spreadconnect-pod/{composer,phpunit}.{json,xml}`, `wordpress/plugins/pinterest-capi/{composer,phpunit}.{json,xml}`                            | 2 plugins |

---

## Key Integration Points

| Integration                                          | Current Location                                                                              | Impact of Feature |
|------------------------------------------------------|-----------------------------------------------------------------------------------------------|-------------------|
| WC `processing` hook → SC submit                     | `spreadconnect-pod.php:50` `add_action('woocommerce_order_status_processing', ...)`            | Replace direct synchronous `handle_order_processing` with `as_enqueue_async_action('spreadconnect/create_order', ['order_id' => $id])`. Add `woocommerce_order_status_cancelled` hook for `cancel_order_mirror`. |
| REST webhook receiver                                | `spreadconnect-pod.php:53` + `class-spreadconnect-tracking-service.php:21-40`                  | Keep namespace `spreadconnect/v1`, but rewrite `handle_webhook` to (1) verify HMAC, (2) compute deterministic event-id hash, (3) insert into `wp_spreadconnect_webhook_log` with UNIQUE conflict-as-duplicate, (4) `as_enqueue_async_action('spreadconnect/process_webhook_event', ...)`, (5) return 202 + body `[accepted]`. Same `permission_callback` slot now hosts the constant-time HMAC check. |
| Settings page                                        | `spreadconnect-pod.php:57-58` + `class-spreadconnect-settings.php`                              | Move from `add_options_page` to `add_submenu_page('woocommerce', ...)` Hub root. Settings becomes one of 8 sections. The `register_setting` calls expand from 2 keys to 18 (per Discovery's wp_options table). |
| Plugin bootstrap order (`plugins_loaded`)            | `spreadconnect-pod.php:60`                                                                     | Add `before_woocommerce_init` hook (HPOS declare) **before** `plugins_loaded`. Move service wiring into a Container class instead of inline `new` calls. |
| WP-CLI activation in `scripts/setup.sh`              | `scripts/setup.sh:122-124` (`wp plugin activate spreadconnect-pod`)                            | New `register_activation_hook` runs `dbDelta` for 3 custom tables, sets default options, schedules recurring AS actions. Existing `setup.sh` continues to activate the plugin (no change to setup.sh). |
| `pa_groesse`/`pa_farbe` attribute slugs              | `frontend/lib/product/variant-utils.ts:4-5`                                                    | Plugin's catalog-sync **creates** taxonomies `pa_groesse`/`pa_farbe` if missing (Discovery → Catalog Sync); frontend already reads them. No frontend change needed. |
| WC_Order meta keys (frontend may eventually read)    | `_spreadconnect_*` keys not currently read by frontend (verified — no grep hits in `frontend/`) | Frontend stays untouched (Discovery → Out of Scope: "Frontend-Änderungen am Headless-Next.js-Shop"). |
| `headless-redirect.php` mu-plugin                    | `wordpress/mu-plugins/headless-redirect.php:18-20`                                              | `REST_REQUEST` constant excludes `/wp-json/*` from redirect — webhook URL `/wp-json/spreadconnect/v1/webhook` already protected. **No mu-plugin change required**, but verify in QA. |
| Pinterest CAPI plugin (sibling)                      | `wordpress/plugins/pinterest-capi/`                                                            | Co-exists. Both plugins fire on `woocommerce_order_status_*` hooks; ensure no priority collision. Pinterest fires on `completed` (post-tracking-applied), Spreadconnect on `processing` (pre-fulfillment). Independent. |
| WPGraphQL (frontend Apollo)                          | n/a in plugin                                                                                  | Plugin is back-end only — no GraphQL field exposure in scope (Discovery Out-of-Scope). |
| Mollie Payments                                      | n/a in plugin                                                                                  | Mollie fires `processing` after payment success; same hook the plugin consumes. No direct dependency. |

---

## Decision Log Context

| # | Decision | Relevant for this Feature | How |
|---|----------|---------------------------|-----|
| -- | No `.decisions.md` file present at repo root or any worktree path (verified via `find /home/dev/pod-shop -maxdepth 3 -name .decisions.md`) | n/a | Architecture phase should consider creating one to capture: (a) Action-Scheduler-as-only-retry-layer, (b) HPOS-only meta access, (c) WC_Logger over `error_log`, (d) `add_submenu_page('woocommerce')` over `add_options_page`. |

> No decision log found.

**However**, the Discovery document itself encodes prior decisions that the Architecture Agent should treat as load-bearing:
- "Greenfield" (v1 deletion before v2) — `discovery.md` line 71
- "Single Retry-Layer (Entscheidung)" — line 557
- "v1's Order-Meta-Schlüssel werden in v2 weiterverwendet" — line 87
- "fix `pa_groesse`, `pa_farbe`" — line 582
- HMAC contract resolved via SC docs (Open Question #8 RESOLVED) — line 990
- "Last-Write-Wins + Idempotency-Skip" race strategy — line 607

---

## Scan Summary

| Metric                  | Value |
|-------------------------|-------|
| Patterns found          | 21    |
| REUSE recommendations   | 12    |
| EXTEND recommendations  | 8     |
| NEW recommendations     | 19    |
| AVOID recommendations   | 9     |
| Decision Log entries    | 0     |
