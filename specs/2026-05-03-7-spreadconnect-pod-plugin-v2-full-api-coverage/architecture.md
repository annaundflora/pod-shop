# Feature: Spreadconnect POD Plugin v2 — Full API Coverage

**Epic:** --
**Issue:** #7
**Status:** Ready
**Discovery:** `discovery.md` (same folder)
**Wireframes:** `wireframes.md` (same folder)
**Codebase Scan:** `codebase-scan.md` (same folder)
**Derived from:** Discovery constraints, NFRs, risks, business rules + Codebase-Scan REUSE/EXTEND/NEW/AVOID matrix.

---

## Problem & Solution

**Problem:**
- v1 covers only 2 of 27 SC REST endpoints; Article-Mgmt, Catalog-Sync, Designs-Read, Stock, Cancel, Confirm, Shipping-Type-Selection and Webhook-Subscriptions are absent.
- Article-IDs are pinned manually per WC product → unscalable beyond ~20 products.
- Webhook auth is a stub (`WP_DEBUG` bypass) — security risk.
- All work is synchronous in the request → PHP-timeout risk on bulk sync (same defect as the official SC plugin).
- No failure recovery, no margin display, no stock awareness, no multi-shop support.

**Solution:**
- Greenfield plugin (v1 deleted) covering **all 27 REST endpoints** + **7 webhook events** of Spreadconnect Fulfillment-API v2.3.9.
- Native WooCommerce surface: Hub-Page under `WooCommerce → Spreadconnect` + inline meta-boxes, list columns, bulk actions.
- Action-Scheduler-driven background queue for catalog sync, order operations, webhook processing — PHP-timeout-immune, retry-capable, observable.
- Auto webhook-subscription registration with HMAC-SHA256 verification.
- HPOS-compatible (mandatory since WC 8.2).

**Business Value:**
- Scaling enabler: pull-catalog-sync supports 100+ articles without manual WC product upkeep.
- Multi-shop reusable: plugin is codebase-independent → reusable across operator's POD shops.
- Operational safety: failure recovery, margin awareness, stock awareness — closes known gaps of the official plugin.

---

## Scope & Boundaries

| In Scope |
|----------|
| Full 27-endpoint REST coverage (Auth, Articles, Orders, Subscriptions, Simulations, ProductTypes, Stock, Designs-Read). |
| All 7 webhook events: `Shipment.sent`, `Order.cancelled`, `Order.processed`, `Order.needs-action`, `Article.added`, `Article.updated`, `Article.removed`. |
| Pull-only Catalog-Sync (SC → WC Variable-Products with `pa_groesse` + `pa_farbe` variations). |
| First-sync image-sideload via `media_sideload_image()` into WP Media Library. |
| Order lifecycle: auto-create on `processing`, manual confirm, auto cancel-mirror (WC→SC when state = `NEW`). |
| Hub-Page under `WooCommerce → Spreadconnect` with 8 sections (Dashboard, Catalog, Orders, Webhooks, Failed Operations, Logs, Settings, Subscriptions). |
| Inline extensions: Product-Edit Meta-Box, Product-List columns (Cost + Margin), Order-Edit Meta-Box, Order-List columns + filter + bulk action. |
| Hybrid stock sync: live cache (5 min) + periodic threshold-based (4×/day) + webhook (`Article.updated`). |
| Pricing: WC-price stays manual; SC-cost stored as meta + margin display on Edit-Page + List. |
| Default-Shipping-Type setting (gates Auto-Confirm). |
| Failure recovery: auto-retry (3× exp. backoff via Action Scheduler), DLQ UI with resend, email notice, order-note + admin-notice. |
| Auto-webhook subscription registration on Settings-Save with valid connection. HMAC secret one-time-shown on first save. |
| HPOS compatibility (`FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` on `before_woocommerce_init`). |
| Single-shop per WP install; multi-shop = multiple WPs. |
| i18n: English source + `de_DE.po` translation. |

| Out of Scope |
|--------------|
| Push-Sync (WC → SC article creation). |
| Bi-directional sync with conflict resolution. |
| Design-Upload UI (`POST /designs/upload`). |
| Full Design-Manager in WP-backend. |
| WP-Multisite-Network settings. |
| Configurable attribute slugs (fixed `pa_groesse`/`pa_farbe`). |
| Hotlink images (always local). |
| Category/ProductType-specific markup rules. |
| Migration of v1 order-meta. |
| Frontend Next.js changes. |
| Replacement of existing Spreadshirt frontend integration. |

---

## API Design

### Overview

| Aspect | Specification |
|--------|---------------|
| Outbound style | REST (Spreadconnect Fulfillment-API v2.3.9) over HTTPS, JSON bodies. |
| Outbound auth | `Authorization: Bearer <api_key>` per request (option `spreadconnect_api_key`). |
| Outbound base URL | `https://rest.spreadconnect.com` (prod) or `https://staging.spreadconnect.com` (staging) — toggle via option `spreadconnect_use_staging`. |
| Outbound rate-limiting | Honor `X-RateLimit-Remaining` (proactive 1 s sleep when ≤ 5) and `X-RateLimit-Retry-After-Seconds` on 429. Single fail-fast retry on 429 within job; further retries via Action Scheduler. |
| Inbound style | REST (`register_rest_route`) under namespace `spreadconnect/v1`. |
| Inbound auth (webhook) | HMAC-SHA256 over raw body, header `X-SPRD-SIGNATURE`, base64 (no `sha256=` prefix), `hash_equals()` constant-time compare. |
| Inbound auth (admin AJAX) | `manage_woocommerce` capability + WP nonce (`wp_create_nonce('spreadconnect_admin')`). |
| Webhook ACK contract | HTTP 202, body `[accepted]` literal, within 8 s — async processing. |

### Outbound: Spreadconnect REST Endpoints (27)

> All endpoints reached through `Api\SpreadconnectClient`. DTOs are typed PHP value objects (Request/Response). Discovery's "Trigger Inventory" is the authoritative caller list per endpoint. Out-of-scope endpoints are listed but not wired to callers in MVP.

| # | Method | Path | Request DTO | Response DTO | Caller(s) | Cache | Notes |
|---|--------|------|-------------|--------------|-----------|-------|-------|
| 1 | GET    | /authentication                                | — | `AuthOk{ok:bool}` | Settings → Test Connection; on Settings-Save | none | Verifies API-Key. 200=ok / 401=auth_failed. |
| 2 | GET    | /articles                                      | `ArticlesQuery{page,size,search?}` | `Page<ArticleSummary>` | Job `sync_catalog`; `article_picker` (debounced search) | none | Pagination cursor `page`/`size`. Server-side `search` filter — see Open Q9 resolution. |
| 3 | GET    | /articles/{id}                                 | path:`id` | `ArticleDetail` | Job `sync_article` | none (re-pulled each sync) | — |
| 4 | POST   | /articles                                      | — | — | — | — | **Out of scope MVP** (push-sync). Wrapper exists but no caller. |
| 5 | DELETE | /articles/{id}                                 | path:`id` | — | — | — | **Out of scope MVP**. |
| 6 | POST   | /orders                                        | `OrderCreate{externalOrderReference, orderItems[], billingAddress, shippingAddress, shippingType?, customerEmail?, phone?, taxType?}` | `OrderResponse{id, state, …}` | Job `create_order`; manual Resend | none | `state` always `NEW` on success. Inline `shippingType` per Open Q11 resolution = **A: inline accepted**. |
| 7 | GET    | /orders/{id}                                   | path:`id` | `OrderDetail` | "Refresh State" button; webhook-processing | none | — |
| 8 | PUT    | /orders/{id}                                   | — | — | — | — | **Out of scope MVP** (no order-edit-after-submit). |
| 9 | POST   | /orders/{id}/confirm                           | path:`id` | `OrderDetail` | Order-Edit "Confirm"; Auto-Confirm-Cron | none | State `NEW → CONFIRMED`. |
| 10 | POST  | /orders/{id}/cancel                            | path:`id` | `OrderDetail` | Order-Edit "Cancel"; WC-Cancel-Mirror | none | Allowed only when state=`NEW`. |
| 11 | GET   | /orders/{id}/shipments                         | path:`id` | `Shipment[]` | Webhook `Shipment.sent`; Order-Edit page-load | none | Reads tracking number/URL/carrier. |
| 12 | GET   | /orders/{id}/shippingTypes                     | path:`id` | `ShippingType[]{id, company, name, description, price{amount,taxRate,taxAmount,currency}}` | Order-Edit page-load (loads dropdown) | per-order transient, 5 min | Empty ID list ⇒ ProductType-bound enum strings. |
| 13 | POST  | /orders/{id}/shippingType                      | path:`id`, body:`{shippingType:string}` | `OrderDetail` | Order-Edit "Save Shipping Type" | none | — |
| 14 | GET   | /subscriptions                                 | — | `Subscription[]{id,eventType,callbackUrl,…}` | Subscriptions-Manager page-load; weekly drift check | none | — |
| 15 | POST  | /subscriptions                                 | `SubscriptionCreate{eventType, callbackUrl, secret}` | `Subscription` | Auto-Setup on Settings-Save; Repair-flow | none | Secret = `spreadconnect_webhook_secret`. |
| 16 | DELETE| /subscriptions/{id}                            | path:`id` | — | Repair-flow (only orphans whose URL = our URL); `uninstall.php` (with admin-confirm) | none | Never deletes foreign URLs. |
| 17 | POST  | /orders/{id}/simulate/order-cancelled          | path:`id` | `OrderDetail` | Settings → Dev-Tools (staging only) | none | UI hidden in production. |
| 18 | POST  | /orders/{id}/simulate/order-processed          | path:`id` | `OrderDetail` | Settings → Dev-Tools (staging only) | none | — |
| 19 | POST  | /orders/{id}/simulate/shipment-sent            | path:`id` | `Shipment` | Settings → Dev-Tools (staging only) | none | — |
| 20 | GET   | /productTypes                                  | — | `ProductTypeSummary[]` | Job `sync_article` (cached) | option `spreadconnect_pt_index_etag` (long TTL) | ETag/If-None-Match supported. |
| 21 | GET   | /productTypes/{id}                             | path:`id` | `ProductTypeDetail{sizes[], colors[], printAreas[], …}` | Job `sync_article` (cached, long TTL) | transient `sc_pt_{id}` 24 h + ETag | Hot endpoint — heavy cache. |
| 22 | GET   | /productTypes/{id}/views                       | path:`id` | `View[]` | Job `sync_article` (hotspot detection) | transient 24 h | — |
| 23 | GET   | /productTypes/{id}/size-chart                  | path:`id` | `SizeChart` | Optional Product-Edit lazy-load | transient 24 h | — |
| 24 | GET   | /productTypes/{id}/categories                  | path:`id` | `Category[]` | — | — | Reserved (catalog-browser, post-MVP). |
| 25 | GET   | /productTypes/categories                       | — | `Category[]` | — | — | Reserved. |
| 26 | GET   | /productTypes/{id}/hotspots/design/{designId}  | path:`id`,`designId` | `Hotspot` | Job `sync_article` | none | — |
| 27 | POST  | /productTypes/{id}/previews                    | path:`id`, body:`{designId, hotspotId, viewIds[]}` | `Preview[]` (URLs) | Job `sync_article` (image pull) | none | URLs are presigned & short-lived → consumed immediately by `media_sideload_image()`. |
| 28 | GET   | /stock                                         | `StockQuery{productTypeId?, skus[]?}` | `StockEntry[]{sku, quantity}` | Job `scheduled_stock_sync`; live-cache refresh on Product-Edit | transient `sc_stock_{sku}` 5 min (configurable 60–900 s) | **Bulk endpoint** per Open Q10 resolution. Never per-SKU loop. |
| 29 | GET   | /stock/{sku}                                   | path:`sku` | `StockEntry` | Optional fallback only | none | Used only when bulk endpoint rejects filter. |
| 30 | GET   | /stock/productType/{id}                        | path:`id` | `StockEntry[]` | Optional | none | — |
| 31 | POST  | /designs/upload                                | — | — | — | — | **Out of scope MVP**. |

