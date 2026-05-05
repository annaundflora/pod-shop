# Gate 1: Architecture Compliance Report

**Reviewed Architecture:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md`
**Review Date:** 2026-05-03
**Discovery:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/discovery.md`
**Wireframes:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/wireframes.md`
**Codebase Scan:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/codebase-scan.md`
**Gate 0:** PASS (`gates/compliance-discovery-wireframe.md`)

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 110 |
| Blocking | 0 |

**Verdict:** APPROVED

---

## A) Feature Mapping

Every Discovery feature traced to architecture coverage:

| Discovery Feature | Architecture Section | API Endpoint | DB Schema | Status |
|-------------------|---------------------|--------------|-----------|--------|
| Full 27-endpoint REST coverage | API Design § Outbound (table rows 1–31) | All 27 wired + 4 reserved | n/a | PASS |
| 7 webhook events | API Design § Inbound + Server Logic § ProcessWebhookEventJob | `POST /webhook` | `wp_spreadconnect_webhook_log` | PASS |
| Pull-only Catalog-Sync | Server Logic § SyncCatalogJob/SyncArticleJob/ProductMapper | `GET /articles`, `GET /articles/{id}`, `GET /productTypes/{id}` | `_spreadconnect_*` postmeta + `wp_spreadconnect_sync_history` | PASS |
| Image-sideload via media_sideload_image | Server Logic § ImageSideloader + Constraint § media_sideload_image | n/a (WP core) | WP Media Library | PASS |
| Order lifecycle (auto-create on processing, manual confirm, cancel-mirror) | Server Logic § OrderHandler/OrderSubmitJob/OrderConfirmJob/OrderCancelMirrorJob + business-flow diagram | `POST /orders`, `POST /orders/{id}/confirm`, `POST /orders/{id}/cancel` | `_spreadconnect_state`, `_spreadconnect_order_id` ordermeta | PASS |
| Hub-Page with 8 sections | Architecture Layers § Hub\Controller + Hub\View\* (Dashboard/Catalog/Orders/Webhooks/Failed/Logs/Settings/Subscriptions) | n/a | n/a | PASS |
| Inline meta-boxes (Product-Edit + Order-Edit) | Server Logic § Inline\ProductMetaBox + Inline\OrderMetaBox | per-component | `_spreadconnect_*` post/order-meta | PASS |
| Product-List columns (Cost+Margin) | Server Logic § Inline\ProductListColumns | n/a | reads `_spreadconnect_cost`, `_spreadconnect_article_id` | PASS |
| Order-List columns + filter + bulk-action (HPOS+Legacy dual hooks) | Server Logic § Inline\OrderListColumns + Constraints § dual hook-sets | AJAX `spreadconnect_resend_failed_op` | reads `_spreadconnect_state` | PASS |
| Hybrid stock sync (Live cache + periodic + webhook) | Server Logic § StockCache + StockSyncJob + LiveStockRefresher; AS hook `scheduled_stock_sync` | `GET /stock` (bulk) | transient `sc_stock_{sku}` + WC variation stock | PASS |
| Pricing: WC manual + SC-cost meta + margin display | Constraints + Inline\ProductMetaBox + Inline\ProductListColumns + Validation Rules | n/a | `_spreadconnect_cost`, `_spreadconnect_cost_currency` | PASS |
| Default-Shipping-Type setting (gates Auto-Confirm) | Constraints + Validation Rules + WP Options table + Open Q11 resolution | `GET/POST /orders/{id}/shippingTypes` + inline on `POST /orders` | `spreadconnect_default_shipping_type` option | PASS |
| Failure recovery (3-retry AS, DLQ UI, email, order-note, admin-notice) | Server Logic § FailedOpsRepo/FailureNotifier/AdminNoticeStore + AS retry policy + Error Handling matrix | n/a | `wp_spreadconnect_failed_ops` | PASS |
| Auto-webhook subscription registration on Settings-Save + HMAC one-time-reveal | Server Logic § SubscriptionManager + WebhookSecretManager; Admin AJAX `spreadconnect_repair_subscriptions`, `spreadconnect_regenerate_secret` | `GET/POST/DELETE /subscriptions` | option `spreadconnect_webhook_secret` | PASS |
| HPOS compatibility | Bootstrap\Plugin + Constraint § HPOS + Integration row | n/a | HPOS `wp_wc_orders_meta` | PASS |
| Single-shop per WP installation | Settings via `wp_options` + Out-of-Scope explicit | n/a | `wp_options` | PASS |
| i18n (English source + de_DE.po) | Architecture Layers § Infrastructure - i18n; Constraint § English source | n/a | text-domain `spreadconnect-pod` | PASS |
| Webhook async ACK (8s/202/`[accepted]`) | API Design § Inbound + Constraint + business-flow diagram | `POST /webhook` returns 202 + body `[accepted]` | n/a | PASS |
| Idempotency-Strategie (deterministic event_id hash + UNIQUE constraint) | Server Logic § EventIdHasher + DB schema § `wp_spreadconnect_webhook_log.event_id CHAR(64) UNIQUE` | n/a | `uniq_event_id` UNIQUE | PASS |
| Race protection (LWW + compare-and-set + as_unschedule_action) | Server Logic § OrderStateMachine + Constraint § Race-Protection | n/a | atomic UPDATE on order-meta | PASS |
| HMAC-SHA256 over raw body, X-SPRD-SIGNATURE, hash_equals | Server Logic § WebhookSignatureVerifier + Security § Inbound webhook | n/a | n/a | PASS |
| Cron-Context-Includes for media_sideload_image | Constraints + Server Logic § ImageSideloader::ensureAdminIncludesLoaded | n/a | n/a | PASS |
| Live-Progress AJAX endpoint | API Design § Inbound `/sync-progress` + Admin AJAX § `spreadconnect_sync_now` | `GET /wp-json/spreadconnect/v1/sync-progress` | transient `sc_sync_log_tail_{run_id}` | PASS |
| Cancel-sync (cooperative) | Admin AJAX § `spreadconnect_cancel_sync` + Risks § Cancel-Sync zombie rows | n/a | `wp_spreadconnect_sync_history.state='canceled'` | PASS |
| Article-Picker (debounced server-side search + fallback) | Open Q9 resolution + Validation Rules + AJAX `spreadconnect_link_article` | `GET /articles?search=…` with B-fallback | `_spreadconnect_article_id` | PASS |
| Dismiss-Resolution-Modal (3-choice for create_order) | Admin AJAX § `spreadconnect_resolve_create_order` + AJAX § `spreadconnect_dismiss_failed_op` (refused for create_order) | n/a | `wp_spreadconnect_failed_ops` | PASS |
| Bulk-Resend-Outcome-Panel | Server Logic § BulkResendCoordinator | AJAX `spreadconnect_resend_failed_op` | n/a | PASS |
| Auto-Confirm-Pending indicator + cancel button | Admin AJAX § `spreadconnect_cancel_auto_confirm` + AS hook `confirm_order` | n/a | reads AS row | PASS |
| Dashboard failure breakdown | Server Logic § Hub\View aggregate query + indexes (`idx_state_op_type`) | n/a | `wp_spreadconnect_failed_ops` | PASS |
| Save-Success-Panel (stepwise) | Implicit in `SubscriptionManager::repair()` partial-success surface; Risks § "4xx during POST /subscriptions" + Save flow | n/a | n/a | PASS |
| Initial-Secret-Reveal-Panel | Server Logic § WebhookSecretManager (one-time-reveal payload) | n/a | option `spreadconnect_webhook_secret` | PASS |
| Settings Export/Import JSON | Admin AJAX § `spreadconnect_export_settings` + `_import_settings` | n/a | reads `spreadconnect_*` options | PASS |
| Failed-Ops-Page (Resend/Dismiss/Bulk/Detail) | Hub\View § Failed | AJAX `spreadconnect_resend_failed_op`, `_dismiss_failed_op` | `wp_spreadconnect_failed_ops` | PASS |
| Webhook-Log Page | Hub\View § Webhooks | n/a | `wp_spreadconnect_webhook_log` | PASS |
| Subscriptions Manager + Repair-All | Hub\View § Subscriptions + AJAX `spreadconnect_repair_subscriptions` | `GET/POST/DELETE /subscriptions` | n/a | PASS |
| Logs (WC_Logger reader) | Hub\View § Logs + Infrastructure § WcLoggerAdapter | n/a | `wc-logs/` | PASS |
| Activation: Custom-Tables, Default-Options, Cron-Jobs | Bootstrap\Plugin + Bootstrap\Schema::dbDelta() + AS hook inventory | n/a | 3 custom tables + 18 options | PASS |
| Uninstall: Drop tables + Delete options + Subscriptions cleanup | Migration Map § uninstall.php + AVOID-list § secret excluded from export | DELETE /subscriptions/{id} (with confirm) | uninstall.php | PASS |
| Permissions: manage_woocommerce + nonce | Security § Authentication & Authorization | n/a | n/a | PASS |
| `spreadconnect_force_repull_images` (Open Q4 resolution) | WP Options table + Open Q decisions | n/a | option | PASS |
| Drift-detection (weekly subscription check) | AS hook inventory § `auto_subscription_check` | `GET /subscriptions` | persistent admin-notice | PASS |
| Retention purge (daily) | AS hook inventory § `purge_old_logs` + WP Options retention keys | n/a | all 3 custom tables | PASS |
| Per-Variation stock check + bulk-only refresh | Server Logic § LiveStockRefresher + Open Q10 resolution (bulk primary, per-SKU forbidden) | `GET /stock` bulk filter | `_spreadconnect_stock_cached*` variation meta | PASS |

