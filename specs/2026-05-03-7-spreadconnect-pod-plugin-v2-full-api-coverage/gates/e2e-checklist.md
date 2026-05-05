# E2E Checklist: Spreadconnect POD Plugin v2 — Full API Coverage

**Integration Map:** `integration-map.md`
**Generated:** 2026-05-03
**Stack:** PHP 8.2 + WordPress 6.x + WooCommerce 10.5 + Action-Scheduler

---

## Pre-Conditions

- [ ] All 46 slices APPROVED (Gate 2) — verified in `gates/compliance-slice-*.md`
- [ ] Architecture APPROVED (Gate 1) — verified in `gates/compliance-architecture.md`
- [ ] Integration Map has 0 MISSING_INPUTS, 0 ORPHANED_OUTPUTS, 0 GAPS
- [ ] `composer test` runs green at HEAD (each slice's PHPUnit suite)
- [ ] Docker stack up: `docker compose up -d` (MySQL :3306, WP :8080, phpMyAdmin :8180)
- [ ] WP-CLI accessible inside container; `composer dump-autoload` ran for `SpreadconnectPod\` PSR-4
- [ ] Plugin activated: `WooCommerce → Plugins → Spreadconnect POD v2.0.0`
- [ ] DB tables present: `wp_spreadconnect_failed_ops`, `wp_spreadconnect_webhook_log`, `wp_spreadconnect_sync_history`
- [ ] HPOS active: `wc-orders` admin path resolves (HPOS list table)
- [ ] Action Scheduler accessible via `Tools → Scheduled Actions`
- [ ] WC-CLI: `wp wc shop_order list` returns without warnings
- [ ] Test API-Key for Spreadconnect Staging available; Staging Webhook-Endpoint reachable from internet (or use ngrok)

---

## Happy Path Tests

### Flow A — Initial Setup

1. [ ] **slice-13:** Open `WooCommerce → Spreadconnect`. Hub-Page renders with 8 sections (Dashboard/Catalog/Orders/Webhooks/Failed/Logs/Settings/Subscriptions). Header shows "Connection: unconfigured" red.
2. [ ] **slice-11:** Open `Settings`. Form shows: API-Key (masked), Use Staging (toggle), Auto-Confirm (radio, locked), Default Shipping-Type (none), Auto-Cancel-Mirror (on), Stock-Sync-Interval (6h), Low-Stock-Threshold (10), Live-Cache-TTL (300), Notification-emails (empty), Failure-Recipients flags (3), Retention (90).
3. [ ] **slice-11:** Enter valid Staging API-Key, leave default values for others. Click `Save Changes`.
4. [ ] **slice-12:** Click `Test Connection` BEFORE saving. AJAX returns `{ok:true, message:"…"}`; Connection-Badge becomes green.
5. [ ] **slice-14:** First Save triggers `save_success_panel` with nested `initial_secret_reveal_panel` showing the freshly-generated 32-byte HMAC secret in plaintext exactly once. `[Copy]` and `[Done]` buttons present.
6. [ ] **slice-18:** Settings-Save with valid connection auto-registers all 7 Webhook-Subscriptions on `https://staging.spreadconnect.com`. AJAX response shows `{added:7, removed:0}` or similar success-panel.
7. [ ] **slice-19:** Open `Subscriptions` section. Table shows 7 rows: `Article.added/updated/removed`, `Order.cancelled/processed/needs-action`, `Shipment.sent` — all with status `active` (green).
8. [ ] **slice-13/46:** Open `Dashboard`. Cards show: Connection ✓, Subscriptions 7/7 active, Catalog "Not yet synced", Orders all 0, Failed Ops 0.

### Flow B — Initial Catalog Sync

1. [ ] **slice-26:** Open `Catalog` section. Header shows "Catalog Sync"; Sync-History table empty; `Jetzt synchronisieren` button enabled.
2. [ ] **slice-26:** Click `Jetzt synchronisieren`. AJAX `spreadconnect_sync_now` enqueues `spreadconnect/sync_catalog` action. UI flips to "in-progress"; Live-Progress-Bar appears.
3. [ ] **slice-24:** AS-worker picks up `sync_catalog` job. `GET /articles?page=1&size=50` paginated; one `spreadconnect/sync_article` action enqueued per article. `wp_spreadconnect_sync_history` row created with `state='in_progress'`.
4. [ ] **slice-26:** Live-Progress-Bar polls `/wp-json/spreadconnect/v1/sync-progress?run_id=…` every 3s. UI updates `processed/total`, `created/updated/skipped/error` counters.
5. [ ] **slice-23:** Per-Article job: `getArticle()` -> `getProductType()` (cached) -> `getProductTypeViews()` -> `getHotspot()` -> `createPreviews()` -> `ImageSideloader::sideload()` per preview-URL -> `ProductMapper::upsert()`. WC-Variable-Product appears under `Products`.
6. [ ] **slice-21:** First `sync_article` worker that touches images: `ensureAdminIncludesLoaded()` loads `wp-admin/includes/{media,file,image}.php`. No fatal in cron-context.
7. [ ] **slice-22:** Verify product:
   - `_spreadconnect_article_id` meta present
   - `_spreadconnect_cost` numeric
   - `_spreadconnect_last_sync` timestamp
   - Variations created with attributes `pa_groesse` and `pa_farbe`
   - Each variation has `_sku` from SC-SKU, plus meta `_spreadconnect_sku`, `_spreadconnect_size_id`, `_spreadconnect_color_id`
   - WC `_regular_price` is **empty** (admin pflegt manuell)
   - Featured image set; gallery images set
8. [ ] **slice-26:** After all per-article jobs finish, `sync_history.state='complete'`. Dashboard updates "Catalog: N products linked, last sync …".
9. [ ] **slice-22 idempotency:** Click `Jetzt synchronisieren` again. **No duplicate** WC-products created. Existing products updated; `_regular_price` (if admin set it) remains untouched.

### Flow C — Order-Lifecycle (Auto-Submit + Manual Confirm)

1. [ ] Frontend (or `wp wc shop_order create`): Place a customer order containing a synced Article-Variation. WC-Order created with status `pending` -> `processing` (Mollie or manual flip).
2. [ ] **slice-28:** WC-hook `woocommerce_order_status_processing` fires. `OrderHandler::on_processing` checks `_spreadconnect_order_id` (empty -> proceed) and `as_has_scheduled_action` (no -> proceed); enqueues `spreadconnect/create_order`.
3. [ ] **slice-28:** AS-worker picks up job; builds `OrderCreate`-DTO from WC-Order; `POST /orders` to SC-Staging. On 2xx:
   - `_spreadconnect_order_id` meta set to SC-OrderID
   - `_spreadconnect_state='NEW'` (via CAS from `submitting`)
   - Order-Note "Submitted to Spreadconnect (#SC-…)"
4. [ ] **slice-32:** Open Order-Edit page. Spreadconnect Meta-Box shows:
   - SC-OrderID + link
   - State badge `NEW` (blue)
   - Last-Action timestamp
   - `Refresh State` button
   - Shipping-Type dropdown (lazy-loaded from `GET /orders/{id}/shippingTypes`)
   - `Confirm Order`/`Cancel Order in SC`/`Resend` buttons
   - Webhook-Activity-Last-5 (likely empty initially)
5. [ ] **slice-32:** Select a Shipping-Type from dropdown. AJAX `spreadconnect_save_shipping_type` -> `POST /orders/{id}/shippingType`. Meta `_spreadconnect_shipping_type` updated. Order-Note added.
6. [ ] **slice-32 -> slice-29:** Click `Confirm Order`. AJAX `spreadconnect_confirm_order` -> AS-action `spreadconnect/confirm_order` -> `OrderConfirmJob::handle` -> `POST /orders/{id}/confirm`. State `NEW->CONFIRMED` via CAS. Order-Note added.
7. [ ] **slice-30 (webhook Order.processed):** Trigger via SC-Staging Simulate (Flow J). Plugin verifies HMAC, logs event in `webhook_log`, enqueues `process_webhook_event`, dispatcher routes to `OrderEventHandler`. State `CONFIRMED->PROCESSED`. Order-Note.
8. [ ] **slice-30 (webhook Shipment.sent):** Simulate. `OrderEventHandler` enqueues `spreadconnect/fetch_tracking`. `FetchTrackingJob` calls `GET /orders/{id}/shipments`, writes `_spreadconnect_tracking_number`, `_spreadconnect_tracking_url`. WC-Order status -> `completed`. Order-Note "Shipped: …".
9. [ ] **slice-30 (webhook Order.needs-action):** Simulate. `_spreadconnect_needs_action=true`. Persistent admin-notice (slice-39) shows "Order #X needs action in Spreadconnect" until manually marked resolved.
10. [ ] **slice-30 (webhook Order.cancelled):** Simulate. State `PROCESSED->CANCELLED` (LWW). WC-Order status `cancelled` (if not already). Order-Note.

### Flow D — WC-Cancel-Mirror (NEW state)

1. [ ] Place a fresh order; let `create_order` succeed; SC-State = `NEW` (DO NOT confirm).
2. [ ] **slice-31:** WC-Admin -> set Order to `cancelled`. WC-hook `woocommerce_order_status_cancelled` fires.
3. [ ] **slice-31:** `OrderHandler::on_cancelled` runs `as_unschedule_action('spreadconnect/confirm_order', [...])` (race-protect any pending auto-confirm); then enqueues `spreadconnect/cancel_order_mirror`.
4. [ ] **slice-31:** `OrderCancelMirrorJob::handle` reads state `NEW`, calls `POST /orders/{id}/cancel`. State `NEW->CANCELLED` via CAS. Order-Note "Auto-cancelled in Spreadconnect".

### Flow D' — WC-Cancel-Mirror (CONFIRMED state)

1. [ ] Place + confirm an order; SC-State = `CONFIRMED`.
2. [ ] **slice-31:** WC-Admin -> set Order to `cancelled`.
3. [ ] **slice-31:** `OrderCancelMirrorJob::handle` reads state `CONFIRMED` -> NO API-Call. Order-Note "Cannot cancel in Spreadconnect (state: CONFIRMED)".
4. [ ] **slice-39:** Persistent admin-notice "Order #X — manual intervention needed in SC".

### Flow E — Catalog-Webhook (Article.updated)

1. [ ] Modify an Article in SC-Backend (e.g. price change). SC sends `Article.updated` to plugin webhook.
2. [ ] **slice-15:** `WebhookController::authorize` verifies HMAC via `hash_equals`. Valid -> proceeds. Invalid -> 401 + log entry without payload.
3. [ ] **slice-16:** `EventIdHasher` computes deterministic hash. `WebhookLogRepo::insertOrIgnore` writes new row. Async-schedule `spreadconnect/process_webhook_event`. Response: HTTP 202 + body `[accepted]`.
4. [ ] **slice-17:** `ProcessWebhookEventJob::handle($log_id)` parses payload. Dispatcher matches `Article.*` -> `ArticleEventHandler::dispatch`. Webhook-log row updated `processing_status='success'`.
5. [ ] **slice-25:** `ArticleEventHandler` enqueues `spreadconnect/sync_article` for the affected article-id.
6. [ ] **slice-23:** Re-sync executes. WC-product updated. `_spreadconnect_last_sync` refreshed.
7. [ ] **slice-36:** Stock cache invalidated for affected SKUs (transients deleted) → next live-cache request will refresh.

### Flow E' — Article.removed

1. [ ] Trigger `Article.removed` for an article that has a corresponding WC-product.
2. [ ] **slice-25:** `ArticleEventHandler` enqueues `spreadconnect/handle_article_removed`.
3. [ ] **slice-25:** `ArticleRemovedJob::handle` sets WC-product status to `draft` (NOT `trash`/delete). Order-history preserved.
4. [ ] **slice-34:** Product-Edit page shows badge `removed_in_sc` gray, `Re-Sync (re-create)` button.

### Flow E'' — Webhook Idempotency (duplicate)

1. [ ] Send the **same** webhook payload twice (or trigger SC to re-deliver).
2. [ ] **slice-16:** First receive: row inserted; HTTP 202 + `[accepted]`.
3. [ ] **slice-16:** Second receive: UNIQUE-constraint on `event_id` blocks insert; row updated `processing_status='duplicate'`. HTTP 200 + duplicate-noop body. **No** second async-schedule.

### Flow E''' — Webhook HMAC-Mismatch

1. [ ] Send a webhook with intentionally wrong `X-SPRD-SIGNATURE`.
2. [ ] **slice-15:** Verifier returns false. HTTP 401. WARN-log includes IP + header-keys + reason `invalid_hmac`. NO payload, NO secret in log.

### Flow F — Stock-Sync (Live + Periodic)

1. [ ] **slice-34:** Open Product-Edit page for a linked product. Stock-table renders with cached values (or skeleton if first time).
2. [ ] **slice-36:** Async `LiveStockRefresher::refresh($product_id)` triggered after page-render. Single `GET /stock` call (Bulk, filtered to variation-SKUs). Per-variation update via JS in DOM. Cache transients written.
3. [ ] **slice-34:** Click `[Refresh Stock]`. AJAX `spreadconnect_refresh_stock` triggers same path bypassing cache.
4. [ ] **slice-36:** Wait for AS-recurring `spreadconnect/scheduled_stock_sync` (or trigger manually via `wp action-scheduler run --hooks=spreadconnect/scheduled_stock_sync`). Job pulls Bulk stock and writes `outofstock` only for SKUs where `quantity < threshold`.
5. [ ] Verify: SKUs `>= threshold` have WC-stock-mgmt off (POD = unlimited). SKUs `< threshold` have `_stock_status='outofstock'`.

### Flow G — Failure-Recovery (Permanent failure -> DLQ)

1. [ ] Force a permanent failure: place an order with an SC-deleted article-SKU OR temporarily pass an invalid API-Key.
2. [ ] **slice-28:** `POST /orders` returns 4xx. `OrderSubmitJob::handle` catches `SpreadconnectClientError`, sets `_spreadconnect_state='failed_to_submit'`, writes Order-Note, **does not re-throw** (no retry). Logs `failed_op_pending_record` tag.
3. [ ] **slice-37:** `RetryPolicyListener::on_action_failed` is wired but not triggered (4xx is permanent — recorded directly via the listener path or via the slice-28 logging tag → slice-37 records `FailedOpsRepo::record(op_type='create_order', ...)`).
4. [ ] **slice-39:** `FailureNotifier` sends `wp_mail` to recipients. `AdminNoticeStore::add` posts persistent admin-notice "Order #X failed: <error>".
5. [ ] **slice-38:** Open `Failed Operations` Hub-page. Row visible: op_type=`create_order`, error truncated, `[Resend][Dismiss][View Detail]`.
6. [ ] **slice-38:** Click `Dismiss` for a `create_order` row. UI opens `dismiss_resolution_modal` with 3 choices (Resend / Cancel WC / Submitted manually). **Plain Dismiss not allowed.** Server returns 422 if attempted.
7. [ ] **slice-38:** Choose `Submitted manually`, enter ext-SC-OrderID `SC-9999-EXT`. Server writes `_spreadconnect_order_id='SC-9999-EXT'`, state `NEW`, Order-Note. Failed-Ops row marked `resolved`.
8. [ ] **slice-38:** Click `Resend` on a different (non-create_order) failed op (e.g. `confirm_order`). AS re-enqueues; on success, row marked `resolved` and removed.

### Flow G' — Auto-Retry (Transient failure 5xx)

1. [ ] Force a 5xx during `POST /orders` (e.g. SC-Staging maintenance window). Or use a request-mock if available.
2. [ ] **slice-28:** `OrderSubmitJob::handle` catches `SpreadconnectTransientError`, **re-throws**. AS marks the action failed.
3. [ ] **slice-37:** AS auto-retries: 1min, 5min, 15min (exp. backoff). Action-Scheduler UI (`Tools → Scheduled Actions`) shows 3 attempts.
4. [ ] After 3rd retry fails: `RetryPolicyListener::on_action_failed` fires (hook `action_scheduler_failed_action`). `FailedOpsRepo::record` writes DLQ row. Email + Admin-Notice fires.

### Flow H — Subscription-Repair

1. [ ] Manually delete one of the 7 subscriptions in SC-Staging via API: `DELETE /subscriptions/{id}`.
2. [ ] **slice-19:** Open Subscriptions Hub-page. Click `[Refresh from SC]`. The deleted event shows status `missing` red.
3. [ ] **slice-19:** Click `[Repair All]`. AJAX `spreadconnect_repair_subscriptions` -> `SubscriptionManager::repair`. Missing -> `POST /subscriptions` with our URL+secret. Orphans (URL = ours but stale) -> `DELETE /subscriptions/{id}`.
4. [ ] Verify: All 7 statuses `active` again. Banner shows `{added:1, removed:0}`.

### Flow H' — Regenerate Webhook-Secret

1. [ ] **slice-14:** Open Settings. Click `Regenerate Secret`. Confirm-dialog warns "Will require updating SC subscriptions".
2. [ ] **slice-14:** Confirm -> AJAX `spreadconnect_regenerate_secret`. New 32-byte secret generated. `initial_secret_reveal_panel` shows plaintext once.
3. [ ] **slice-18:** Auto-triggered: existing subscriptions re-registered with new secret (DELETE + POST cycle). Subscriptions Hub shows all 7 still `active`.

### Flow I — Bulk-Resend (Order-List + Failed-Ops)

1. [ ] Create 5 failed `create_order` rows (force 4xx 5x).
2. [ ] **slice-33:** Open Orders-List (HPOS). Filter `SC-State = FAILED`. Select all 5 rows. Bulk-Action: `Re-send to Spreadconnect`.
3. [ ] **slice-33+slice-40:** Pre-flight banner: "Will re-send: 3 · Will skip: 2" (e.g. 2 are already in non-resendable state).
4. [ ] **slice-40:** After confirm, Bulk-Resend coordinator enqueues `create_order` for the 3 eligible. Outcome banner shows "3 of 5 re-queued · 2 skipped (reason)" with `[Show details ▾]`.
5. [ ] **slice-40 (Bulk-Dismiss with create_order in selection):** Select 5 rows including a `create_order` row in Failed-Ops. Choose Bulk-Dismiss. Banner blocks: "X create_order entries require explicit resolution — open them individually". No row marked dismissed.

### Flow J — Dev-Tools Simulate (Staging only)

1. [ ] **slice-44:** Settings page on Staging shows "Dev-Tools" section. Inputs: SC-OrderID + 3 buttons.
2. [ ] **slice-44:** Click `Simulate Order Processed`. AJAX `spreadconnect_simulate_*` -> `SpreadconnectClient::simulateOrderProcessed()`. SC sends webhook back to plugin.
3. [ ] **slice-15+16+17+30:** Plugin receives, verifies HMAC, logs, dispatches, mutates state. Verify in Webhooks Hub-page.
4. [ ] Repeat for `simulate_order_cancelled` and `simulate_shipment_sent`.
5. [ ] On Production (`spreadconnect_use_staging=false`): Dev-Tools section is hidden in Settings. AJAX returns 403 if attempted.

### Flow K — Logs + Webhook-Log + Purge

1. [ ] **slice-42:** Open `Logs` Hub-page. Filter Level + Source + Date-Range. Tail (last 200) renders. Verify Source-strings: `spreadconnect-api-client`, `-order-service`, `-webhook-receiver`, `-sync-job`, `-failure`. Bearer-Tokens redacted to `Bearer ***`.
2. [ ] **slice-42:** Download CSV-Export. Verify columns + redaction.
3. [ ] **slice-41:** Open `Webhooks` Hub-page. Filter event-type, hmac-status, processing-status. Expand a row -> JSON-Payload visible. Verify HMAC-failed entries don't show payload.
4. [ ] **slice-43:** Manually trigger `wp action-scheduler run --hooks=spreadconnect/purge_old_logs`. Older-than-retention rows deleted from `webhook_log` and `failed_ops`. Younger preserved.

### Flow L — Export/Import Settings

1. [ ] **slice-45:** Settings -> Footer button `Export Settings JSON`. AJAX returns JSON-download. Verify file does NOT contain `spreadconnect_api_key` or `spreadconnect_webhook_secret`.
2. [ ] **slice-45:** Modify exported JSON (e.g. change `spreadconnect_low_stock_threshold` to 25). Click `Import Settings JSON`, upload file. AJAX validates via `SettingsValidator::sanitize`. Settings updated; reload Settings page to verify.
3. [ ] Invalid JSON (corrupt) -> error message; no settings change.

### Flow M — i18n (de_DE)

1. [ ] **slice-46:** Set WP-Site-Language to Deutsch (`WPLANG=de_DE` or via `Settings → General`).
2. [ ] Reload Hub. All strings should be German (Settings labels, Sidebar nav, Buttons, Notice texts, Dashboard cards).
3. [ ] No "missing translation" entries in WP-Debug-Log; `i18n-extract` dry-run returns 0 missing strings.

---

## Edge Cases

### Error Handling

- [ ] **slice-15:** Webhook with missing `X-SPRD-SIGNATURE` header -> HTTP 401, no body parse, log entry with `reason='missing_header'`.
- [ ] **slice-15:** Webhook with non-base64 signature -> HTTP 401, log `reason='invalid_hmac'`.
- [ ] **slice-15:** Webhook with empty secret (option blank) -> HTTP 401 (defense-in-depth, even if request comes with empty signature).
- [ ] **slice-08:** API-call hits 429 rate-limit. Inner-retry once after `Retry-After`. Second 429 -> `SpreadconnectTransientError` -> AS-Retry kicks in. No infinite loop.
- [ ] **slice-08:** API-call hits proactive rate-limit threshold (`X-RateLimit-Remaining ≤ 5`). 1s sleep before next call.
- [ ] **slice-23:** Image-Sideload fails (network error). `sync_history.details[i].status='partial'`, product still created without image. No fatal.
- [ ] **slice-22:** `upsert` with empty Variants list -> `ProductMapperException`; no WC-product created.
- [ ] **slice-22:** `upsert` re-run with deleted SC-variant: obsolete WC-variation set to `private` (NOT deleted). New variants added without touching unchanged ones.
- [ ] **slice-29:** Confirm without Shipping-Type set -> Pre-Check fails, no API-call, error in Meta-Box.
- [ ] **slice-29:** Cancel with state `CONFIRMED` -> rejected (state-guard). Order-Note + Notice instead.
- [ ] **slice-31:** Auto-Confirm-Pre-Check-Failure: setting changed mid-flight. Persistent admin-notice (NOT FailedOps). State stays `NEW`.
- [ ] **slice-15+permission_callback:** REST-Route reachable without auth (HMAC is the auth). Verify `headless-redirect.php` mu-plugin DOES NOT 301-redirect `/wp-json/spreadconnect/v1/webhook` (REST_REQUEST excluded).
- [ ] **slice-12:** Test-Connection with invalid API-Key -> 401 from SC -> AJAX returns `{ok:false, message:"Invalid API Key"}`. Connection-Badge red.
- [ ] **slice-18:** Settings-Save with invalid API-Key -> connection-validity-gate fails -> NO subscription-register attempt.

### State Transitions (Edge)

- [ ] **slice-27:** Race `submitting -> NEW` while webhook arrives `submitting -> PROCESSED`. CAS on second write blocks `submitting->NEW` overwrite. State stays `PROCESSED`.
- [ ] **slice-27:** Doubled `processing` hook (Mollie + manual). Idempotency-check sees `_spreadconnect_order_id` already set. Second `OrderSubmitJob` no-ops.
- [ ] **slice-31:** Doubled cancel-mirror enqueue. Second worker sees state `CANCELLED` and is no-op.
- [ ] **slice-16:** Webhook `Article.updated` arrives during `sync_article` job for same article. Both write WC-Product-Meta. LWW. Stock-Cache transient is fresh.
- [ ] **slice-25:** Article-removed for an article with no matching WC-Product. Job logs `processing_error='no matching WC-product'`, no fatal.

### Boundary Conditions

- [ ] **slice-08:** Network-timeout (long latency) -> `SpreadconnectTransientError`. AS-retry.
- [ ] **slice-23:** `sync_article` for article with empty `previews[]` -> product created without images, status `synced` (not `partial`). Re-sync later when SC adds previews.
- [ ] **slice-22:** Article with 50+ variations (size×color matrix). All variations created without timeout (Action Scheduler isolates).
- [ ] **slice-36:** Stock-Sync for product with 100+ variations -> Single Bulk `GET /stock` call (NOT 100 per-SKU calls). Verify rate-limit not exhausted.
- [ ] **slice-43:** Purge-Job with retention=7d -> very few rows. With retention=365d -> rows preserved.

---

## Cross-Slice Integration Points

| # | Integration Point | Slices | How to Verify |
|---|-------------------|--------|---------------|
| 1 | WC-Order processing -> SC-Order create | 28+10 | Place order -> verify `_spreadconnect_order_id` written |
| 2 | SC-webhook receive -> state mutation | 15+16+17+30 | Trigger Simulate -> verify state in WC-Order meta + Webhook-log row |
| 3 | Auto-subscription register on Settings-Save | 11+12+18+10 | Save valid key -> verify all 7 subs created via SC-API |
| 4 | Catalog sync -> WC-Variable-Product creation | 24+23+22+21+20+10 | Sync-Now -> verify product visible in WC-Products list with all metadata |
| 5 | Article-Webhook -> auto re-sync | 15+16+17+25+23 | Update article in SC -> verify WC-product re-synced |
| 6 | Article-Removed-Webhook -> draft | 15+16+17+25 | Remove in SC -> verify WC-status `draft` |
| 7 | Stock-Cache + Live-Refresh + Periodic | 34+36+10 | Open product-edit -> stock visible; trigger periodic -> outofstock applied |
| 8 | WC-Cancel -> SC-Cancel only at NEW | 31+30+27 | Cancel WC-Order at state NEW -> SC cancelled; at CONFIRMED -> Notice only |
| 9 | Permanent-Failure -> DLQ + Email + Notice | 37+39+38 | Force 4xx -> verify failed_ops row + email + admin-notice |
| 10 | Bulk-Resend -> AS re-enqueue | 33+40+38+28 | Bulk-resend 3 orders -> verify 3 new AS-actions enqueued |
| 11 | HPOS dual-hooks for columns | 33+35 | Order-list (HPOS path) + Order-list (legacy edit.php?post_type=shop_order) both show SC-State column |
| 12 | Idempotent webhook (duplicate event) | 15+16 | Same payload twice -> second flagged duplicate, no double-processing |
| 13 | Auto-Confirm-Cancel-Race | 31+29 | Schedule auto-confirm; cancel WC before timer fires -> verify `as_unschedule_action` removed the confirm-job |
| 14 | Dashboard counts aggregate from repos | 46+18+23+37 | Dashboard shows correct counts after all flows run |
| 15 | Settings Validator gates Auto-Confirm | 11+18 | Set Auto-Confirm without Default-Shipping-Type -> validator forces `off` |

---

## Sign-Off

| Tester | Date | Result |
|--------|------|--------|
| | | |

**Notes / Observations:**

(to be filled during E2E run)