> Endpoint count breakdown: 27 in scope (per Discovery) + 4 reserved/out-of-scope wrappers = 31 total wrapper methods. Discovery's "27 endpoints" refers to the wired-to-callers set.

### Inbound: WP REST Routes under `spreadconnect/v1`

| Method | Path | Request | Response | Auth | Business Logic |
|--------|------|---------|----------|------|----------------|
| POST | `/wp-json/spreadconnect/v1/webhook` | Raw body (SC payload `{eventType, data:{pointOfSaleId, entity{}, errorReason?}}`) | HTTP 202 + body `[accepted]` (literal) on success / 401 on HMAC mismatch / 200 + duplicate-noop body on duplicate event | Public route + `permission_callback` = HMAC-SHA256 (constant-time) | (1) verify HMAC; (2) compute deterministic `event_id = sha256(eventType+":"+entity.id+":"+sha256(raw_body))`; (3) `INSERT IGNORE INTO wp_spreadconnect_webhook_log` (UNIQUE on `event_id` → duplicates fall through); (4) `as_enqueue_async_action('spreadconnect/process_webhook_event', [log_id])`; (5) return 202. **No domain mutation in receiver path.** |
| GET | `/wp-json/spreadconnect/v1/sync-progress` | query:`run_id?` (default = active run) | `{run_id, state, started_at, processed:int, total:int, created, updated, skipped, errors, last_log_lines:string[]}` | `manage_woocommerce` capability check via `permission_callback` | Reads `wp_spreadconnect_sync_history` row + last 20 log lines from in-memory ring (transient `sc_sync_log_tail_{run_id}`). Used by AJAX poll every 3 s on Catalog page. |
| GET | `/wp-json/spreadconnect/v1/health` | — | `{ok:bool, connection:string, subscriptions:{active:int,total:int}, queue:{pending:int,failed:int}}` | `manage_woocommerce` capability check | Read-only diagnostics. Used by Hub-Header. |

### Internal Admin AJAX (under `admin-ajax.php`)

> Used for in-page interactions where the caller is already in an authenticated WP-Admin context. All actions verify capability + nonce.