**Verdict:** All Discovery features mapped. All 12 wireframe screens have backing API/service/data. PASS.

---

## B) Constraint Mapping

Every Discovery business rule + NFR has a technical solution:

| Constraint | Source | Wireframe Ref | Architecture | Status |
|------------|--------|---------------|--------------|--------|
| HMAC-SHA256 verify (constant-time) | Discovery § Webhooks | Screen 7 ④ Regenerate | Server Logic § WebhookSignatureVerifier; Security § Inbound webhook | PASS |
| HMAC-Secret 32 bytes base64 | Discovery § Webhooks (line 553) | Screen 7 § initial_secret_reveal_panel | WP Options table + WebhookSecretManager (`random_bytes(32)` + base64) | PASS |
| Bearer-Token Auth | Discovery § API & Security | Screen 7 ① API Key | API Design § Outbound (`Authorization: Bearer <api_key>`) | PASS |
| Rate-Limit awareness (X-RateLimit-Remaining ≤ 5 sleep + Retry-After 429) | Discovery § API & Security | n/a (background) | API Design § Outbound rate-limiting + Single-Retry-Layer + Trade-offs | PASS |
| Single Retry-Layer (AS only, 4xx fail-fast, 5xx retried) | Discovery § API & Security (line 557–561) | n/a | Server Logic § OrderSubmitJob (transient/permanent error split) + Trade-offs § Single Retry Layer | PASS |
| HPOS-Declare via FeaturesUtil on before_woocommerce_init | Discovery § HPOS-Compliance | n/a | Bootstrap\Plugin + Integration row + Risks § HPOS race | PASS |
| Order-Meta only via $order->update_meta_data() | Discovery § HPOS-Compliance (line 565) | n/a (background) | Constraint § HPOS + AVOID #1 + WC-Order Meta table preface | PASS |
| Order-List dual hook-sets (HPOS+Legacy) | Discovery § HPOS (line 566) | Screen 12 ALL columns | Constraint § dual hook-sets + Inline\OrderListColumns | PASS |
| Catalog: Pull-only | Discovery § Catalog Sync | Screen 2 [Sync now] | Scope: in-scope row 3; Out-of-scope row 1 | PASS |
| Catalog idempotent upsert | Discovery § Catalog Sync (line 570) | Screen 2 status counts | Server Logic § ProductMapper; Quality Attrs § Idempotency-Order Submit and -Catalog (per-article) | PASS |
| WC-Preis NEVER overwritten by sync | Discovery § Catalog Sync (line 571) | Screen 9 ④ "live" WC Price | Constraint § Catalog Sync + ProductMapper note | PASS |
| Image sideload only on first sync (toggleable) | Discovery § Catalog Sync (line 573) | Screen 2 § "Force re-pull images" toggle | WP Options § `spreadconnect_force_repull_images` (default false) | PASS |
| Cron-Context-Includes for media_sideload_image | Discovery § Catalog Sync (lines 574–580) | n/a (background) | Constraint row + Server Logic § ImageSideloader::ensureAdminIncludesLoaded | PASS |
| Article.removed → WC `draft` (no delete) | Discovery § Catalog Sync (line 581) | Screen 9 state `removed_in_sc` | Server Logic § ArticleRemovedJob + Risks § "Article.removed triggers WC product deletion" | PASS |
| Attribut-Slugs fix `pa_groesse`/`pa_farbe` | Discovery § Catalog Sync (line 582) | n/a (data layer) | Constraint + Server Logic § AttributeProvisioner; Trade-offs § slugs fixed | PASS |
| SKU-Format SC-SKU 1:1 | Discovery § Catalog Sync (line 583) | Screen 9 ⑤ SKU column | DTO `Variant.sku` + WC-Variation Meta `_spreadconnect_sku` | PASS |
| Order auto-create on `processing` (idempotent) | Discovery § Order Lifecycle | Screen 11 ⑤ "Submitted to SC" note | Server Logic § OrderHandler + OrderSubmitJob idempotency-skip | PASS |
| SC-Order created in `NEW` (manual confirm) | Discovery § Order Lifecycle | Screen 11 ③ State badge | Server Logic § Order business-flow diagram + AS hook `confirm_order` | PASS |
| Confirm-Pre-Check (Shipping-Type required) | Discovery § Order Lifecycle (line 588) | Screen 11 ⑤ Shipping (only state NEW) | Validation Rules + AJAX `spreadconnect_confirm_order` | PASS |
| Default-Shipping-Type at create_order | Discovery § Order Lifecycle (line 589) | Screen 7 ⑥a + Screen 11 § "default_shipping_prefilled" | DTO `OrderCreate.shippingType?` + Open Q11 A-with-B-fallback | PASS |
| Auto-Confirm-Gating (server-side enforced) | Discovery § Order Lifecycle (line 590) | Screen 7 ⑥b state `auto_confirm_locked` | Validation Rules + Constraint § Default-Shipping-Type-gates-AC | PASS |
| Auto-Confirm-Pre-Check-Failure-Handling (no SC ship-type) | Discovery § Order Lifecycle (line 591) | Screen 11 state `auto_confirm_failed_no_shipping` | OrderConfirmJob + Notice + Order-Note (dedicated path, not generic DLQ) | PASS |
| Cancel only when state=NEW | Discovery § Order Lifecycle | Screen 11 ⑦ "Cancel only state NEW" | Constraint + Validation + Quality Attr § "Allowed only when state=NEW" | PASS |
| WC-Cancel-Mirror only when state=NEW | Discovery § Flow D | n/a (background) | Constraint § Race-Protection + AS hook `cancel_order_mirror` | PASS |
| WC-Cancel ↔ Auto-Confirm Race-Schutz (`as_unschedule_action`) | Discovery § Order Lifecycle (line 594) | Screen 11 § `cancel_auto_confirm_button` | Constraint § Race-Protection + AJAX `spreadconnect_cancel_auto_confirm` | PASS |
| Webhook 8s/202/`[accepted]` ACK | Discovery § Webhooks (line 601) | n/a | API Design § Webhook ACK contract + Quality Attr § Webhook responsiveness + business-flow | PASS |
| Idempotency: deterministic event_id (sha256) | Discovery § Webhooks (line 604) | n/a | Server Logic § EventIdHasher + DB schema CHAR(64) UNIQUE + Open Q13 | PASS |
| Subscription-Repair only orphans on our URL | Discovery § Webhooks (line 605) | Screen 8 ⑤ policy hint | Server Logic § SubscriptionManager + Risks § "Repair never deletes foreign URLs" | PASS |
| Race Protection: LWW + Compare-and-Set | Discovery § Race Protection table | n/a (background) | Server Logic § OrderStateMachine + Constraint table 5-row matched | PASS |
| Stock Hybrid (live cache + periodic + webhook) | Discovery § Stock Sync | Screen 9 ⑤ Stock (cached 5min) | Server Logic § StockCache + StockSyncJob + LiveStockRefresher | PASS |
| Stock Live-Cache TTL configurable 60–900s | Discovery § Stock Sync (line 624) | Screen 7 ⑦ "Live-Cache TTL" | WP Options + Validation Rules § Stock TTL clamp | PASS |
| Failure Auto-Retry 1m/5m/15m | Discovery § Failure Handling | n/a (background) | AS hook inventory § "1m/5m/15m on 5xx/network" | PASS |
| Permanent Failure → DLQ entry | Discovery § Failure Handling | Screen 5 entire screen | DB schema § failed_ops + Server Logic § FailedOpsRepo | PASS |
| Email Notification on Permanent Failure | Discovery § Failure Handling | Screen 7 ⑧ Failure Notifications | Server Logic § FailureNotifier + WP Options notify_emails | PASS |
| Order-Failure Persistent Admin-Notice (Mark-Resolved) | Discovery § Failure Handling | Screen 1 ① + Screen 11 ① | Server Logic § AdminNoticeStore | PASS |
| Dismiss-Semantik per Op-Type (3-choice for create_order) | Discovery § Failure Handling (line 631–639) | Screen 5 § dismiss_modal_create_order_open | Admin AJAX § `spreadconnect_dismiss_failed_op` (refused) + `_resolve_create_order` 3-branch | PASS |
| Bulk-Dismiss respects per-op-type-rule | Discovery § Failure Handling (line 640) | Screen 5 § bulk_dismiss_blocked | Server Logic § BulkResendCoordinator + AJAX | PASS |
| Failed-Ops-Retention 90d default | Discovery § Failure Handling (line 641) | Screen 7 ⑧ retention | WP Options + AS hook `purge_old_logs` | PASS |
| i18n: English source + de_DE.po | Discovery § i18n | wireframes top-of-file note | Architecture Layers § Infrastructure i18n + Quality Attrs § i18n + Trade-offs | PASS |
| Plugin-Activation: Tables + Defaults + Cron | Discovery § Plugin-Activation | n/a | Bootstrap\Plugin + Bootstrap\Schema::dbDelta + AS recurring registration | PASS |
| Plugin-Deactivation: cron-remove (NOT subscriptions) | Discovery § Plugin-Activation | n/a | Bootstrap\Plugin (deactivation hook) | PASS |
| Plugin-Uninstall: drop tables + delete options + delete subs | Discovery § Plugin-Activation | n/a | Migration Map § uninstall.php | PASS |
| Permissions: manage_woocommerce + HMAC-public | Discovery § Permissions | n/a | Security § Auth & Authorization | PASS |
| Webhook-Mapping via reverse-lookup `_spreadconnect_order_id` | Discovery § Webhooks (line 850) | n/a | API Design § Outbound row 7 + Constraint § HPOS reverse-lookup | PASS |
| WC-Cancel ↔ Auto-Confirm Race-Schutz applied **before** scheduling cancel-mirror | Discovery line 594 | n/a | Constraint § Race-Protection (centralized via OrderStateMachine + as_unschedule_action) | PASS |
| `_spreadconnect_state` ↔ `_spreadconnect_needs_action` orthogonal | Discovery § Order Lifecycle + Open Q12 | Screen 11 ⑥ "needs action" + ③ State | Open Q12 = B (orthogonal flag) + WC-Order Meta table | PASS |
| Sync-History `details` JSON schema | Discovery § Data | Screen 2 ⑧ Per-article tables | DB schema details JSON shape (matched 1:1) | PASS |
| Custom Table indexes (failed_ops, webhook_log, sync_history) | Discovery § Data | n/a | DB Schema § all 3 tables (full index lists, including composite for "last 5 events for order") | PASS |
| Sync-Job-Cancel state | Discovery § Sync-Job State | Screen 2 § cancel_confirm_open | AS hook `sync_catalog` + AJAX `spreadconnect_cancel_sync` | PASS |
| WP Options 18 keys (full set incl. dependency rules) | Discovery § Data | Screen 7 ALL fields | WP Options table (18 keys + 1 ETag) | PASS |
| WC-Variation Meta (5 keys) | Discovery § Data | Screen 9 ⑤ Stock per-variation | WC-Variation Meta table | PASS |
| WC-Product Meta (7 keys) | Discovery § Data | Screen 9 ②③⑥ | WC-Product Meta table | PASS |
| WC-Order Meta (7 keys, HPOS) | Discovery § Data | Screen 11 all blocks | WC-Order Meta table + HPOS-only access disclaimer | PASS |
| Webhook ↔ WC-Order mapping via `_spreadconnect_order_id` reverse-lookup | Discovery § Webhook → WC-Order Mapping (line 850) | Screen 11 ⑩ Webhook activity | Server Logic § OrderEventHandler | PASS |
| Per-order shipping-type list (5-min transient) | Discovery § Order Lifecycle (line 156) | Screen 11 ⑤ Type dropdown | Transient `sc_shipping_types_{order_id}` 5min | PASS |

### NFR Mapping (Non-functional)

| NFR | Discovery Anchor | Architecture Solution | Status |
|---|---|---|---|
| Webhook ACK ≤ 8s | Discovery line 601 | Quality Attrs row 1 + business-flow async deferral | PASS |
| 100+ articles in single sync, no PHP timeout | Discovery solution paragraph | Quality Attrs row 2 + AS batching/concurrency | PASS |
| 3 retries → DLQ + email | Discovery § Failure Handling | Quality Attrs row 3 + AS retry-policy | PASS |
| Webhook idempotency (deterministic) | Discovery § Webhook idempotency | Quality Attrs row 4 + UNIQUE constraint | PASS |
| Order-Submit idempotency | Discovery § Order Lifecycle | Quality Attrs row 5 + skip-when-meta-exists + state-machine CAS | PASS |
| HMAC constant-time | Discovery § Webhooks | Quality Attrs row 6 + Server Logic § WebhookSignatureVerifier | PASS |
| Cap + nonce on admin actions | Discovery § Permissions | Quality Attrs row 7 | PASS |
| Live stock cache 60–900s | Discovery § Stock Sync | Quality Attrs row 8 + transients | PASS |
| Hub Dashboard counts at-a-glance | Discovery UI | Quality Attrs row 9 + indexed columns | PASS |
| Structured WC_Logger (no error_log) | Discovery i18n + AVOID #6 | Quality Attrs row 10 + Architecture Layers § Logging | PASS |
| HPOS Compliance | Discovery § HPOS | Quality Attrs row 12 + multiple constraint rows | PASS |
| Concurrency safety (5-row race table) | Discovery § Race Protection | Quality Attrs row 13 + OrderStateMachine | PASS |
| Maintainability (PSR-4 + 6-layer DDD-light) | Discovery scope + CLAUDE.md | Quality Attrs row 14 + Architecture Layers | PASS |