| `action` | Caller (UI) | Behavior |
|----------|-------------|----------|
| `spreadconnect_test_connection` | Settings → Test Connection (in-form, doesn't save) | Calls `GET /authentication` with the API-key from POST body (not yet persisted), returns `{ok, message}`. |
| `spreadconnect_sync_now` | Hub-Dashboard / Catalog → "Sync Now" | `as_enqueue_async_action('spreadconnect/sync_catalog', ['trigger'=>'manual'])`, returns `{run_id}`. |
| `spreadconnect_cancel_sync` | Catalog → Cancel-Sync confirm modal | `as_unschedule_action('spreadconnect/sync_article', […])` for queued + remaining; updates `wp_spreadconnect_sync_history.state='canceled'`. In-flight worker finishes current article (cooperative cancel). |
| `spreadconnect_resend_failed_op` | Failed-Ops list / Order-Edit Resend / Order-List bulk | Re-enqueue corresponding action by `op_type` + `payload`; on success, delete `failed_ops` row. |
| `spreadconnect_dismiss_failed_op` | Failed-Ops `[Dismiss]` (eligible op-types only) | Mark row `dismissed`. **Refused server-side for `op_type='create_order'`** unless one of three resolution choices is provided in payload. |
| `spreadconnect_resolve_create_order` | `dismiss_resolution_modal` 3-choice flow | Branches: `resend` → enqueue `create_order`; `cancel_wc` → set WC-Order to `cancelled`; `submitted_externally` → write `_spreadconnect_order_id` from payload + state `NEW` + Order-Note. |
| `spreadconnect_repair_subscriptions` | Subscriptions → Repair-All | Diff expected-vs-actual, batch `POST/DELETE /subscriptions`, return `{added:int, removed:int}`. |
| `spreadconnect_regenerate_secret` | Settings → Regenerate Secret | `random_bytes(32)` + base64 → write option, batch DELETE+POST all 7 subscriptions with new secret, return one-time-reveal payload. |
| `spreadconnect_link_article` | Product-Edit → article-picker selection | Write `_spreadconnect_article_id`, enqueue `sync_article`. |
| `spreadconnect_unlink_article` | Product-Edit → Unlink | Delete `_spreadconnect_article_id` (and other `_spreadconnect_*` product meta). |
| `spreadconnect_refresh_stock` | Product-Edit → Refresh-Stock | Calls `GET /stock` filtered to variation SKUs of the product; updates per-variation cache; returns map `{sku:status}`. |
| `spreadconnect_save_shipping_type` | Order-Edit → shipping-type select | `POST /orders/{id}/shippingType`, write `_spreadconnect_shipping_type`. |
| `spreadconnect_confirm_order` | Order-Edit → Confirm | Pre-check shipping-type set; `POST /orders/{id}/confirm`; update `_spreadconnect_state='CONFIRMED'`. |
| `spreadconnect_cancel_order` | Order-Edit → Cancel | `POST /orders/{id}/cancel`; update state. |
| `spreadconnect_refresh_order_state` | Order-Edit → Refresh State | `GET /orders/{id}`; update meta. |
| `spreadconnect_cancel_auto_confirm` | Order-Edit → `cancel_auto_confirm_button` | `as_unschedule_action('spreadconnect/confirm_order', ['order_id'=>$id])`. |
| `spreadconnect_export_settings` / `spreadconnect_import_settings` | Settings footer | Serialize/deserialize `spreadconnect_*` options as JSON (excluding `spreadconnect_webhook_secret`). |

### Data Transfer Objects (DTOs)

> All DTOs are PHP `final readonly` value objects under `SpreadconnectPod\Api\Dto\`. Validation runs in DTO factory methods. Snake_case JSON ↔ camelCase property is handled by the DTO mapper.

| DTO | Fields | Validation | Notes |
|-----|--------|------------|-------|
| `OrderCreate` | `externalOrderReference:string`, `orderItems:OrderItem[]`, `billingAddress:Address`, `shippingAddress:Address`, `shippingType?:string`, `customerEmail?:string`, `phone?:string`, `taxType?:string` | `externalOrderReference` non-empty (= WC-Order-ID); `orderItems` ≥ 1; addresses non-empty country+city+postcode | `taxType` per Open Q3 resolution: omitted (SC picks based on shipping address); fallback wrap if SC enforces. |
| `OrderItem` | `sku:string`, `quantity:int≥1`, `customerPrice?:Money` | `sku` non-empty | — |
| `Address` | `firstName:string`, `lastName:string`, `street:string`, `streetAnnex?:string`, `zipCode:string`, `city:string`, `country:string` (ISO 3166-1 alpha-2), `state?:string` | All non-empty except annexes | — |
| `Money` | `amount:string` (decimal as string), `currency:string` (ISO-4217), `taxRate?:string`, `taxAmount?:string` | `amount` matches `^\d+\.\d{2}$`; `currency` 3 chars | Decimal-as-string avoids float-precision loss. |
| `ArticleSummary` / `ArticleDetail` | `id:string`, `title:string`, `description?:string`, `productTypeId:string`, `designId?:string`, `variants:Variant[]`, `state?:string` | `id` non-empty | `id` as `TEXT` — see Realistic-Data table. |
| `Variant` | `sku:string`, `sizeId:string`, `colorId:string`, `priceCalculation?:Money` | — | — |
| `ShippingType` | `id:string`, `company:string`, `name:string`, `description?:string`, `price:Money` | — | UI: shows all five label fields per Discovery (no lead-time). |
| `Subscription` | `id:string`, `eventType:string`, `callbackUrl:string`, `state?:string` | — | `eventType` matches one of the 7 known events. |
| `StockEntry` | `sku:string`, `quantity:int≥0`, `state?:string` | — | — |
| `Preview` | `viewId:string`, `imageUrl:string`, `expiresAt?:string` | `imageUrl` is HTTPS | URL is presigned, transient → never persisted. |
| `WebhookEvent` | `eventType:string`, `data:{pointOfSaleId:string, entity:array, errorReason?:string}` | `eventType` ∈ {`Article.added/updated/removed`, `Order.cancelled/processed/needs-action`, `Shipment.sent`} | Raw `entity` kept untyped; per-handler structural validation. |

---

## Database Schema

### Entities (Custom Tables)

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `wp_spreadconnect_failed_ops` | Dead-letter queue for permanently-failed Action Scheduler ops. | `id`, `op_type`, `related_entity_type`+`related_entity_id`, `state`, `created_at` |
| `wp_spreadconnect_webhook_log` | Append-only event log for inbound webhooks (idempotency + audit). | `id`, `event_type`, `event_id` UNIQUE, `related_entity_type`+`related_entity_id`, `received_at`, `processing_status` |
| `wp_spreadconnect_sync_history` | One row per catalog-sync run with progress/result counts. | `id`, `started_at`, `state`, `details` (JSON) |

### Schema Details

**`wp_spreadconnect_failed_ops`**

| Column | Type | Constraints | Index |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | `PRIMARY KEY AUTO_INCREMENT` | implicit PK |
| `op_type` | `VARCHAR(64)` | `NOT NULL`, app-enforced enum | (composite) |
| `related_entity_type` | `VARCHAR(32)` | `NOT NULL` | (composite) |
| `related_entity_id` | `VARCHAR(64)` | `NOT NULL` | (composite) |
| `payload` | `LONGTEXT` | `NOT NULL` (JSON) | — |
| `error_message` | `TEXT` | NULL | — |
| `error_code` | `VARCHAR(64)` | NULL | — |
| `retries_used` | `TINYINT UNSIGNED` | `NOT NULL DEFAULT 0` | — |
| `created_at` | `DATETIME` | `NOT NULL` | `idx_created_at` |
| `last_attempt_at` | `DATETIME` | NULL | — |
| `state` | `VARCHAR(16)` | `NOT NULL DEFAULT 'unresolved'`, enum {`unresolved`,`resolved`,`dismissed`} | (composite) |

Indexes:
- `idx_state_op_type (state, op_type)` — Hub-Dashboard counts + Failed-Ops filter.
- `idx_related_entity (related_entity_type, related_entity_id)` — per-order/per-article lookup.
- `idx_created_at (created_at)` — retention purge.

**`wp_spreadconnect_webhook_log`**

| Column | Type | Constraints | Index |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | `PRIMARY KEY AUTO_INCREMENT` | PK |
| `event_type` | `VARCHAR(64)` | `NOT NULL` | — |
| `event_id` | `CHAR(64)` | `NOT NULL` | `uniq_event_id` UNIQUE — sha256 hex always 64 chars |
| `related_entity_type` | `VARCHAR(32)` | `NOT NULL` | (composite) |
| `related_entity_id` | `VARCHAR(64)` | `NOT NULL` | (composite) |
| `payload` | `LONGTEXT` | `NOT NULL` (JSON; raw body re-encoded for storage) | — |
| `hmac_status` | `VARCHAR(16)` | `NOT NULL`, enum {`valid`,`invalid`} | — |
| `processing_status` | `VARCHAR(16)` | `NOT NULL DEFAULT 'pending'`, enum {`success`,`error`,`pending`,`duplicate`} | `idx_processing_status` |
| `processing_error` | `TEXT` | NULL | — |
| `received_at` | `DATETIME` | `NOT NULL` | (composite) |

Indexes:
- `uniq_event_id (event_id) UNIQUE` — idempotency enforcement: duplicate insert fails deterministically.
- `idx_received_at (received_at)` — list view + retention purge.
- `idx_related_entity (related_entity_type, related_entity_id, received_at)` — composite for "last 5 events for order" (Screen 11 ⑩).
- `idx_processing_status (processing_status)` — "errors only" filter.

**`wp_spreadconnect_sync_history`**

| Column | Type | Constraints | Index |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | `PRIMARY KEY AUTO_INCREMENT` | PK |
| `started_at` | `DATETIME` | `NOT NULL` | (composite) + `idx_started_at` |
| `finished_at` | `DATETIME` | NULL | — |
| `trigger` | `VARCHAR(32)` | `NOT NULL`, enum {`manual`,`webhook`,`scheduled`,`initial`} | — |
| `created_count` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | — |
| `updated_count` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | — |
| `skipped_count` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | — |
| `error_count` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | — |
| `state` | `VARCHAR(16)` | `NOT NULL DEFAULT 'pending'`, enum {`pending`,`in_progress`,`complete`,`failed`,`canceled`} | (composite) |
| `details` | `LONGTEXT` | `NOT NULL` (JSON, schema below) | — |

Indexes:
- `idx_state_started_at (state, started_at)` — list view + in-progress lookup.
- `idx_started_at (started_at)` — pagination + retention.

`details` JSON shape:

```json
[
  { "article_id": "string", "title": "string", "status": "created|updated|skipped|error|partial", "notes": "string|null" }
]
```

### Realistic Data Type Audit (URL/Token columns)

| Field | Decision | Rationale (Realistic Data Check input) |
|---|---|---|
| `wp_spreadconnect_webhook_log.event_id` | `CHAR(64)` | sha256 hex digest is exactly 64 chars; fixed length. |
| `wp_spreadconnect_webhook_log.payload` | `LONGTEXT` | SC payloads can include arrays of variants → unknown ceiling. `LONGTEXT` (4 GB) costs nothing extra in MySQL InnoDB row-format dynamic. |
| `wp_spreadconnect_failed_ops.payload` | `LONGTEXT` | Catalog-sync payloads contain article-arrays → unbounded. |
| `_spreadconnect_article_id` (postmeta) | (default `LONGTEXT` — WP postmeta) | SC `id` field type: not specified as fixed-length in OpenAPI. Stored as TEXT in postmeta — ample. |
| `_spreadconnect_order_id` (ordermeta) | (default `LONGTEXT` — HPOS meta-table column type is `longtext`) | Same — SC ID format is not contract-fixed. Last seen sample is digit-string ≤ 12 chars but Architecture refuses to assume. |
| `_spreadconnect_tracking_url` (ordermeta) | (default `LONGTEXT`) | Carrier tracking URLs can be arbitrarily long (DHL/UPS occasionally embed multi-segment slugs). `LONGTEXT` is safe. |
| `Preview.imageUrl` (DTO, transient) | not persisted | Presigned URL — used immediately by `media_sideload_image()`, never stored. |
| `option spreadconnect_api_key` | WP option (LONGTEXT) | Bearer token length not contract-fixed; WP options table is LONGTEXT — safe. |
| `option spreadconnect_webhook_secret` | WP option (LONGTEXT) | base64 of 32 random bytes ≈ 44 chars; LONGTEXT trivially holds it. |

### Relationships (logical, no FKs — per WP convention)

| From | To | Relationship | Cascade |
|------|-----|--------------|---------|
| `wp_spreadconnect_webhook_log.related_entity_id` (when type=`order`) | WC-Order via `_spreadconnect_order_id` reverse-lookup | N:1 (multiple events per order) | none — events stay even if order is deleted (audit). |
| `wp_spreadconnect_failed_ops.related_entity_id` (when type=`order`) | WC-Order ID (= `wc_get_order` PK) | N:1 | none — entries stay until purged or resolved. |
| `wp_spreadconnect_sync_history.id` | (no FK) | — | cascade-delete via retention job only. |
| WC-Product post-meta `_spreadconnect_article_id` | SC Article (external) | 1:1 logical | unlink via `Article.removed` webhook → product set to `draft`, meta retained for audit. |

### WC-Product Meta (`wp_postmeta`)

| Meta Key | Required | Validation | Notes |
|----------|----------|------------|-------|
| `_spreadconnect_article_id` | Conditional | non-empty string | Pflicht for linked products. Reverse-lookup via `meta_query` allowed. |
| `_spreadconnect_product_type_id` | No | non-empty string | Pulled from Article. |
| `_spreadconnect_cost` | No | numeric ≥ 0 (decimal) | EUR per Discovery. |
| `_spreadconnect_cost_currency` | No | ISO-4217 | Default `EUR`. |
| `_spreadconnect_last_sync` | No | unix-timestamp | — |
| `_spreadconnect_sync_state` | No | enum `synced`/`partial`/`error`/`removed_in_sc` | — |
| `_spreadconnect_sync_error` | No | string | — |

### WC-Variation Meta

| Meta Key | Required | Validation | Notes |
|----------|----------|------------|-------|
| `_spreadconnect_sku` | Yes | non-empty | = WC-Variation `_sku` (Konsistenz-Doppelung). |
| `_spreadconnect_size_id` | No | string | from ProductType-Spec. |
| `_spreadconnect_color_id` | No | string | from ProductType-Spec. |
| `_spreadconnect_stock_cached` | No | int ≥ 0 | — |
| `_spreadconnect_stock_cached_at` | No | unix-timestamp | — |

### WC-Order Meta (HPOS — via `$order->update_meta_data()` ONLY)

| Meta Key | Required | Validation | Notes |
|----------|----------|------------|-------|
| `_spreadconnect_order_id` | Conditional | non-empty | Set after `POST /orders` success. Indexed via `wc_get_orders(['meta_key'=>...])`. |
| `_spreadconnect_state` | Conditional | enum `submitting`/`NEW`/`CONFIRMED`/`PROCESSED`/`CANCELLED`/`failed_to_submit` | Six persistent states. `pending` = pre-submit (no meta). `needs_action` = orthogonal flag. |
| `_spreadconnect_needs_action` | No | bool | Set by webhook. |
| `_spreadconnect_shipping_type` | No | string (SC `shippingType.id`) | Validated against last-fetched list. |
| `_spreadconnect_tracking_number` | No | string | — |
| `_spreadconnect_tracking_url` | No | URL | — |
| `_spreadconnect_last_event` | No | string `<unix>:<event-type>` | Latest webhook event. |

### WP Options (`wp_options`)

(See Discovery for the full 18-key table — replicated here without change.)

| Option Key | Type | Default | Validation | Notes |
|------------|------|---------|------------|-------|
| `spreadconnect_api_key` | string | `""` | non-empty when set | Bearer token, masked in UI. |
| `spreadconnect_use_staging` | bool | `false` | — | Default off in production (per AVOID #9). |
| `spreadconnect_webhook_secret` | string | auto-generated | base64, ≥32 bytes | Generated via `random_bytes(32)`. |
| `spreadconnect_auto_confirm` | string | `off` | enum `off`/`immediate`/`after_minutes` | Forced `off` when `spreadconnect_default_shipping_type` empty. |
| `spreadconnect_auto_confirm_minutes` | int | `0` | ≥ 0 | only when `after_minutes`. |
| `spreadconnect_default_shipping_type` | string | `""` | non-empty SC `shippingType.id` | Required for Auto-Confirm. |
| `spreadconnect_auto_cancel_mirror` | bool | `true` | — | — |
| `spreadconnect_pull_images` | bool | `true` | — | Catalog sync. |
| `spreadconnect_force_repull_images` | bool | `false` | — | Per-run override toggle. |
| `spreadconnect_stock_sync_interval` | string | `6h` | enum `1h`/`4h`/`6h`/`12h`/`24h` | — |
| `spreadconnect_low_stock_threshold` | int | `10` | ≥ 0 | — |
| `spreadconnect_live_cache_ttl_seconds` | int | `300` | 60..900 | — |
| `spreadconnect_notify_emails` | string | `""` | comma-sep emails | — |
| `spreadconnect_notify_on_order_failure` | bool | `true` | — | — |
| `spreadconnect_notify_on_sync_failure` | bool | `true` | — | — |
| `spreadconnect_notify_on_webhook_failure` | bool | `false` | — | — |
| `spreadconnect_failed_ops_retention_days` | int | `90` | 7..365 | — |
| `spreadconnect_webhook_log_retention_days` | int | `90` | 7..365 | — |
| `spreadconnect_pt_index_etag` | string | `""` | — | Long-cache ETag for `GET /productTypes`. |

### Transient Keys (cache layer)

| Key Pattern | TTL | Purpose |
|---|---|---|
| `sc_pt_{productTypeId}` | 24 h | ProductType detail cache. |
| `sc_pt_views_{productTypeId}` | 24 h | Views cache. |
| `sc_pt_size_chart_{productTypeId}` | 24 h | Optional size-chart. |
| `sc_stock_{sku}` | configurable 60–900 s (default 300) | Live-stock cache (per-variation). |
| `sc_shipping_types_{order_id}` | 5 min | Per-order shipping-type list. |
| `sc_sync_log_tail_{run_id}` | until run-end + 1 h | Last 20 log lines for AJAX-poll. |

---

## Server Logic

### Service Map (DDD-light layering)

| Service | Layer | Responsibility | Inputs | Outputs | Side Effects |
|---|---|---|---|---|---|
| `Bootstrap\Plugin` | Bootstrap | Wire hooks + service container; HPOS declare; activation/deactivation. | `__FILE__` | — | Hook registration. |
| `Bootstrap\Container` | Bootstrap | DI for services (closure-based factory map). | service id | service instance | — |
| `Api\SpreadconnectClient` | Infrastructure | Outbound HTTP wrapper. Bearer auth, base-URL toggle, single fail-fast retry on 429, `X-RateLimit-*` honor. | DTO request, endpoint id | DTO response \| `WP_Error` (`spreadconnect_*` codes) | none (read-only HTTP). |
| `Api\Dto\*` | Domain | Typed DTOs + factory validators (snake_case ↔ camelCase mapping). | array | DTO | — |
| `Order\OrderSubmitJob` | Application (Job-Handler) | Hook handler for `spreadconnect/create_order`. Idempotency-skip when `_spreadconnect_order_id` exists; calls `POST /orders`; persists state. | `['order_id'=>int]` | — | WC-Order meta writes; failure → `FailedOpsRepo::record()`. |
| `Order\OrderConfirmJob` | Application | Handler for `spreadconnect/confirm_order` (auto-confirm timer or manual). | `['order_id'=>int]` | — | `POST /orders/{id}/confirm`; meta update. |
| `Order\OrderCancelMirrorJob` | Application | Handler for `spreadconnect/cancel_order_mirror`. | `['order_id'=>int]` | — | `POST /orders/{id}/cancel`; meta + Order-Note. |
| `Order\OrderHandler` | Application (Hook-Handler) | WC-status hook listener; schedules jobs. | WC hook payload | — | `as_unschedule_action(...)` for race protection; `as_enqueue_async_action(...)`. |
| `Order\OrderStateMachine` | Domain | Compare-and-set wrapper around `_spreadconnect_state` (per Race Protection). | order, expected, target | bool (cas-success) | atomic-ish UPDATE on order meta. |
| `Catalog\SyncCatalogJob` | Application | Pages `GET /articles`, schedules per-article jobs; updates `sync_history`. | `['trigger'=>string,'run_id'=>int]` | — | Inserts/updates `wp_spreadconnect_sync_history` row. |
| `Catalog\SyncArticleJob` | Application | One article: `GET /articles/{id}` + `GET /productTypes/{id}` + previews + media-sideload + WC-product upsert. | `['article_id'=>string,'run_id'=>int?]` | — | WC product CRUD; postmeta writes; cron-context media includes (`require_once`). |
| `Catalog\ArticleRemovedJob` | Application | Sets WC-product `draft`, leaves order history intact. | `['article_id'=>string]` | — | post-status update. |
| `Catalog\ProductMapper` | Domain | Article+ProductType+Previews → WC_Product_Variable / WC_Product_Variation upsert. | DTOs | WC_Product | — |
| `Catalog\ImageSideloader` | Infrastructure | Wraps `media_sideload_image()` with admin-includes loaded. | URL, post_id | attachment_id \| WP_Error | Media library inserts. |
| `Catalog\AttributeProvisioner` | Infrastructure | Idempotent create of `pa_groesse`/`pa_farbe` taxonomies + terms. | size/color labels | term_ids | term inserts. |
| `Webhook\WebhookController` | Adapter (REST) | `permission_callback` runs HMAC verify; handler inserts log row + schedules processing; returns 202. | `WP_REST_Request` | `WP_REST_Response` | DB insert; AS schedule. |
| `Webhook\WebhookSignatureVerifier` | Domain | HMAC-SHA256 over raw body, header `X-SPRD-SIGNATURE`, base64, `hash_equals()`. | raw body, header, secret | bool | — |
| `Webhook\EventIdHasher` | Domain | Computes deterministic `event_id`. | eventType, entity-id, raw body | string (sha256-hex) | — |
| `Webhook\ProcessWebhookEventJob` | Application | Async dispatcher per event-type → invokes domain handlers. | `['log_id'=>int]` | — | Order-meta updates / re-sync triggers / Admin-Notice. |
| `Webhook\OrderEventHandler` | Domain | `Order.processed/cancelled/needs-action/Shipment.sent` → state mutations + WC-status updates. | event payload | — | meta + Order-Note + WC status change. |
| `Webhook\ArticleEventHandler` | Domain | `Article.added/updated/removed` → schedule sync_article / handle_article_removed. | event payload | — | AS schedule. |
| `Subscription\SubscriptionManager` | Application | Diff registered-vs-expected; bulk register/delete; auto-on-Settings-Save. | (state) | summary | API calls + transient log. |
| `Subscription\WebhookSecretManager` | Application | Generate / rotate secret; orchestrate re-subscribe on rotate. | — | one-time-reveal payload | option write + bulk re-subscribe. |
| `Stock\StockCache` | Domain | Transient-backed per-SKU cache. | sku | int? | — |
| `Stock\StockSyncJob` | Application | Periodic threshold-based update via `GET /stock` bulk; per-variation WC-stock writes. | (none) | — | post-meta updates; `outofstock` flag toggling. |
| `Stock\LiveStockRefresher` | Application | On-demand bulk refresh for one product's variation set. | product_id | map<sku,status> | transient writes. |
| `Failure\FailedOpsRepo` | Infrastructure | CRUD for `wp_spreadconnect_failed_ops`. | row params | id \| void | DB writes. |
| `Failure\FailureNotifier` | Application | Emails + admin-notices on permanent failure. | failed-op | — | `wp_mail`; `wc_get_logger()`; admin-notice option. |
| `Failure\AdminNoticeStore` | Infrastructure | Persistent-notice option list with per-op-type dismiss policy. | op-type | array | option writes. |
| `Failure\BulkResendCoordinator` | Application | Order-List bulk-action: pre-flight count + per-row outcome panel. | order_ids[] | summary | per-row AS schedules. |
| `Hub\Controller` | Adapter (Admin-Page) | Routes `?section=...` → renders sub-page view. | request | HTML | — |
| `Hub\View\*` | Adapter (Admin-Page) | One class per section (Dashboard, Catalog, Orders, Webhooks, Failed, Logs, Settings, Subscriptions). | data | HTML | — |
| `Inline\ProductMetaBox` | Adapter (Admin-Page) | Product-Edit Spreadconnect box: cost+margin+stock+sync. | post_id | HTML/JS | — |
| `Inline\OrderMetaBox` | Adapter (Admin-Page) | Order-Edit Spreadconnect box: state, shipping-type, actions, shipments, webhook activity. | order | HTML/JS | — |
| `Inline\ProductListColumns` | Adapter (Admin-List) | `manage_edit-product_columns` + render + filter dropdown. | columns | columns | — |
| `Inline\OrderListColumns` | Adapter (Admin-List) | Dual hooks (Legacy + HPOS) for SC-State + SC-OrderID columns + filter + bulk-action. | columns | columns | — |
| `Logging\WcLoggerAdapter` | Infrastructure | Wraps `wc_get_logger()` with sources `spreadconnect-api-client`, `spreadconnect-order-service`, `spreadconnect-webhook-receiver`, `spreadconnect-sync-job`, `spreadconnect-failure`. | level, msg, context | — | log-file write. |
| `Cli\Commands` | Adapter (CLI) | Optional WP-CLI: `wp spreadconnect sync`, `wp spreadconnect repair-subs`. | argv | exit | — |

### Business Logic Flow — Outbound Order Submit (Flow C)

```
WC Order processing-hook
        │
        ▼
OrderHandler::on_processing
   ├─ idempotency-check on _spreadconnect_order_id (skip if present)
   └─ as_enqueue_async_action('spreadconnect/create_order', [order_id])
        │
        ▼ (Action Scheduler worker, separate request)
OrderSubmitJob::handle
   ├─ idempotency-check (state machine: submit only if state ∈ {null, submitting})
   ├─ build OrderCreate DTO from WC-Order
   ├─ SpreadconnectClient->createOrder(dto)
   │       │
   │       ├─ 2xx → DTO returned
   │       ├─ 429 → wait Retry-After + ONE retry
   │       ├─ 4xx → throw SpreadconnectClientError (permanent)
   │       └─ 5xx/network → throw SpreadconnectTransientError
   ├─ on success: order->update_meta_data(_spreadconnect_order_id, response.id)
   │              order->update_meta_data(_spreadconnect_state, 'NEW')
   │              order->save()
   │              Order-Note "Submitted to Spreadconnect (#SC-N)"
   ├─ on permanent (4xx): FailedOpsRepo::record(); FailureNotifier::dispatch();
   │                       order meta state 'failed_to_submit'; Admin-Notice;
   │                       (Action Scheduler does NOT retry on thrown SpreadconnectClientError)
   └─ on transient (5xx/network): re-throw → Action Scheduler retries 1m/5m/15m
                                  after 3rd failure → FailedOpsRepo::record(); same notice path
```

### Business Logic Flow — Webhook Receive (Flow E)

```
SC POSTs https://shop/wp-json/spreadconnect/v1/webhook
        │
        ▼
permission_callback = WebhookSignatureVerifier::verify(raw_body, header, secret)
   ├─ false → return WP_Error 401  (Controller logs entry with hmac_status='invalid', no mutation)
   └─ true  → controller body
        │
        ▼
WebhookController::handle
   ├─ event_id = EventIdHasher::compute(eventType, entity.id, raw_body)
   ├─ INSERT INTO wp_spreadconnect_webhook_log (… processing_status='pending')
   │     ON DUPLICATE-KEY (event_id UNIQUE) → row already exists
   │           UPDATE processing_status='duplicate'
   │           return 200 + body 'duplicate'   (NOT 202 - SC won't retry)
   ├─ as_enqueue_async_action('spreadconnect/process_webhook_event', [log_id])
   └─ return HTTP 202 + body '[accepted]'   (within 8 s)
        │
        ▼ (separate request)
ProcessWebhookEventJob::handle
   ├─ load row, parse payload
   ├─ dispatch to OrderEventHandler / ArticleEventHandler by eventType prefix
   ├─ on success → UPDATE processing_status='success'
   └─ on error   → UPDATE processing_status='error', FailedOpsRepo::record(op_type='handle_webhook')
```

### Validation Rules (cross-cutting)

| Field | Rule | Error |
|-------|------|-------|
| `spreadconnect_api_key` (Settings save) | non-empty + post-save `GET /authentication` 200 | inline form error "Invalid API Key" |
| `spreadconnect_default_shipping_type` | non-empty when `spreadconnect_auto_confirm` ≠ `off` | server forces auto-confirm to `off` + inline notice |
| Webhook header `X-SPRD-SIGNATURE` | exists + base64-decodable + `hash_equals` matches | 401, log entry hmac_status=`invalid` |
| Webhook payload `eventType` | one of 7 known strings | row inserted with `processing_status='error'`, error=`unknown_event_type` |
| Order item SKU mapping | maps to a known WC-variation `_sku` | submit fails 4xx → `failed_to_submit`; UI route to manual resolution |
| Article-link form input | `^[A-Za-z0-9_-]{1,64}$` (defensive — Architecture won't assume tighter) | inline error "Invalid Article ID" |
| Auto-confirm minutes | `≥ 0` int | discard, default 0 |
| Stock TTL | `60 ≤ x ≤ 900` | clamp |
| Retention days | `7 ≤ x ≤ 365` | clamp |

---

## Security

### Authentication & Authorization

| Area | Mechanism | Notes |
|------|-----------|-------|
| Outbound API | Bearer token (`Authorization: Bearer <api_key>`) per request | Read from `spreadconnect_api_key` option each call (no in-memory caching beyond request scope). |
| Inbound webhook | HMAC-SHA256 over raw body, header `X-SPRD-SIGNATURE`, base64-encoded, `hash_equals()` constant-time compare against `spreadconnect_webhook_secret`. **No `WP_DEBUG` bypass.** | Failed verification ⇒ 401 + log entry (IP + headers, no payload). |
| Admin pages + AJAX | WP `current_user_can('manage_woocommerce')` + `wp_verify_nonce()` for state-changing actions. | Read-only AJAX (`sync-progress`, `health`) requires capability only (no nonce). |
| WP-CLI commands | runs as system user, defaults to capability check via `wp user-list-capabilities` if `--user=` given. | Optional. |

### Data Protection

| Data Type | Protection | Notes |
|---|---|---|
| `spreadconnect_api_key` | masked in UI ("••••" with show-toggle); stored plaintext in `wp_options` (per Open Q7 resolution = MVP plain). | Encryption (Sodium + `SECURE_AUTH_KEY`) is post-MVP slice. |
| `spreadconnect_webhook_secret` | one-time reveal panel on initial save; stored plaintext in `wp_options` (must be readable for HMAC verify); `[Regenerate]` invalidates + auto-resubscribes. | Same constraint as any HMAC secret manager. |
| Webhook payload (raw body) | logged in `wp_spreadconnect_webhook_log.payload` only on `hmac_status='valid'`. | Invalid payloads: header-snippet only (IP + first headers). |
| Bearer token in logs | redacted (`Authorization: Bearer ***`) by `WcLoggerAdapter` before write. | Mandatory for compliance. |
| Email recipients (Notify list) | stored plaintext (`wp_options`) — same trust level as WP user emails. | — |
| Settings-Export JSON | excludes `spreadconnect_api_key` and `spreadconnect_webhook_secret`. | Prevents secret exfil via export. |

### Input Validation & Sanitization

| Input | Validation | Sanitization |
|---|---|---|
| Settings form (POST) | per-field type + range + enum check via WP Settings API `sanitize_callback`. | `sanitize_text_field`, `intval`, `(bool)`, `sanitize_email` per field. |
| Webhook raw body | size limit 1 MB (reject 413 if larger); JSON-decodable; required keys (`eventType`, `data.entity`); enum check on `eventType`. | Raw bytes preserved for HMAC; only sanitized into structured DTO inside async job. |
| Admin AJAX inputs | typed POST keys validated via dedicated request-classes. | `wp_unslash` + per-field WP sanitizers. |
| WC-Order data → SC `OrderCreate` | Address fields trimmed; country uppercased + ISO-3166-1-alpha-2 check. | — |
| Article-Picker search query | ≤ 100 chars, server-side debounced via `?search=`. | URL-encoded by HTTP client. |

### Rate Limiting & Abuse Prevention

| Resource | Limit | Window | Penalty |
|---|---|---|---|
| Outbound `GET /articles` page calls | per-batch 25 articles (Action Scheduler default `batch_size`) | per AS batch (default 30 s) | Honor SC's own 429 Retry-After. |
| Outbound bulk operations | proactive 1 s sleep when `X-RateLimit-Remaining ≤ 5` | per request | — |
| Inbound webhook endpoint | none (SC is the only legitimate caller; HMAC + UNIQUE event_id are the abuse barriers) | — | 401 on HMAC mismatch; 200 on duplicate. |
| Settings → Test Connection | 1 call per click (frontend disables button while `loading`); no server-side rate-limit. | — | — |
| Admin AJAX endpoints | implicit via WP `wp_verify_nonce` (single-use within 12 h) | — | nonce-fail = 403. |
| `sync-progress` poll | 3 s client-poll; transient cache front-end value to bound DB hits | 3 s | — |

---

## Architecture Layers

### Layer Responsibilities

| Layer | Responsibility | Pattern |
|---|---|---|
| Bootstrap | Plugin activation/deactivation/uninstall, HPOS declare, hook registration, DI container. | Plugin Bootstrap |
| Adapter — REST | Webhook receiver, sync-progress/health endpoints. | REST controller pattern (`register_rest_route`). |
| Adapter — Admin Page | Hub controller + per-section views; meta-boxes; list-column hooks. | MVC-light (Controller + View classes). |
| Adapter — Admin AJAX | Capability + nonce-protected action handlers. | Front-controller pattern (one `admin-ajax.php` action per command). |
| Adapter — CLI | Optional `wp spreadconnect …`. | WP-CLI command pattern. |
| Application — Job Handlers | Action-Scheduler hook callbacks (`spreadconnect/create_order`, `/sync_article`, `/process_webhook_event`, …). Idempotent, retryable. | Job pattern (single-method handler, throws on transient error to enable AS retry). |
| Application — Coordinators | Multi-step orchestration (BulkResendCoordinator, SubscriptionManager). | Use-case service. |
| Domain | OrderStateMachine, ProductMapper, EventIdHasher, WebhookSignatureVerifier, StockCache. | Pure functions / value objects. |
| Domain — DTO | `OrderCreate`, `Address`, `OrderItem`, `ShippingType`, `Subscription`, `StockEntry`, … | Value objects with factory validators. |
| Infrastructure — HTTP | `SpreadconnectClient` (single retry on 429 fail-fast otherwise). | Repository / API Client pattern. |
| Infrastructure — Persistence | `FailedOpsRepo`, `WebhookLogRepo`, `SyncHistoryRepo` (raw `$wpdb` for custom tables); `wc_get_orders`/`get_post_meta` for WC-meta. | Repository pattern. |
| Infrastructure — Logging | `WcLoggerAdapter` over `wc_get_logger()`. | Adapter pattern. |
| Infrastructure — Cache | `StockCache` over WP transient API. | Cache-aside pattern. |
| Infrastructure — i18n | `load_plugin_textdomain('spreadconnect-pod')`; `__()` wrappers; `de_DE.po`. | WP i18n. |

### Action Scheduler — Hook Inventory

| Hook | Trigger | Recurring? | Concurrency | Retry Policy (AS) |
|---|---|---|---|---|
| `spreadconnect/sync_catalog` | Manual ("Sync Now"), webhook trigger, scheduled (initial install) | one-shot | 1 (single-claim) | Fail-fast on 4xx; 1m/5m/15m on 5xx/network. |
| `spreadconnect/sync_article` | Per-article child of `sync_catalog`; also on `Article.added/updated`-webhook | one-shot | up to AS-default concurrency (5) | Same. |
| `spreadconnect/handle_article_removed` | `Article.removed`-webhook | one-shot | 1 per article-id | 1m/5m/15m. |
| `spreadconnect/create_order` | `woocommerce_order_status_processing`-hook + Resend | one-shot | 1 per WC-order | 1m/5m/15m on 5xx; permanent on 4xx. |
| `spreadconnect/confirm_order` | Order-Edit "Confirm" + Auto-Confirm timer | one-shot | 1 per WC-order | 1m/5m/15m. |
| `spreadconnect/cancel_order_mirror` | `woocommerce_order_status_cancelled` (only if `_spreadconnect_state='NEW'`) | one-shot | 1 per WC-order | 1m/5m/15m. |
| `spreadconnect/fetch_tracking` | `Shipment.sent`-webhook | one-shot | 1 per SC-order | 1m/5m/15m. |
| `spreadconnect/process_webhook_event` | Webhook-receiver | one-shot | 1 per `event_id` | 1m/5m/15m. |
| `spreadconnect/scheduled_stock_sync` | Recurring (interval per setting) | recurring | 1 (single-claim) | 1m/5m/15m. |
| `spreadconnect/auto_subscription_check` | Recurring (weekly drift detection) | recurring | 1 | 1m/5m/15m. |
| `spreadconnect/purge_old_logs` | Recurring (daily) | recurring | 1 | no retry (idempotent). |

> All hooks register a default Action-Scheduler group `spreadconnect` for filterable visibility under `Tools → Scheduled Actions`.

### Data Flow

```
                    ┌────────────────────────────────────────────────┐
                    │            WP-ADMIN UI (manage_woocommerce)      │
                    │  Hub-Page · Meta-Boxes · List-Columns · Bulk     │
                    └──────────────┬───────────────────────┬──────────┘
                                   │ AJAX (wp_ajax_*)      │
                                   ▼                       │
                    ┌──────────────────────────┐           │
                    │  Adapter — Admin AJAX    │           │
                    │  (cap-check + nonce)     │           │
                    └──────────────┬───────────┘           │
                                   │                       │
       ┌───────────────────────────┴────────────────┐      │
       ▼                                            ▼      ▼
┌────────────────────┐            ┌─────────────────────────────┐
│ Application Layer  │            │  Adapter — REST Webhook      │
│ Coordinators / Jobs│◄───enqueue─│  /spreadconnect/v1/webhook   │
│ (Action Scheduler) │            │  HMAC + log + 202            │
└────────┬───────────┘            └─────────────────────────────┘
         │ uses
         ▼
┌─────────────────────────────────────────────────────────────────┐
│                 Domain (DTO, StateMachine, Mappers)              │
└────────────┬────────────────┬───────────────────────────────────┘
             │                │
             ▼                ▼
   ┌────────────────┐   ┌────────────────────┐   ┌────────────────────┐
   │ Infrastructure │   │ Infrastructure     │   │ Infrastructure     │
   │ HTTP-Client    │   │ Persistence        │   │ Logging            │
   │ SC REST API    │   │ wpdb · WC Order/   │   │ WC_Logger          │
   │ (Bearer)       │   │ Product CRUD ·      │   │ (sources)          │
   └────────────────┘   │ wp_options/transient│   └────────────────────┘
                        └────────────────────┘

External:
  Outbound  → https://(staging.|rest.)spreadconnect.com  (REST, JSON)
  Inbound   ← Spreadconnect webhook → /wp-json/spreadconnect/v1/webhook
```

### Error Handling Strategy

| Error Type | Handling | User Response | Logging |
|---|---|---|---|
| HTTP 2xx outbound | success | (success state in UI) | INFO `spreadconnect-api-client` |
| HTTP 429 outbound | sleep `X-RateLimit-Retry-After-Seconds`, ONE retry within job | (transparent) | WARN `spreadconnect-api-client` |
| HTTP 4xx outbound | `WP_Error` (`spreadconnect_http_error`, code 4xx) → caller maps to permanent failure | inline form/Order-Note + Admin-Notice | ERROR `spreadconnect-api-client` |
| HTTP 5xx / network / timeout | `WP_Error` thrown → Action Scheduler retries 1m/5m/15m | "Retrying…" placeholder; after 3rd attempt → Failed-Ops + email + Admin-Notice | WARN/ERROR `spreadconnect-*-job` |
| HMAC mismatch on webhook | 401 + log entry hmac_status=`invalid` (no payload) | (none — SC issue) | WARN `spreadconnect-webhook-receiver` (IP, header sample) |
| Duplicate event (UNIQUE conflict) | 200 + body `duplicate`, no schedule | (none) | DEBUG `spreadconnect-webhook-receiver` |
| Missing WC-product for SKU on submit | 4xx returned by SC → caller path | Order-Note + Failed-Op (`create_order`) | ERROR `spreadconnect-order-service` |
| Image-sideload failure | sync-state `partial`; sync continues for other articles; row inserted in `sync_history.details` | Catalog-Sync history "partial"; Re-Sync button | WARN `spreadconnect-sync-job` |
| Action-Handler thrown PHP-Fatal | AS marks job `failed`; transcript via `wc-logs/`; counts as one retry | (transparent) | ERROR (PHP-error log + AS log) |
| Webhook payload schema-violation | log row `processing_status='error'` `processing_error='schema'` | (no UI surface beyond log) | ERROR `spreadconnect-webhook-receiver` |

---

## Migration Map

> **N/A — Greenfield.** v1 plugin is deleted before v2 implementation begins (per Discovery Current State Reference: "Greenfield im Plugin-Scope: `spreadconnect-pod` v1 wird vor v2-Implementierung gelöscht").

| Existing File | Current Pattern | Target Pattern | Specific Changes |
|---|---|---|---|
| `wordpress/plugins/spreadconnect-pod/` (entire directory) | v1 plugin (4 PSR-4 service classes, 2 endpoints used, settings page under `add_options_page`, stub HMAC) | DELETED before v2 starts. v2 reuses only the **directory path** for its new files. | Slice 1 — Foundation: deletes all of `includes/`, `vendor/`, settings, tracking-service, etc. Recreates empty skeleton with `composer.json` (PSR-4 root `SpreadconnectPod\\` → `includes/`), main file `spreadconnect-pod.php` v2.0.0, `uninstall.php`, `tests/bootstrap/` (REUSE existing stubs from scan), `tests/slices/pod-shop-mvp/` (REUSE layout). No data migration — v1 is on a dev installation only. |
| `tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php` (root) | Old v1 slice test (root-level twin) | Replaced by new slice tests `slice-01-foundation.php` … `slice-10-logs-polish.php` matching v2 slices. | Old file deleted; new ones reuse Brain\Monkey + WC-stub bootstrap pattern. |

> No production data is at risk — v1 lives on a dev-only Docker WP install. All v1-written `_spreadconnect_*` order-meta on existing dev orders is treated as throwaway. Naming is preserved for forward consistency only.

---

## Constraints & Integrations

### Constraints (technical implications from Discovery)

| Constraint | Technical Implication | Solution |
|---|---|---|
| HPOS Default since WC 8.2 | All order-meta access **must** go through `$order->update_meta_data()` + `$order->save()`; reverse-lookup via `wc_get_orders(['meta_key'=>...])`. No `update_post_meta()` for orders. | Centralized via `OrderStateMachine` + `OrderRepo`. Meta keys reused from v1 for naming consistency (REUSE #2). HPOS-declare on `before_woocommerce_init`. |
| Webhook ACK ≤ 8 s, body must be `[accepted]` | Receiver must not do domain mutation in the request thread. | Synchronous: HMAC verify + DB insert + `as_enqueue_async_action` + return 202. All mutation deferred to `process_webhook_event` job. |
| Action Scheduler bundled with WC since 3.0; default batch=25, claim-cleanup=5 min | Single retry layer lives at AS level. Inner HTTP retry kept only for 429 with `Retry-After` (one attempt). | `SpreadconnectClient` re-throws `SpreadconnectTransientError` on 5xx/network for AS to retry. Permanent client errors thrown as `SpreadconnectClientError` (no AS retry). |
| `media_sideload_image()` requires admin includes | Cron-context worker must `require_once ABSPATH . 'wp-admin/includes/{media,file,image}.php'` before first call. | Centralized in `Catalog\ImageSideloader::ensureAdminIncludesLoaded()`, idempotent guard. |
| WC dual hook-sets for Order-List (HPOS + legacy) | Each column/filter/bulk-action requires registration on **both** `manage_edit-shop_order_columns` and `manage_woocommerce_page_wc-orders_columns` (and analogues). | `Inline\OrderListColumns` registers on both hook slugs in one bootstrap call. |
| Discovery race-protection rules | Last-Write-Wins on `_spreadconnect_state`, with compare-and-set in `OrderStateMachine` for `submitting → NEW`; `as_unschedule_action()` on WC-Cancel before scheduling cancel-mirror. | `OrderStateMachine::compareAndSet($order, $expected, $target)` uses an `UPDATE wp_wc_orders_meta SET ... WHERE meta_value=:expected` (HPOS) / `wp_postmeta` fallback. Single-worker-claim of AS as second layer. |
| SC gives no `eventId` for webhook | Plugin must compute deterministic event-id and enforce uniqueness. | `event_id = sha256(eventType+":"+entity.id+":"+sha256(raw_body))`, UNIQUE constraint on `wp_spreadconnect_webhook_log.event_id`. |
| Single Retry-Layer (AS only) | No inner 3× HTTP retry. | `SpreadconnectClient` does fail-fast except for ONE 429-Retry-After attempt. |
| English source strings | All `__()` calls in English; `de_DE.po` translates. | Slice 10 ships translation file. |
| `manage_woocommerce` capability for all Hub UI + AJAX | Cap-check in every adapter entrypoint; documented in Security table. | `Hub\Controller::ensureCapability()` + per-AJAX-action explicit `current_user_can`. |
| Default-Shipping-Type gates Auto-Confirm | Server-side enforcement at Settings save: forces `auto_confirm='off'` if `default_shipping_type=''`. | `Settings\SettingsValidator::sanitize()`. UI also disables radio. |
| `pa_groesse` / `pa_farbe` fixed | Plugin auto-creates these taxonomies if missing; never reads other slugs. | `AttributeProvisioner` on activation + idempotent on each sync. |

### Integrations

| Area | System / Capability | Interface | Version | Notes |
|---|---|---|---|---|
| Spreadconnect Fulfillment API | REST | HTTPS + JSON, Bearer token | **v2.3.9** (OpenAPI v3.0.2; researched 2026-05-03 via `https://rest.spod.com/docs` and `https://api.spod.com/docs`) | Prod base `https://rest.spreadconnect.com`; staging `https://staging.spreadconnect.com`. |
| WordPress | Core platform | PHP 8.2+, WP-CLI in container | **6.9** (verified `docker-compose.yml` `image: wordpress:6.9-php8.2-apache` — current stable as of 2026-05-03) | — |
| WooCommerce | Core e-commerce | Hooks + WC_Order/WC_Product CRUD + WC_Logger + bundled Action Scheduler + HPOS | **10.5+** (researched: WC 10.5.x latest stable per WordPress.org Feb 2026; HPOS Default since 8.2 Oct 2023) | Plugin declares `custom_order_tables` compatibility. |
| Action Scheduler | Background queue | `as_enqueue_async_action`, `as_schedule_recurring_action`, `as_unschedule_action`; default group `spreadconnect` | **bundled with WC ≥ 3.0** (current AS version with WC 10.5.x ≈ 3.8.x). No standalone composer dep needed at runtime. | dev-dep in `composer.json` only if standalone unit tests reference AS classes. |
| WC HPOS (`Custom Order Tables`) | Order storage | `Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` | WC ≥ 8.2 (verified via WC Developer Advisory) | Pflicht-Declare. |
| `register_rest_route` (WP REST API) | REST hosting | `permission_callback` + handler | WP core ≥ 4.4 | Webhook endpoint here. |
| `media_sideload_image()` | WP Media | Admin-includes required outside admin context | WP core | Used in `Catalog\ImageSideloader`. |
| Brain\Monkey | Test mocking | PHPUnit-integrated | **^2.6** (verified `wordpress/plugins/spreadconnect-pod/composer.json`) | REUSE existing harness (REUSE #3). |
| PHPUnit | Test runner | unit + slice tests | **^11.0** (verified `composer.json`) | REUSE. |
| Patchwork | Internal-redefine | redefinable internals listed in `patchwork.json` | latest stable bundled with Brain\Monkey | REUSE. Add `random_bytes`, `microtime`, `time` for v2 (Codebase-Scan EXTEND #6). |
| Mollie Payments for WooCommerce | Payment | WC `processing` hook (consumed) | latest stable from WP plugin repo (verified `scripts/setup.sh:Step 6`) | No direct dependency — plugin reads the hook. |
| Pinterest CAPI plugin (sibling) | Co-located plugin | `woocommerce_order_status_*` hooks | n/a | Co-existence verified: Pinterest fires on `completed`, Spreadconnect on `processing` → no priority collision. |
| `headless-redirect.php` mu-plugin | Headless gate | excludes `REST_REQUEST` ⇒ webhook URL `/wp-json/spreadconnect/v1/webhook` already passes through | n/a | QA-verify in Slice 3; no mu-plugin change required. |
| Composer (PSR-4) | Autoload | `SpreadconnectPod\\` → `includes/` | per `composer.json` | Switch v2's `composer.json` from `classmap` (v1) to `psr-4` (Codebase-Scan EXTEND #5). |
| WPGraphQL / WPGraphQL-WooCommerce | Frontend API | n/a | n/a | Not consumed by plugin (frontend is out-of-scope). |

---

## Quality Attributes (NFRs)

### From Discovery → Technical Solution

| Attribute | Target (from Discovery) | Technical Approach | Measure / Verify |
|---|---|---|---|
| **Webhook responsiveness** | ACK ≤ 8 s, body `[accepted]` | Receiver only verifies HMAC + INSERTs + enqueues AS action; mutation deferred. | Synthetic webhook test in PHPUnit (Slice 3 AC); per-receive timing via `WcLoggerAdapter`. |
| **Throughput — Catalog-Sync** | 100+ articles in single run, no PHP timeout | Pages `GET /articles` (size ≤ 50); per-article AS jobs run in batches of 25, 30 s/request, 5 concurrent. | Manual QA on staging with 100-article test catalog (Slice 4 acceptance); AS visibility via Tools → Scheduled Actions. |
| **Reliability — Failure recovery** | 3 retries; permanent failures land in DLQ + email | AS retry-policy (1m/5m/15m); on-failure callback writes to `wp_spreadconnect_failed_ops` + invokes `FailureNotifier`. | PHPUnit: 3-fail-then-record assertion; manual: simulate 5xx via mock SC instance. |
| **Idempotency — Webhooks** | Duplicate events deterministically rejected | UNIQUE constraint on `event_id` + INSERT-IGNORE pattern. | PHPUnit: identical raw body twice ⇒ second returns 200 + `duplicate`. |
| **Idempotency — Order Submit** | Duplicate `processing` hooks no-op | Skip when `_spreadconnect_order_id` exists; OrderStateMachine compare-and-set on `submitting → NEW`. | PHPUnit AC; integration smoke. |
| **Security — Webhook auth** | HMAC-SHA256 constant-time | `hash_equals()`, no debug bypass, log-on-mismatch with IP. | PHPUnit cases for valid/invalid/expired-secret; security-review against verifier. |
| **Security — Admin actions** | Cap + nonce | `manage_woocommerce` + `wp_verify_nonce` on every state-changing AJAX. | Static review checklist in Slice 7. |
| **Performance — Live stock cache** | 5 min TTL configurable 60–900 s | Transient API; per-product Bulk `GET /stock` deferred async after page render. | Manual QA: page-render timing on staging; cache-hit rate logged at INFO. |
| **Operational visibility** | Hub Dashboard shows Connection / Subs / Failed-Ops counts at a glance | Aggregate queries on indexed columns (`idx_state_op_type`, `idx_state_started_at`). | Manual QA. |
| **Observability — Structured logs** | `WC_Logger` sources `spreadconnect-*`, no `error_log()` | `WcLoggerAdapter` mandatory; `error_log` banned in v2 lint check. | Code-review gate; existing slice tests already assert log calls. |
| **i18n** | English source + de_DE.po | `__()` wrappers + text-domain `spreadconnect-pod` + `load_plugin_textdomain()`. | i18n-extract dry-run in Slice 10; translation file shipped. |
| **HPOS Compliance** | No `update_post_meta()` for orders | Code-review checklist + lint pattern; centralized OrderRepo. | Slice 1 + Slice 5 acceptance; final systemic review. |
| **Concurrency safety** | Race protection per Discovery 5-row table | LWW + UNIQUE-event-id + `as_unschedule_action` + AS single-claim. | Discovery-row-by-row PHPUnit cases in Slice 5/Slice 3. |
| **Maintainability** | PSR-4 + clean module boundaries | Domain ↔ Adapter ↔ Application ↔ Infrastructure separation per "Layer Responsibilities". | Codebase-scanner re-run for follow-up features; 0 `error_log` calls. |

### Monitoring & Observability

| Metric | Type | Source | Target / Alert |
|---|---|---|---|
| `webhook.receive.duration_ms` | histogram via WC_Logger context-data | `Webhook\WebhookController` | P95 < 8000 ms (alert if exceeded). |
| `api.request.duration_ms` | per-endpoint histogram | `SpreadconnectClient` | P95 < 3000 ms. |
| `api.request.status_code` | counter (per endpoint, per code) | `SpreadconnectClient` | Alert on 429-rate spike or 4xx-rate ≠ 0. |
| `failed_ops.count` | gauge | `wp_spreadconnect_failed_ops WHERE state='unresolved'` | Surfaced on Hub Dashboard; alert on Email-on-failure. |
| `webhook.duplicate_count` | counter | `processing_status='duplicate'` | Informational. |
| `sync.run.created/updated/skipped/error` | per-run row | `wp_spreadconnect_sync_history` | Inspect via Hub. |
| `subscriptions.drift` | gauge | weekly `auto_subscription_check` | Alert via persistent admin-notice. |

> No external metrics sink in MVP (no Prometheus/Datadog). Logs are read via WC's built-in `Tools → Logs` viewer + Hub Dashboard counts.

---

## Risks & Assumptions

### Assumptions (technical validation)

| Assumption | Technical Validation | Impact if Wrong |
|---|---|---|
| `POST /orders` accepts inline `shippingType` (Open Q11 = A) | Validate against `https://rest.spod.com/docs` schema during Slice 5 implementation; integration smoke on staging. | Fallback path: omit `shippingType` from `OrderCreate`, then `POST /orders/{id}/shippingType` directly after submit (extra round-trip). Both paths share `Order\OrderSubmitJob`. |
| `GET /stock` is bulk with `productTypeId` or SKU-list filter (Open Q10 = A) | Schema check; staging smoke. | Fallback: per-SKU `GET /stock/{sku}` calls **forbidden in hot paths**; switch to `GET /stock/productType/{id}` then client-side filter. |
| `GET /articles?search=…` is server-side filtered (Open Q9 = A) | Staging smoke. | Fallback: paginated client-side filter on the Article-Picker; no architecture impact since Picker is a single Adapter component. |
| SC picks tax-type from shipping address (Open Q3 default) | Confirm in OpenAPI; inspect response on first staging order. | Fallback: send `taxType` from WC tax setting on each order. Handled in DTO — adding the optional field is non-breaking. |
| `Article.updated` fires on stock-only changes (Open Q1) | Validate via simulate-endpoint + manual stock change in SC dashboard during Slice 6. | If false (defensive default already chosen): periodic stock-sync remains the primary; webhook is bonus. |
| WC HPOS meta access is the only meta path | Verified by WC docs (≥ 8.2). | If a code path slips and uses `update_post_meta` for orders: HPOS-mode silently writes to legacy table, breaks invariants. Guard via lint + Slice-5 acceptance test. |
| `media_sideload_image()` works in cron context after admin-includes load | Verified by WP docs + REUSE-pattern note (`require_once`). | Without includes: undefined function → fatal in worker → AS marks failed → DLQ. Caught by Slice 4 PHPUnit. |
| API-Key Bearer header format is `Authorization: Bearer <key>` | Verified by SC FAQ docs. | If wrong: 401 on first call → Connection auth_failed; Settings show error. |
| `pa_groesse` and `pa_farbe` are the only attribute slugs the plugin must support | Per Discovery ("fix"). | Frontend-side already handles bilingual lookup (variant-utils.ts); plugin-side only writes German slugs. Out-of-Scope explicitly. |
| Webhook URL passes through `headless-redirect.php` mu-plugin | Verified that `REST_REQUEST` is excluded; QA in Slice 3. | If not: webhook returns 301; SC retries fail. Mitigation: explicit `if` clause in mu-plugin. |

### Risks & Mitigation

| Risk | Likelihood | Impact | Technical Mitigation | Fallback |
|---|---|---|---|---|
| SC API rate-limit cascade during initial 100+ article sync | Med | High (sync stalls, partial failure) | Honor `X-RateLimit-Remaining`/`Retry-After`; AS batch + concurrency limits; ETag for `GET /productTypes`. | Per-article job already retries 1m/5m/15m; user can pause via Cancel-Sync. |
| HPOS meta write race between webhook handler and outbound submit | Med | High (state corruption: PROCESSED overwritten by NEW) | `OrderStateMachine::compareAndSet('submitting','NEW')` rejects update if state advanced; LWW elsewhere; AS single-claim per order_id. | Manual reconciliation via Order-Edit "Refresh State" button. |
| Webhook-Receiver exceeds 8 s on slow DB | Low | Med (SC retries) | Receiver only does INSERT (no SELECT, no joins) + AS-enqueue + return; UNIQUE constraint enforces idempotency. | If consistently > 8 s: introduce `INSERT … RETURNING`/index review; add `transient` deduplication layer ahead of DB insert. |
| Image-sideload failure for one article blocks entire run | Low | Low (sync continues) | Per-article handler is independent; failure recorded as `partial`. | Re-Sync from Article-Picker or admin button. |
| `wp_options` API-key plaintext exfil | Low (admin only) | Med | Mask in UI; export excludes secrets; redact in logs. | Post-MVP slice for Sodium encryption. |
| Webhook-secret leak via mistakenly logged headers | Low | Med | Logger redacts `Authorization` and `X-SPRD-SIGNATURE`-related fields. | Regenerate-Secret flow auto-resubscribes. |
| `Article.removed` triggers WC product deletion (forbidden) | Low (anti-pattern temptation) | High (Order-history loss) | `ArticleRemovedJob` sets `post_status='draft'` only — never `wp_delete_post`. PHPUnit AC asserts. | — |
| Pinterest-CAPI sibling plugin priority collision on order-status hooks | Low | Low | Pinterest fires on `completed`; Spreadconnect on `processing` — different states, no shared priority. | Document order-of-execution in Slice 5 README. |
| Action-Scheduler jobs accumulate without purge → DB bloat | Med | Low | `spreadconnect/purge_old_logs` daily AS action drops `wp_spreadconnect_*_log` rows past retention; AS itself self-purges complete jobs after 30 days (default). | Manual purge via Hub → Logs. |
| 4xx during `POST /subscriptions` blocks initial setup | Med | Med | `SubscriptionManager::repair()` is idempotent; partial-success exposed via `save_success_panel` per-step result. | Repair-button on Settings + manual retry. |
| Settings → Test Connection sends API key in admin-AJAX before save | Low | Low | Always over HTTPS; cap+nonce; not logged. | — |
| `media_sideload_image()` writes ~MB image into Media Library on every re-sync | Low | Low | First-sync only by default; "Force re-pull images" toggle off by default. | Hub → Logs surfaces large attachments via WC native. |
| `dbDelta` schema upgrade across versions misaligns indexes | Low | Med | Schema captured in `Bootstrap\Schema::dbDelta()`; activation hook idempotent; index name conventions documented. | Manual `ALTER TABLE` recovery script. |
| Cancel-Sync leaves zombie per-article AS rows | Low | Low | `as_unschedule_action('spreadconnect/sync_article')` filters by group; mass-unschedule by `run_id` arg. In-flight worker is allowed to finish its current article (cooperative cancel). | Operator can purge via Tools → Scheduled Actions. |
| Headless-redirect mu-plugin starts redirecting webhook URL after future change | Low | High (silent SC failure) | Slice 3 ships QA test asserting `200/202` on `/wp-json/spreadconnect/v1/webhook`. | Explicit `/wp-json/spreadconnect/` exclusion comment in mu-plugin. |

---

## Technology Decisions

### Stack Choices

| Area | Technology | Rationale |
|---|---|---|
| Background queue | **Action Scheduler** (bundled with WC) | DB-persistent, retry-policy native, observable via `Tools → Scheduled Actions`, scales to >50 k jobs/h, no extra infra. AVOIDS `wp_schedule_single_event` (transient + memory-only) per Codebase-Scan AVOID #3. |
| HTTP client | **`wp_remote_post`/`wp_remote_get`** wrapped in `Api\SpreadconnectClient` | Native to WP; testable via Brain\Monkey aliases (REUSE pattern). |
| DB layer | **`$wpdb`** for custom tables; **`wc_get_orders` / `WC_Product` CRUD** for WC entities | Stack-native; HPOS-compliant via WC API. |
| Logging | **`wc_get_logger()`** with sources `spreadconnect-*` | Native to WC; replaces `error_log` (AVOID #6). |
| Cache | **WP transient API** | Native; Redis/Memcached optional via WP object-cache drop-in (transparent). |
| Auth (outbound) | Bearer token (`Authorization`) | SC requirement. |
| Auth (inbound) | HMAC-SHA256 raw-body | SC requirement (`X-SPRD-SIGNATURE`). |
| Admin UI | WP Settings API + custom Hub-Page (`add_submenu_page('woocommerce', …)`) + sectioned routing | Discovery decision (REUSE #1 pattern; AVOIDS `add_options_page` AVOID #4). |
| i18n | `__()` + `load_plugin_textdomain()` + `de_DE.po` | WP standard. |
| Test framework | PHPUnit 11 + Brain\Monkey 2.6 + Patchwork | REUSE (Codebase-Scan REUSE #3+#6+#11). |
| Autoload | Composer PSR-4, `SpreadconnectPod\\` → `includes/` | Codebase-Scan EXTEND #5; CLAUDE.md convention. |
| State machine | Plain PHP enum strings + meta-key compare-and-set | Light, no external state-machine lib. |

### Trade-offs

| Decision | Pro | Con | Mitigation |
|---|---|---|---|
| Action Scheduler over RabbitMQ/SQS | Zero infra, WC-native, sufficient throughput | Vendor-locked to WC; throughput cap ~50 k/h | Plenty of headroom for POD use-cases (tens to low-hundreds of jobs/day). |
| Single Retry Layer (AS only) | Simpler debugging, no double-retry storm, DB-persistent | One inner-HTTP retry on 429 only — could be perceived as "too aggressive" giving up on transient hiccups | AS itself retries 1m/5m/15m on `SpreadconnectTransientError`; total max ~21 min before DLQ. |
| Plain plaintext API-key in `wp_options` (MVP) | Simple, no key-mgmt complexity | Storing a Bearer token at-rest unencrypted | Mitigated by WP security model (admin-only DB access); Sodium encryption is a post-MVP slice. |
| HMAC secret one-time reveal | Standard practice; reduces accidental copy-leak | If user closes panel without copying, must regenerate (re-subscribes all 7) | Regenerate flow is automated via `WebhookSecretManager` — single action. |
| `pa_groesse`/`pa_farbe` fixed (not configurable) | Simpler implementation; matches frontend `variant-utils.ts` REUSE | Cannot reuse plugin in shops with English-only attribute taxonomy | Configurable slugs is a post-MVP enhancement. |
| Greenfield — delete v1 before v2 | No migration overhead; clean PSR-4 root | Brief moment of "no plugin installed" on the dev box | Dev-only impact; v2 ships in a single Slice-1 PR. |
| Async webhook processing (return 202 first) | Meets 8 s SLA; SC won't retry; resilient | Mutation latency = AS scheduling delay (~seconds) | Visible in webhook log; Hub-Dashboard surfaces "Last event received". |
| `wp_spreadconnect_*` custom tables vs. options/meta | Indexed, queryable, retention-purgable | Adds 3 new tables to schema; needs `dbDelta` + uninstall | Justified by Hub-Dashboard query patterns (per-state counts, per-entity history). |
| German `de_DE.po` translation in MVP | Native experience for primary user | Adds slice 10 work + maintenance burden | Single-locale at first; plugin still works with English fallback if `.po` missing. |
| PSR-4 autoload (vs. v1 `classmap`) | Composer best practice; CLAUDE.md convention | One-time migration of v1 file naming (`class-foo.php` → `Foo.php` in PSR-4) | v2 is greenfield → no migration cost. |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|---|---|---|---|
| 1 | Does `Article.updated` fire on stock-only changes? | A) Yes (webhook sufficient); B) No (periodic mandatory) | B (defensive) | **DECIDED B**: Periodic + Webhook both shipped (Slice 6). Webhook treated as bonus; never relied upon as sole stock signal. |
| 2 | How does SC respond to `POST /orders` containing WC-linked but SC-deleted Article-IDs? | A) 4xx-validation; B) Order created with skip; C) silent | A (assume strict) | **DECIDED A**: Plugin path treats 4xx as permanent failure → DLQ → Resolution-Modal (`create_order` op). Validate during Slice 5 staging smoke; fallback path identical for B/C. |
| 3 | Tax-type per order/item or SC-picked? | A) WC sends; B) SC picks | B (assumed) | **DECIDED B**: `OrderCreate.taxType` is **omitted** by default. If SC enforces → add field with WC tax setting. Non-breaking either way. |
| 4 | Re-pull images on Article-Update with design change? | A) Always; B) Never; C) Toggle | C | **DECIDED C**: `spreadconnect_force_repull_images` option (default `false`). Per Discovery → Catalog Sync. |
| 5 | Catalog-browser UI for `GET /productTypes/categories`? | A) MVP; B) Out-of-scope | B | **DECIDED B**: Out of scope (reserved wrapper present in `SpreadconnectClient`). |
| 6 | Per-order override of Auto-Confirm / Auto-Cancel-Mirror? | A) Yes; B) Global only | B | **DECIDED B**: Global setting only (Discovery confirms). |
| 7 | API-key encryption at rest? | A) Plain (default); B) Sodium-encrypted via `SECURE_AUTH_KEY` | A (MVP) | **DECIDED A**: Plain MVP; Sodium-encrypted is a post-MVP slice. |
| 8 | Webhook HMAC header / format? | A) Derivable from docs; B) Staging validation needed | A — RESOLVED | **DECIDED A**: `X-SPRD-SIGNATURE`, raw-body sha256-HMAC, base64, no prefix. ACK 8 s / 202 / `[accepted]`. No event-id → plugin-side deterministic hash. (already in Discovery → Webhooks, line 990.) |
| 9 | Server-side filter for `GET /articles?search=…`? | A) Yes; B) No (client-side) | A (assumed); B fallback | **DECIDED A** with B-fallback: Article-Picker uses `?search=` first; on first 4xx for the param, switch to client-side filter on paginated pull. Both implemented behind a single `ArticleRepo::search()`. |
| 10 | `GET /stock` semantics? | A) Bulk with filter; B) Listing + client-side filter; C) Mixed | A | **DECIDED A**: Bulk endpoint with `productTypeId` or SKU-list filter is the primary path. `GET /stock/{sku}` only as fallback in 4xx scenarios. Per-SKU loops **forbidden**. |
| 11 | `POST /orders` accepts inline `shippingType`? | A) Yes; B) Separate request only | A | **DECIDED A**: Inline. Code-path also handles B (separate `POST /shippingType` call after `POST /orders` → state remains `NEW`). Single `OrderSubmitJob` covers both via try-inline-then-fallback. |
| 12 | `_spreadconnect_state` vs `_spreadconnect_needs_action` — separate column vs orthogonal flag? | A) Separate enum value; B) Orthogonal flag | B | **DECIDED B (Discovery already)**: `_spreadconnect_needs_action` is an orthogonal bool meta. Coexists with all six persistent states. |
| 13 | Schema for `wp_spreadconnect_webhook_log.event_id` length? | A) `VARCHAR(64)`; B) `CHAR(64)` | B | **DECIDED B**: `CHAR(64)` — sha256-hex always exactly 64 chars; fixed-length save 1 byte/row vs `VARCHAR`. |
| 14 | Async dispatcher choice for SC plugin (vs sibling pinterest-capi using `wp_schedule_single_event`)? | A) Action Scheduler; B) `wp_schedule_single_event` | A | **DECIDED A**: AS for all SC ops (Discovery + AVOID #3). Pinterest plugin's choice stays untouched. |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-05-03 | Codebase | v1 plugin: 4 PSR-4 service classes covering 2 of 27 endpoints; settings page under `add_options_page`; webhook signature stub bypassed via `WP_DEBUG`; uses `update_post_meta` for orders (HPOS-non-compliant); inner 3× HTTP retry with `sleep()`; `error_log()` for logging (12 call sites). v1 will be deleted before v2 begins. |
| 2026-05-03 | Codebase | Brain\Monkey + PHPUnit 11 + Patchwork harness fully reusable; existing Patchwork redefinable-internals (`sleep`, `hash_equals`, `error_log`) cover key v2 needs. Add `random_bytes`, `microtime`, `time` for v2. |
| 2026-05-03 | Codebase | Sibling pinterest-capi plugin uses `wp_schedule_single_event` — explicitly AVOIDed for SC. Different async semantics: SC is durable-required, Pinterest is fire-and-forget. |
| 2026-05-03 | Codebase | mu-plugin `headless-redirect.php` already excludes `REST_REQUEST`, so webhook URL passes through. QA-verify only. |
| 2026-05-03 | Codebase | Frontend `variant-utils.ts:4-5` uses `pa_groesse`/`pa_farbe` German slugs — plugin-side fixed slug assumption is consistent. |
| 2026-05-03 | Codebase | No `.decisions.md` exists at repo root. Architecture seeds load-bearing decisions: AS-only-retry, HPOS-only meta, WC_Logger over `error_log`, WC-submenu Hub. |
| 2026-05-03 | API spec | OpenAPI v3.0.2 from `https://rest.spod.com/docs` and `https://api.spod.com/docs` ⇒ Spreadconnect Fulfillment-API v2.3.9. 27 endpoints + 7 webhook events + full enum lists. |
| 2026-05-03 | API spec — Webhooks | `X-SPRD-SIGNATURE` raw-body sha256-HMAC base64 (no prefix). 8 s/202/`[accepted]` ACK contract. No `eventId` field. Verified via SC docs. |
| 2026-05-03 | API spec — Auth | Bearer token (`Authorization: Bearer <key>`); staging URL `https://staging.spreadconnect.com`. Verified `https://faq.spreadconnect.app/...Setting-up-the-API`. |
| 2026-05-03 | WP/WC | HPOS Default since WC 8.2 (Oct 2023). Plugins MUST declare `custom_order_tables` compat via `FeaturesUtil::declare_compatibility(...)` on `before_woocommerce_init`. |
| 2026-05-03 | WP/WC | Action Scheduler bundled since WC 3.0; AS production-bench >50 k jobs/h, default batch=25, concurrency=5, claim-cleanup=5min. |
| 2026-05-03 | WP/WC | Verified via `docker-compose.yml`: WordPress **6.9-php8.2-apache**, WC autoinstalled latest stable (10.5+). Composer: PHPUnit 11, Brain\Monkey 2.6, PHP ≥ 8.2. |
| 2026-05-03 | WC HPOS list-columns | Order-list plugins must register on **both** `manage_edit-shop_order_columns` (legacy) AND `manage_woocommerce_page_wc-orders_columns` (HPOS). Same for bulk-actions/filters. |
| 2026-05-03 | WP `media_sideload_image()` | Requires `wp-admin/includes/{media,file,image}.php` outside admin context. Cron-context (AS worker) is non-admin → must `require_once`. Returns attachment-id. |
| 2026-05-03 | Web — POD reviews | Out-of-stock risk on SC is real (Shopify-app reviews + LitCommerce article confirm). Validates Hybrid stock-sync (live + periodic + webhook). |
| 2026-05-03 | Web — Webhook security | HMAC-SHA256 + `hash_equals()` constant-time is industry standard; ephemeral signing keys not relevant for SC (static secret model). |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| 1 | GitHub issue number for this architecture? | #7 — confirmed via `gh issue view 7`: "Spreadconnect POD Plugin v2 — Full API Coverage" (state: open). |
| 2 | Architecture depth (Short / Standard / Detailed)? | **Detailed** — Discovery itself is detailed, codebase scan returned 21 patterns + 19 NEW components, 12 wireframes; planner needs full layer/service map. |
| 3 | Resolve Open Q3 (tax-type send vs SC-pick)? | **B**: SC picks based on shipping address. `OrderCreate.taxType` omitted by default; non-breaking if added later. |
| 4 | Resolve Open Q9 (server-side article search)? | **A with B-fallback**: try `?search=`; if 4xx, fall back to client-side filter on paginated list. Both implemented in `ArticleRepo::search()`. |
| 5 | Resolve Open Q10 (`GET /stock` semantics)? | **A**: bulk-with-filter primary; `GET /stock/{sku}` only fallback. Per-SKU loops forbidden. |
| 6 | Resolve Open Q11 (`POST /orders` shippingType inline)? | **A with B-fallback**: try inline; if rejected, separate `POST /shippingType` call. Single job covers both. |
| 7 | Resolve Open Q7 (API-key encryption)? | **A** (plain MVP); Sodium-encrypted is a post-MVP slice. |
| 8 | Open Q1 (Article.updated on stock-only)? | **B (defensive)** — periodic + webhook both shipped. Webhook is bonus, not authoritative. |
| 9 | Schema choice for `event_id` (varchar vs char)? | **CHAR(64)** — sha256-hex always exactly 64 chars; fixed-length more space-efficient. |
| 10 | Where lives the secret-rotation re-subscription orchestration? | `Subscription\WebhookSecretManager` (Application layer); calls `SubscriptionManager::resubscribeAll(newSecret)` after option update. |
| 11 | What sources does `WcLoggerAdapter` expose? | `spreadconnect-api-client`, `spreadconnect-order-service`, `spreadconnect-webhook-receiver`, `spreadconnect-sync-job`, `spreadconnect-failure`, `spreadconnect-cli`. |
| 12 | Are inline meta-boxes in scope for Slice 5 or Slice 8? | Discovery slices: **Slice 8 (Inline UX)** owns Product-Edit + Order-Edit Meta-Boxes, list-columns, and bulk actions. Slice 5 wires the order lifecycle hooks; Slice 8 surfaces them. |
| 13 | What does the Hub-Header (`connection_status_badge`) read? | Reads cached `GET /authentication` result (transient `sc_health`, 60 s TTL); manual re-test via AJAX `spreadconnect_test_connection`. |
| 14 | Should v1 deletion be its own slice? | No — v1 deletion happens in **Slice 1 (Foundation)** as the first commit's first step. Discovery confirms greenfield. |

---

## ✅ Sign-Off

All template areas checked:

- ✅ Problem & Solution
- ✅ Scope & Boundaries
- ✅ API Design (outbound 27 + 4 reserved; inbound 3 routes; admin AJAX 17 actions; DTOs)
- ✅ Database Schema (3 custom tables + 18 options + meta + transients + realistic data audit)
- ✅ Server Logic (37 services across 6 layers + 2 business-flow diagrams + validation rules)
- ✅ Security (auth, data protection, input validation, rate limiting)
- ✅ Architecture Layers (responsibilities + AS hook inventory + data-flow diagram + error matrix)
- ✅ Migration Map (N/A — greenfield; v1 deletion explained)
- ✅ Constraints & Integrations (12 constraints + 14 integrations with versions)
- ✅ Quality Attributes (14 NFR rows + observability metrics)
- ✅ Risks & Assumptions (10 assumptions + 14 risks)
- ✅ Technology Decisions (11 stack choices + 10 trade-offs)
- ✅ Open Questions (14 — all decided)
- ✅ Research Log (16 entries)
- ✅ Q&A Log (14 entries)

**Status: Ready** — proceed to Gate 1 Compliance.