**Verdict:** All Discovery business rules and NFRs mapped to a technical solution. PASS.

---

## C) Realistic Data Check

### Codebase Evidence

```
# v1 plugin patterns (existing — to be deleted, but shape is REUSED):
- patchwork.json redefinable-internals: ['sleep', 'error_log', 'json_encode', 'json_decode',
    'json_last_error', 'rawurlencode', 'hash_equals', 'defined', 'sprintf']
- composer.json: phpunit/phpunit ^11.0, brain/monkey ^2.6 (pinned constraints — REUSE)
- v1 SpreadconnectApiClient sends Authorization: $api_key (raw — v2 corrects to "Bearer <key>")
- v1 SpreadconnectApiClient timeout=30, max_retries=3, base=https://api.spreadconnect.com
  (v2 changes base to https://rest.spreadconnect.com per OpenAPI spec)
- Order-meta keys reused 1:1: _spreadconnect_order_id, _spreadconnect_tracking_number, _spreadconnect_tracking_url
- Frontend variant-utils.ts:4-5 defines pa_groesse / pa_farbe slugs (German fixed)

# WP/WC platform pinning:
- docker-compose.yml: image: wordpress:6.9-php8.2-apache (verified WP 6.9 = current stable May 2026
  per WordPress.org)
- WC: latest stable in WordPress.org plugin repo (verified WC 10.5.x is current stable Feb–May 2026)

# Storage platform defaults (relevant for unbounded fields):
- WP wp_options.option_value: LONGTEXT (4 GB) — handles arbitrary Bearer tokens, base64 secrets, JSON exports
- WP wp_postmeta.meta_value: LONGTEXT
- WC HPOS wp_wc_orders_meta.meta_value: longtext
- WP wp_term_taxonomy / native: standard
```

### External API Analysis (Spreadconnect Fulfillment-API v2.3.9)

| API | Field | Measured / Documented Length | Sample (per OpenAPI) | Architecture Type | Recommendation |
|-----|-------|------------------------------|----------------------|-------------------|----------------|
| SC | `Article.id` | OpenAPI declares as `string` (not contract-fixed length); empirical samples ≤ 12 chars (digit IDs like `88421`) | `"88421"`, `"92043"` | postmeta default `LONGTEXT` (`_spreadconnect_article_id`) — refuses to assume length | PASS — defensive choice, no false ceiling |
| SC | `Order.id` | OpenAPI `string`; empirical samples digit-strings `"SC-77194"`, `"77194"` | `"77194"` | HPOS ordermeta default `longtext` (`_spreadconnect_order_id`) | PASS — defensive |
| SC | `Subscription.id` | OpenAPI `string` (e.g. `"sub_a1b2…"`) | `"sub_a1b2c3"` | Not persisted in WP DB beyond transient cache; held only in `Subscription` DTO + transient `sc_shipping_types_*` (per-order) | PASS — DTO holds string |
| SC | `shippingType.id` | OpenAPI `string`. SC-Doku notes: per-ProductType returns either numeric IDs (e.g. `"29"`) or enum-tags like `"STANDARD"` — depends on category | `"29"` or `"STANDARD"` | `_spreadconnect_shipping_type` ordermeta default `longtext`; option `spreadconnect_default_shipping_type` (LONGTEXT) | PASS — variable shape correctly accommodated |
| SC | `Shipment.trackingUrl` | Carrier-dependent; DHL/UPS occasionally embed multi-segment slugs ≥ 200 chars | DHL: `https://www.dhl.de/de/privatkunden/pakete-empfangen/verfolgen.html?piececode=…&abc=...` | `_spreadconnect_tracking_url` ordermeta default `longtext` | PASS — explicit `LONGTEXT` decision documented in Realistic Data Type Audit |
| SC | `Preview.imageUrl` (presigned) | Presigned URLs from CDN can be 500-1000+ chars (signature payloads + expiry params) | n/a (transient) | NOT persisted — consumed immediately by `media_sideload_image()` | PASS — short-lived URL, never stored |
| Plugin-internal | webhook `event_id` (sha256-hex) | sha256 hex digest = 64 chars exactly, fixed-length | `"a3f5…64chars"` | `wp_spreadconnect_webhook_log.event_id` `CHAR(64)` UNIQUE | PASS — Open Q13 explicitly evaluated VARCHAR(64) vs CHAR(64), CHAR chosen for fixed-length | 
| Plugin-internal | HMAC secret (base64 of 32 bytes) | base64(32) = 44 chars (incl. padding) | `"AbC…44chars=="` | option `spreadconnect_webhook_secret` (LONGTEXT) | PASS — overshoot acceptable, no risk |
| SC | API key (Bearer token) | Variable length per SC issuance — not documented as fixed | `"sk_live_XYZ…"` | option `spreadconnect_api_key` (LONGTEXT) | PASS — defensive |
| SC | webhook header `X-SPRD-SIGNATURE` | base64-encoded SHA256 over body = 44 chars | `"AbCdEf…44chars="` | NOT persisted (verified-only); raw body kept after verify | PASS |
| SC | `Article.title`, `description` | OpenAPI `string` (no max documented) | `"Bio-Shirt Unisex"` | DTO string; mapped to WP-Product `post_title` (`varchar(255)` per WP core) and `post_content` (`longtext`) | PASS — WP core handles |
| SC | `Address.zipCode`, `country` (ISO-3166-1 alpha-2) | country = exactly 2 chars; zipCode varies | `"DE"`, `"10115"` | DTO `Address` validation: `country` 2-char ISO check | PASS |
| SC | `Money.amount` (decimal as string) | regex `^\d+\.\d{2}$` per architecture | `"12.34"` | DTO `Money.amount` validation | PASS — strict regex avoids float-precision loss |
| Plugin-internal | `wp_spreadconnect_failed_ops.error_message` | Free-form errors; potentially long stack traces | n/a | `TEXT` (64 KB) | PASS — sufficient for typical error strings |
| Plugin-internal | `wp_spreadconnect_failed_ops.payload` | JSON encoding of full action arg-set; may include article-array on bulk ops | n/a | `LONGTEXT` (4 GB) | PASS — explicit unbounded choice |
| Plugin-internal | `wp_spreadconnect_webhook_log.payload` | Raw SC body — variant-arrays may grow | n/a | `LONGTEXT` | PASS — explicit unbounded choice |
| Plugin-internal | enum columns (e.g. `state`, `op_type`, `processing_status`) | App-enforced enums | `"unresolved"`, `"create_order"` | `VARCHAR(16)`/`(32)`/`(64)` per column | PASS — bounded by enum strings (longest = `handle_article_removed` = 22 chars; `op_type` = `VARCHAR(64)` is generous) |

### Data Type Verdicts

| Field | Arch Type | Evidence | Verdict | Issue |
|-------|-----------|----------|---------|-------|
| `wp_spreadconnect_webhook_log.event_id` | `CHAR(64)` | sha256-hex always 64 chars (industry standard) | PASS | none — explicit Open Q13 trade-off recorded |
| `wp_spreadconnect_webhook_log.payload` | `LONGTEXT` | Unbounded SC payloads with variant-arrays | PASS | — |
| `wp_spreadconnect_failed_ops.payload` | `LONGTEXT` | Unbounded job payloads | PASS | — |
| `wp_spreadconnect_failed_ops.op_type` | `VARCHAR(64)` | Longest enum = `handle_article_removed` (22 chars) | PASS | — |
| `wp_spreadconnect_failed_ops.related_entity_id` | `VARCHAR(64)` | SC IDs not contract-fixed; observed ≤ 12 | PASS | — |
| `wp_spreadconnect_failed_ops.error_code` | `VARCHAR(64)` | WP_Error code prefix `spreadconnect_*` (longest = `spreadconnect_missing_article_id` = 32 chars) | PASS | — |
| `wp_spreadconnect_failed_ops.state` | `VARCHAR(16)` | enum {`unresolved`,`resolved`,`dismissed`} (longest=10) | PASS | — |
| `wp_spreadconnect_webhook_log.event_type` | `VARCHAR(64)` | Longest = `Order.needs-action` (18 chars), `Article.added` (13 chars), `Shipment.sent` (13 chars) | PASS | — |
| `wp_spreadconnect_webhook_log.related_entity_type` | `VARCHAR(32)` | enum {`order`,`article`,`shipment`} | PASS | — |
| `wp_spreadconnect_webhook_log.related_entity_id` | `VARCHAR(64)` | SC IDs ≤ 12 observed; defensive overshoot | PASS | — |
| `wp_spreadconnect_webhook_log.hmac_status` | `VARCHAR(16)` | enum {`valid`,`invalid`} | PASS | — |
| `wp_spreadconnect_webhook_log.processing_status` | `VARCHAR(16)` | enum {`success`,`error`,`pending`,`duplicate`} | PASS | — |
| `wp_spreadconnect_webhook_log.processing_error` | `TEXT` | Free-form processing-error messages (typical < 64 KB) | PASS | — |
| `wp_spreadconnect_sync_history.trigger` | `VARCHAR(32)` | enum {`manual`,`webhook`,`scheduled`,`initial`} | PASS | — |
| `wp_spreadconnect_sync_history.state` | `VARCHAR(16)` | enum {`pending`,`in_progress`,`complete`,`failed`,`canceled`} (longest=11) | PASS | — |
| `wp_spreadconnect_sync_history.details` | `LONGTEXT` | JSON array per article in run (unbounded by article count) | PASS | — |
| `_spreadconnect_article_id` (postmeta) | WP default `LONGTEXT` | Architecture refuses to assume tighter (per Realistic Data Audit) | PASS | — |
| `_spreadconnect_order_id` (HPOS ordermeta) | HPOS default `longtext` | Same | PASS | — |
| `_spreadconnect_tracking_number` | HPOS default `longtext` | Carrier-dependent; e.g. DHL 20 chars, UPS 18 — unbounded by contract | PASS | — |
| `_spreadconnect_tracking_url` | HPOS default `longtext` | Carrier URLs can embed long params | PASS | — |
| `_spreadconnect_shipping_type` | HPOS default `longtext` | `shippingType.id` is `string` per OpenAPI — variable | PASS | — |
| `_spreadconnect_state` | HPOS default `longtext` | enum 6 values; default storage type fine | PASS | — |
| `_spreadconnect_needs_action` | HPOS default `longtext` (bool stored as 0/1 string) | bool | PASS | — |
| `_spreadconnect_last_event` | HPOS default `longtext` (`<unix>:<event-type>` ≈ 40 chars) | format string | PASS | — |
| `option spreadconnect_api_key` | WP `wp_options.option_value` (LONGTEXT) | Bearer-token format unspecified | PASS | — |
| `option spreadconnect_webhook_secret` | WP `wp_options.option_value` (LONGTEXT) | base64(32) = 44 chars | PASS | — |
| Transient `sc_pt_{id}` 24h | WP transient (LONGTEXT) | ProductType-detail JSON | PASS | — |
| Transient `sc_stock_{sku}` (60–900s) | WP transient | int (4 bytes effective) | PASS | — |
| `Preview.imageUrl` (DTO only) | not persisted | Presigned URL → consumed immediately | PASS | — |

**Verdict:** All persisted fields use realistic, defensively-sized types. The two architecture-recorded explicit decisions (CHAR(64) for sha256 event_id; LONGTEXT for unbounded payloads) match best practice. No data-type ceiling will be exceeded by SC payloads. PASS.

---

## D) External Dependencies

### D1) Dependency Version Check

**Project type:** Existing — `wordpress/plugins/spreadconnect-pod/composer.json` exists; `docker-compose.yml` pins WP image; this is REUSE of an existing pinned stack, not a greenfield framework selection. Architecture Slice 1 keeps the same plugin directory + same composer scope but greenfields the contents (per Discovery's "Greenfield within plugin scope").

| Dependency | Arch Version | Pinning File | Pinned? | "Latest"? | Actual Latest (verified) | Current? | Status |
|------------|-------------|--------------|---------|-----------|---------------------------|----------|--------|
| Spreadconnect Fulfillment-API | **v2.3.9** | architecture.md (Integrations table) | Yes | No | v2.3.9 (rest.spod.com / api.spod.com docs return v2.3.9 spec; Open API v3.0.2) | Yes | PASS |
| WordPress | **6.9** | docker-compose.yml `wordpress:6.9-php8.2-apache` | Yes | No | 6.9.x (WP 6.9 "Gene Harris" released 2 Dec 2025; 6.9.1 currently shipped — current stable as of May 2026) | Yes | PASS |
| WooCommerce | **10.5+** | architecture.md (Integrations table) | Yes (lower bound) | No | 10.5.3 latest dot-release (Mar 2026) | Yes | PASS |
| PHP | **8.2+** | docker-compose.yml `php8.2-apache` + composer.json `php: >=8.2` | Yes | No | 8.2 minimum (8.4 latest; constraint is correct since WP 6.9 and WC 10.5 still officially support PHP 8.2) | Yes | PASS |
| Action Scheduler | **bundled with WC ≥ 3.0**, current ≈ 3.8.x with WC 10.5 | architecture.md (Integrations table) | Yes (bundled) | No | bundled — runs whatever AS WC 10.5.x ships | Yes | PASS |
| Brain\Monkey | **^2.6** | wordpress/plugins/spreadconnect-pod/composer.json | Yes | No | 2.7.0 (Feb 2026) — `^2.6` constraint allows 2.7.x; existing test bootstrap REUSED (codebase-scan REUSE #3) | Yes — REUSE-of-existing-pin | PASS |
| PHPUnit | **^11.0** | wordpress/plugins/spreadconnect-pod/composer.json | Yes | No | PHPUnit 12 (Feb 2025), PHPUnit 13 (Feb 2026) are newer; v1's existing pin `^11.0` is REUSED per codebase-scan REUSE #3 (same harness, same constraint). For an MVP that reuses a working pinned stack this is acceptable. | Yes — REUSE-of-existing-pin | PASS |
| Patchwork | **latest stable bundled with Brain\Monkey** | composer.json (transitive) + patchwork.json present | Yes (transitive) | No | bundled with Brain\Monkey 2.7 | Yes | PASS |
| Composer | **per composer.json** | composer.json | Yes (PSR-4 root + dev-deps) | No | n/a (build tool) | Yes | PASS |
| Mollie Payments for WooCommerce | **latest stable from WP plugin repo** | scripts/setup.sh:Step 6 (verified) | Yes (dynamic via WP plugin repo) | No | dynamic install via `wp plugin install` | Yes | PASS |
| Pinterest CAPI plugin (sibling) | n/a (co-located, already installed) | wordpress/plugins/pinterest-capi/composer.json | Yes | No | n/a — sibling already in repo | Yes | PASS |

**Verdict:** All dependencies have explicit version constraints. WP 6.9 confirmed current stable (May 2026) via WordPress.org. WC 10.5+ confirmed current stable (10.5.3) via WooCommerce Developer Blog. Brain\Monkey 2.6 / PHPUnit 11 are REUSE-of-existing-pin (codebase-scan REUSE #3) — not greenfield framework selection — and the constraints (`^2.6` allows 2.7; `^11.0` is held to PHPUnit 11 by choice for slice-test compatibility). PASS.

### D2) External APIs & Services

| Dependency | Rate Limits | Auth | Errors | Timeout | Status |
|------------|-------------|------|--------|---------|--------|
| Spreadconnect Fulfillment-API v2.3.9 | `X-RateLimit-Remaining` (proactive 1s sleep when ≤5); `X-RateLimit-Retry-After-Seconds` on 429 | `Authorization: Bearer <api_key>` | Single fail-fast retry on 429 within job; AS retries 1m/5m/15m on 5xx/network/timeout; 4xx permanent → DLQ | 30s per request (REUSE from v1; documented in Risks/Trade-offs) | PASS |
| WP REST API (inbound webhook) | none (HMAC + UNIQUE event_id are abuse barriers) | HMAC-SHA256 over raw body, header `X-SPRD-SIGNATURE`, base64 (no prefix), `hash_equals()` | 401 on HMAC-mismatch; 200 on duplicate; 202 + `[accepted]` on success | 8s ACK SLA (synchronous receiver only does HMAC + INSERT + AS-enqueue) | PASS |
| WC bundled Action Scheduler | default batch=25; concurrency=5; claim-cleanup=5min | n/a (in-process) | AS marks job `failed` on thrown SpreadconnectTransientError; no retry on SpreadconnectClientError | 30s/request default | PASS |
| WP Settings → AJAX endpoints | nonce single-use 12h | `manage_woocommerce` cap + `wp_verify_nonce` | nonce-fail = 403 | n/a | PASS |
| `media_sideload_image()` | none (WP-Core) | n/a | undefined-function fatal if includes missing — guarded by ImageSideloader::ensureAdminIncludesLoaded | n/a (timeout via download_url default 300s) | PASS |
| WC `wc_get_logger()` | n/a | n/a | n/a | n/a | PASS |
| Mollie Payments hooks (consumed) | n/a (just listening to `processing`) | n/a | hook fires once; idempotency-skip via meta-check | n/a | PASS |

**Verdict:** All external integrations have rate-limit, auth, error, and timeout strategies documented. PASS.

---

## E) Pattern Consistency (Gate 1b)

### Scanner Output Validation

| Check | Result | Notes |
|-------|--------|-------|
| Every AVOID item references a feature explicitly replacing the pattern | PASS | All 9 AVOID items in codebase-scan reference Discovery business rules that explicitly replace the pattern (e.g. AVOID #1 → Discovery → HPOS-Compliance line 565; AVOID #5 → Discovery → API & Security line 557) |
| Every REUSE item has count ≥ 2 OR is single-source-of-truth | PASS-with-note | REUSE #1 (Spreadconnect API client) has count 1; valid because it is the only source of an established pattern (extension target). REUSE #16 (POD Shop admin-notice) has count 1; valid as single-source. REUSE #18 (WP-CLI activation) has count 1; valid as single-source-of-truth. Other REUSE items have count ≥ 2. |
| Every recommendation has a concrete file path | PASS | All 21 patterns + 9 EXTEND/REUSE/AVOID/NEW recommendations cite specific paths in `wordpress/plugins/spreadconnect-pod/`, `wordpress/plugins/pinterest-capi/`, or `frontend/lib/product/variant-utils.ts` |

Scanner output is structurally plausible.

### REUSE Recommendations vs Architecture

| # | Scanner REUSE | Architecture Decision | Justified? | Status |
|---|---|---|---|---|
| R1 | Bilingual attribute slug constants `pa_groesse`/`pa_farbe` | Constraint § fixed slugs + Server Logic § AttributeProvisioner; Trade-offs § slugs fixed; Quality Attrs § HPOS reverse-lookup | Yes — explicit decision | PASS |
| R2 | Order-meta key namespace `_spreadconnect_*` | WC-Product/Variation/Order Meta tables + Migration Map (preserve naming for v2 forward-consistency); Constraint table row | Yes | PASS |
| R3 | Brain\Monkey test bootstrap | Integrations table § Brain\Monkey ^2.6; Tech Decisions § Test framework REUSE | Yes — explicit REUSE-with-stub-extension via codebase-scan EXTEND #6 | PASS |
| R4 | Slice-test directory layout `tests/slices/pod-shop-mvp/` | Migration Map: "tests/slices/pod-shop-mvp/ (REUSE layout)"; Discovery slices 1–10 map to `slice-01-foundation.php` … `slice-10-logs-polish.php` | Yes | PASS |
| R5 | composer.json PSR-4 + classmap split | Tech Decisions § Autoload (PSR-4 SpreadconnectPod\\); EXTEND #5 calls for switch from classmap to PSR-4 | Yes | PASS |
| R6 | Patchwork redefinable-internals list | Integrations table § Patchwork; Codebase-Scan EXTEND #6 adds `random_bytes`, `microtime`, `time` for v2 | Yes — extension explicit | PASS |
| R7 | mu-plugin pattern (headless-redirect) | Constraints + Integrations row "headless-redirect.php mu-plugin" + Risks row + Assumptions row (QA verify only); no mu-plugin change required | Yes — explicit "verified excludes REST_REQUEST" + Slice 3 QA test in Risks | PASS |
| R8 | `register_post_meta()` + WPGraphQL exposure | Architecture explicitly notes frontend is out-of-scope; pattern reserved | Yes — flagged as out-of-scope post-MVP | PASS |
| R9 | Idempotency-skip pattern (if meta exists return) | Server Logic § OrderHandler::on_processing + OrderSubmitJob (idempotency-check on `_spreadconnect_order_id`); business-flow diagram shows it | Yes | PASS |
| R10 | German `WP_Error` code prefix `spreadconnect_*` | Validation Rules + Error Handling matrix (`spreadconnect_http_error` referenced) + Conventions Detected; new codes (`spreadconnect_max_retries`, `spreadconnect_missing_article_id`) preserved | Yes | PASS |
| R11 | `wp_mail` admin-notify-on-failure | Server Logic § FailureNotifier wraps `wp_mail` + recipient list option | Yes — wrapped in real notifier (codebase-scan EXTEND #2 calls for it) | PASS |
| R12 | WP-CLI activation in scripts/setup.sh | Integrations table § Mollie/Composer + Migration Map § "REUSE existing stubs"; setup.sh continues activating plugin (codebase-scan integration row 5) | Yes | PASS |

### EXTEND Recommendations vs Architecture

| # | Scanner EXTEND | Architecture Decision | Justified? | Status |
|---|---|---|---|---|
| E1 | `SpreadconnectApiClient` — Bearer header, all 27 endpoints, remove inner 3x retry, ETag support | API Design § Outbound (all 31 wrappers); Server Logic § `Api\SpreadconnectClient`; Constraint § Single Retry Layer; AS retry policy; transient `sc_pt_{id}` 24h + `option spreadconnect_pt_index_etag` for ETag | Yes — every extension explicit | PASS |
| E2 | `SpreadconnectOrderService` — split into job-handlers, HPOS, state-machine | Server Logic § OrderHandler/OrderSubmitJob/OrderConfirmJob/OrderCancelMirrorJob/OrderStateMachine + Constraints § HPOS; FailureNotifier moved out | Yes | PASS |
| E3 | Plugin bootstrap `spreadconnect-pod.php` — HPOS-declare, activation/deactivation/uninstall, PSR-4 | Server Logic § Bootstrap\Plugin; Tech Decisions § Autoload PSR-4; Migration Map § new spreadconnect-pod.php v2.0.0 + uninstall.php | Yes | PASS |
| E4 | REST namespace `spreadconnect/v1` — keep, add `/sync-progress`, expand `/health` | API Design § Inbound (3 routes incl. `/webhook`, `/sync-progress`, `/health`) | Yes — exact match | PASS |
| E5 | Plugin-level composer.json — switch classmap → PSR-4 | Tech Decisions § Autoload; Migration Map § "switch v2's composer.json from classmap to psr-4 (Codebase-Scan EXTEND #5)" | Yes | PASS |
| E6 | Test bootstrap stubs — add WC_Product_Variable, WC_Logger, FeaturesUtil, AS, $wpdb; Patchwork additions | Integrations table § Patchwork (calls out additions explicitly: random_bytes, microtime, time); Tech Decisions § Test framework REUSE | Yes — exact match | PASS |
| E7 | German attr-slug helper — replace ad-hoc chain with constant | Constraint § fixed slugs; Server Logic § AttributeProvisioner | Yes | PASS |
| E8 | Slice-test parallel directory | Migration Map § slice-01..slice-10 | Yes | PASS |

### AVOID Recommendations vs Architecture

| # | Scanner AVOID | Architecture Decision | Justified? | Status |
|---|---|---|---|---|
| A1 | `update_post_meta`/`get_post_meta` for **order** data | Constraint § HPOS + WC-Order Meta table preface "(HPOS — via $order->update_meta_data() ONLY)" + AVOID #1 referenced by Discovery line 565 | Yes — explicit | PASS |
| A2 | `verify_webhook_signature` API-key-fallback + WP_DEBUG bypass | Server Logic § WebhookSignatureVerifier (HMAC + hash_equals, no debug bypass); Security § Inbound webhook explicitly "**No `WP_DEBUG` bypass.**"; Quality Attrs § Security HMAC-SHA256 constant-time | Yes — explicit | PASS |
| A3 | `wp_schedule_single_event` for SC dispatching | Tech Decisions § Background queue: "**Action Scheduler** … AVOIDS `wp_schedule_single_event` (transient + memory-only) per Codebase-Scan AVOID #3"; Open Q14 = A | Yes — explicit | PASS |
| A4 | `add_options_page` for Spreadconnect settings | Tech Decisions § Admin UI: "AVOIDS `add_options_page` AVOID #4"; Hub-Page is `add_submenu_page('woocommerce', …)` | Yes — explicit | PASS |
| A5 | Inner 3x HTTP retry with `sleep()` | Constraint § Single Retry Layer (AS only); API Design § Outbound (single fail-fast retry on 429); Trade-offs § Single Retry Layer; AVOIDed inner-3x-retry | Yes — explicit | PASS |
| A6 | `error_log()` calls | Tech Decisions § Logging "wc_get_logger() with sources spreadconnect-* … replaces error_log (AVOID #6)"; Quality Attrs § Observability "no `error_log()`"; Architecture Layers § WcLoggerAdapter | Yes — explicit | PASS |
| A7 | Single-class settings file procedural rendering | Architecture Layers § "MVC-light (Controller + View classes)"; Hub\Controller + Hub\View\* (8 view classes) | Yes — explicit | PASS |
| A8 | German `WP_Error` messages mixed with English context | Constraint + Tech Decisions § i18n: "All `__()` strings English; de_DE.po for translations"; Quality Attrs § i18n | Yes — explicit | PASS |
| A9 | Defaulting `spreadconnect_use_staging` to `true` | WP Options table: `spreadconnect_use_staging` Default `false`; Risks/Tech Decisions implicit "default off in production"; AVOID #9 referenced ("per AVOID #9") in WP Options table | Yes — explicit | PASS |

### NEW Recommendations vs Architecture

| # | Scanner NEW | Architecture Decision | Status |
|---|---|---|---|
| N1 | Action Scheduler integration (11 hooks listed) | AS Hook Inventory table (11 hooks: `sync_catalog`, `sync_article`, `handle_article_removed`, `create_order`, `confirm_order`, `cancel_order_mirror`, `fetch_tracking`, `process_webhook_event`, `scheduled_stock_sync`, `auto_subscription_check`, `purge_old_logs`) — exact match | PASS |
| N2 | Custom DB tables (3) + dbDelta + uninstall | DB Schema § 3 tables (failed_ops, webhook_log, sync_history) with full column + index lists; Bootstrap\Schema::dbDelta(); Migration Map § uninstall.php | PASS |
| N3 | HMAC-SHA256 verifier with hash_equals over raw body, X-SPRD-SIGNATURE, base64 no prefix | Server Logic § WebhookSignatureVerifier; Security § Inbound webhook | PASS |
| N4 | Webhook-ID deterministic hash + UNIQUE + 8s/202/`[accepted]` | Server Logic § EventIdHasher; DB Schema § event_id CHAR(64) UNIQUE; API Design § Inbound (202 + `[accepted]`) | PASS |
| N5 | HPOS compatibility declaration | Bootstrap\Plugin + Constraint + Integrations table | PASS |
| N6 | WC_Logger adapter with sources | Architecture Layers § WcLoggerAdapter (sources `spreadconnect-api-client`/`-order-service`/`-webhook-receiver`/`-sync-job`/`-failure`/`-cli`) | PASS |
| N7 | Hub-Page with 8 sections | Hub\Controller + Hub\View\* (8 sections) | PASS |
| N8 | Meta-Boxes (Product-Edit + Order-Edit) | Inline\ProductMetaBox + Inline\OrderMetaBox | PASS |
| N9 | Product-List + Order-List custom columns (dual hooks) | Inline\ProductListColumns + Inline\OrderListColumns + Constraint § dual hook-sets | PASS |
| N10 | Bulk-Action handlers | Architecture Layers § Inline\OrderListColumns (bulk-action) + BulkResendCoordinator + AJAX `spreadconnect_resend_failed_op` | PASS |
| N11 | WC Variable-Product upsert flow + media-sideload boilerplate | Server Logic § ProductMapper + ImageSideloader::ensureAdminIncludesLoaded | PASS |
| N12 | Live progress AJAX endpoint | API Design § Inbound `/sync-progress` + AJAX `spreadconnect_sync_now` + transient `sc_sync_log_tail_{run_id}` | PASS |
| N13 | HMAC secret manager (generate + show-once + regenerate-with-resubscribe) | Server Logic § WebhookSecretManager + Admin AJAX § `spreadconnect_regenerate_secret` | PASS |
| N14 | Subscriptions auto-register/repair + drift-detection | Server Logic § SubscriptionManager + AS hook `auto_subscription_check` (weekly) | PASS |
| N15 | Persistent dismissible-but-not-without-resolution Admin Notices + dismiss_resolution_modal | Server Logic § AdminNoticeStore + Admin AJAX § `spreadconnect_dismiss_failed_op` (refused for create_order) + `spreadconnect_resolve_create_order` (3-branch) | PASS |
| N16 | Live stock cache + bulk GET /stock | Server Logic § StockCache + LiveStockRefresher + Open Q10 (bulk) + transient `sc_stock_{sku}` | PASS |
| N17 | Failure-Recovery DLQ UI + per-op-type Resolution-Modal | Server Logic § FailedOpsRepo + AdminNoticeStore + Hub\View § Failed; AJAX 3-branch resolution | PASS |
| N18 | i18n: spreadconnect-pod text-domain + de_DE.po + load_plugin_textdomain | Architecture Layers § Infrastructure i18n; Quality Attrs § i18n | PASS |
| N19 | uninstall.php (drop tables, delete options, optionally subs) | Migration Map § "uninstall.php"; Tech Decisions § Greenfield row | PASS |

**Verdict:** All 12 REUSE, 8 EXTEND, 9 AVOID, and 19 NEW recommendations from the codebase-scan are explicitly addressed by the architecture (with justifications). PASS.

---

## F) Migration Completeness

**Scope check:** Architecture states "**N/A — Greenfield.** v1 plugin is deleted before v2 implementation begins (per Discovery Current State Reference: 'Greenfield im Plugin-Scope: spreadconnect-pod v1 wird vor v2-Implementierung gelöscht')."

Discovery confirms: "Greenfield" within plugin scope; v1 is deleted before v2 starts; no production data exists (dev-only Docker WP install). No migration map of files-to-be-renamed-and-modified is required because the scope is **delete-and-recreate**, not refactor-in-place.

The architecture's Migration Map section nonetheless documents (with file paths):
- `wordpress/plugins/spreadconnect-pod/` (entire directory) → DELETED before v2 starts; v2 reuses only the directory path; Slice 1 recreates skeleton with `composer.json` (PSR-4), main file `spreadconnect-pod.php` v2.0.0, `uninstall.php`, `tests/bootstrap/` (REUSE), `tests/slices/pod-shop-mvp/` (REUSE).
- `tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php` (root-level twin) → replaced by `slice-01-foundation.php` … `slice-10-logs-polish.php`.

These are concrete file paths with concrete actions (DELETE, RECREATE, REPLACE) — meeting the spirit of the Migration Map quality check for greenfield scope.

| Discovery Claim | Architecture Coverage | Status |
|---|---|---|
| "Greenfield — v1 wird gelöscht" | Migration Map: 2 entries explicitly addressing the directory deletion + slice-test replacement | PASS — N/A migration scope (greenfield) |

**Verdict:** PASS — N/A migration scope. The architecture correctly identifies this as a greenfield-within-plugin-scope and provides concrete delete/recreate guidance.

---

## G) Completeness Check (Template Sections)

The Architecture template has 12 required sections (per Sign-Off block). All are present with content:

| # | Template Section | Architecture Location | Status |
|---|---|---|---|
| 1 | Problem & Solution | top | PASS |
| 2 | Scope & Boundaries | scope tables | PASS |
| 3 | API Design | outbound 27 + 4 reserved + inbound 3 routes + admin AJAX 17 actions + DTOs | PASS |
| 4 | Database Schema | 3 custom tables + 18 options + meta tables + transients + realistic data audit | PASS |
| 5 | Server Logic | 37+ services across 6 layers + 2 business-flow diagrams + validation rules | PASS |
| 6 | Security | auth, data protection, input validation, rate limiting | PASS |
| 7 | Architecture Layers | layer responsibilities + AS hook inventory + data-flow diagram + error matrix | PASS |
| 8 | Migration Map | N/A — greenfield, with v1 deletion explained | PASS |
| 9 | Constraints & Integrations | 12 constraints + 14 integrations with versions | PASS |
| 10 | Quality Attributes (NFRs) | 14 NFR rows + observability metrics | PASS |
| 11 | Risks & Assumptions | 10 assumptions + 14 risks | PASS |
| 12 | Technology Decisions | 11 stack choices + 10 trade-offs | PASS |

Plus mandatory metadata sections:
| Section | Status |
|---|---|
| Open Questions | PASS — 14 questions, all DECIDED |
| Research Log | PASS — 16 entries with date + area + finding |
| Q&A Log | PASS — 14 entries |
| Sign-Off | PASS — explicit ✅ on every section |

**Verdict:** All 12 template sections + Q&A log + research log + sign-off present. PASS.

---

## Blocking Issues

**None.**

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0

**Confidence drivers:**
- Every Discovery feature traced to a named architecture component (~50 features mapped).
- Every Discovery business rule + NFR has a documented technical solution.
- Every codebase-scan REUSE/EXTEND/AVOID/NEW recommendation is addressed (12+8+9+19 = 48 items, 100% coverage).
- Realistic Data Check refuses to make false-ceiling assumptions for SC IDs (Article, Order, Subscription, shippingType) and uses defensive `LONGTEXT`/HPOS-default for all carrier-tracking and presigned URLs.
- Webhook event_id correctly sized to `CHAR(64)` (sha256-hex always exactly 64 chars).
- HMAC secret correctly sized via WP options `LONGTEXT` (overshoots base64-44).
- Both stack platforms (WP 6.9, WC 10.5+) verified as current stable via WebSearch (May 2026).
- All 11 Action Scheduler hooks from codebase-scan NEW#1 are present in the AS Hook Inventory.
- HMAC + 202 + `[accepted]` ACK contract is in three places (API Design, Constraint, business-flow diagram).
- Single Retry-Layer decision is consistently expressed across API Design (single 429 retry), AS hook inventory (1m/5m/15m on 5xx/network), Trade-offs, and Quality Attrs.

**Next Steps:**
- [ ] Proceed to Slice Planning (`/zen:planner`) — architecture is implementation-ready.
- [ ] During Slice 5 (Order Lifecycle): validate Open Q11 (`POST /orders` inline shippingType) on staging — fallback path is already coded.
- [ ] During Slice 6 (Stock Sync): validate Open Q1 (`Article.updated` on stock-only) on staging — already defensively defaulted to "periodic-required".
- [ ] During Slice 4 (Catalog Sync): validate Open Q9 (`?search=` server-side) on staging — fallback path is already coded.
- [ ] During Slice 3 (Webhook): QA-verify `headless-redirect.php` mu-plugin still excludes `/wp-json/spreadconnect/v1/webhook`.
