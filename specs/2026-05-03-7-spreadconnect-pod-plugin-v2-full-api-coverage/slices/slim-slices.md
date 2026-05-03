# Slice Decomposition

**Feature:** Spreadconnect POD Plugin v2 — Full API Coverage
**Discovery-Slices:** 10 (grobe Scope-Bloecke)
**Atomare Slices:** 38
**Stack:** PHP 8.2 (WordPress Plugin) + WooCommerce 10.5 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork
**Test Framework:** PHPUnit slice tests in `tests/slices/pod-shop-mvp/` plus optional E2E QA on Docker

---

## Dependency Graph

```
slice-01-cleanup-v1
    |
    v
slice-02-plugin-bootstrap ----> slice-03-hpos-declare
    |                               |
    v                               |
slice-04-schema-dbdelta             |
    |                               |
    v                               v
slice-05-options-defaults     slice-06-i18n-textdomain
    |                               |
    +---------------+---------------+
                    |
                    v
slice-07-http-client-base ---> slice-08-rate-limit-retry
    |                               |
    +---------------+---------------+
                    v
slice-09-dto-value-objects
                    |
                    v
slice-10-endpoint-methods (27 wrappers)
                    |
                    v
slice-11-settings-form ----> slice-12-test-connection-ajax
    |                               |
    v                               |
slice-13-hub-page-skeleton          |
    |                               |
    +---------------+---------------+
                    v
slice-14-webhook-secret-manager
    |
    v
slice-15-webhook-route + verifier ---> slice-16-event-id-hasher
    |                                       |
    +-------------------+-------------------+
                        v
slice-17-process-webhook-event-job
                        |
        +---------------+---------------+
        v                               v
slice-18-subscription-manager      slice-19-subscriptions-ui
                        |
                        v
slice-20-attribute-provisioner
                        |
                        v
slice-21-image-sideloader
                        |
                        v
slice-22-product-mapper
                        |
                        v
slice-23-sync-article-job
                        |
                        v
slice-24-sync-catalog-job + history-row
                        |
                        v
slice-25-article-removed-job + webhook-handler
                        |
                        v
slice-26-catalog-sync-ui-progress
                        |
                        +---> slice-27-order-state-machine
                                    |
                                    v
                        slice-28-order-submit-job (create_order)
                                    |
                                    v
                        slice-29-order-confirm-cancel-jobs
                                    |
                                    v
                        slice-30-order-webhooks-handler (Order.processed/cancelled/needs-action/Shipment.sent)
                                    |
                                    v
                        slice-31-wc-cancel-mirror + auto-confirm-timer
                                    |
                                    v
                        slice-32-order-meta-box (inline UX)
                                    |
                                    v
                        slice-33-order-list-columns-bulk (HPOS + legacy)
                                    |
                                    v
                        slice-34-product-meta-box-margin-stock
                                    |
                                    v
                        slice-35-product-list-columns
                                    |
                                    v
                        slice-36-stock-cache + scheduled-stock-sync
                                    |
                                    v
                        slice-37-failed-ops-repo + retry-policy
                                    |
                                    v
                        slice-38-failed-ops-ui + dismiss-resolution-modal
                                    |
                                    v
                        slice-39-failure-notifier + admin-notice-store
                                    |
                                    v
                        slice-40-bulk-resend-coordinator
                                    |
                                    v
                        slice-41-webhook-log-ui
                                    |
                                    v
                        slice-42-logs-ui-wc-logger-adapter
                                    |
                                    v
                        slice-43-purge-old-logs-job
                                    |
                                    v
                        slice-44-dev-tools-simulate-endpoints
                                    |
                                    v
                        slice-45-export-import-settings
                                    |
                                    v
                        slice-46-i18n-de-po + readme
```

> Hinweis: Die Decomposition liefert 46 atomare Slices (Slice-01..Slice-46). Discovery zaehlt 10 grobe Slices; wir liegen damit bei rund 4-5 atomaren Slices pro Discovery-Slice, was zu Regel 1 (ein Concern pro Slice) und Regel 3 (max 3 produktive Dateien) passt.

---

## Slice-Liste

### Slice-01: Cleanup v1-Plugin (Greenfield-Reset)
- **Scope:** Loescht das gesamte v1-Plugin-Verzeichnis (`wordpress/plugins/spreadconnect-pod/`) und den alten Slice-Test-Stub. Bereitet leere Plugin-Wurzel vor.
- **Deliverables:**
  - Verzeichnis-Loeschung `wordpress/plugins/spreadconnect-pod/*` (ausser `composer.json`-Skeleton fuer Slice 02)
  - Loeschung `tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php`
- **Done-Signal:** `composer test` zeigt keinen v1-Test mehr; Verzeichnis enthaelt nur Boilerplate-Stubs.
- **Dependencies:** []
- **Discovery-Quelle:** Slice 1 "Plugin Foundation" (Greenfield-Vorbedingung)

---

### Slice-02: Plugin-Bootstrap + PSR-4-Autoloader
- **Scope:** Neues Plugin-Skeleton mit `spreadconnect-pod.php` (Header, v2.0.0), `composer.json` mit PSR-4 `SpreadconnectPod\\` -> `includes/`, `uninstall.php` Stub, leeres `includes/Bootstrap/Plugin.php` mit `init()`-Methode.
- **Deliverables:**
  - `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php`
  - `wordpress/plugins/spreadconnect-pod/composer.json`
  - `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php`
- **Done-Signal:** `composer dump-autoload` resolved `SpreadconnectPod\Bootstrap\Plugin`; WP zeigt Plugin in Liste; PHPUnit-Bootstrap laedt ohne Fatal.
- **Dependencies:** ["slice-01-cleanup-v1"]
- **Discovery-Quelle:** Slice 1 "Plugin Foundation"

---

### Slice-03: HPOS-Declare + WC-Compat-Hook
- **Scope:** `before_woocommerce_init`-Hook in `Bootstrap\Plugin` registrieren, der `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` aufruft. Pflicht-Voraussetzung fuer alle Order-Slices.
- **Deliverables:**
  - Edit in `includes/Bootstrap/Plugin.php` (HPOS-Declare-Block)
  - `tests/slices/pod-shop-mvp/slice-01-foundation-hpos.php`
- **Done-Signal:** PHPUnit: Mock `FeaturesUtil` wird mit `'custom_order_tables', plugin-file, true` aufgerufen.
- **Dependencies:** ["slice-02-plugin-bootstrap"]
- **Discovery-Quelle:** Slice 1 "Plugin Foundation" (HPOS-Declare-First-Class)

---

### Slice-04: Schema + dbDelta-Activator
- **Scope:** `Bootstrap\Schema`-Klasse mit `dbDelta()`-basierter Erstellung der drei Custom Tables (`wp_spreadconnect_failed_ops`, `wp_spreadconnect_webhook_log`, `wp_spreadconnect_sync_history`) inkl. aller Indexes laut Architecture-Schema. Aktivierungs- und Uninstall-Hooks verbinden.
- **Deliverables:**
  - `includes/Bootstrap/Schema.php`
  - Edit `includes/Bootstrap/Plugin.php` (Activate-Hook: `Schema::install()`)
  - Edit `uninstall.php` (`Schema::uninstall()`)
- **Done-Signal:** PHPUnit: `Schema::install()` ruft `dbDelta` mit erwarteten 3 CREATE-Statements; `uninstall.php` droppt alle 3 Tables.
- **Dependencies:** ["slice-02-plugin-bootstrap"]
- **Discovery-Quelle:** Slice 1 "Plugin Foundation"

---

### Slice-05: Default-Options-Setter
- **Scope:** `Bootstrap\OptionsDefaults`-Klasse setzt bei Plugin-Activate alle 19 `spreadconnect_*` Options auf Discovery-Defaults (idempotent via `add_option`).
- **Deliverables:**
  - `includes/Bootstrap/OptionsDefaults.php`
  - Edit `includes/Bootstrap/Plugin.php` (Activate-Hook erweitern)
- **Done-Signal:** PHPUnit: nach Activate sind alle 19 Options mit Discovery-Defaults vorhanden; bei wiederholtem Activate keine Ueberschreibung.
- **Dependencies:** ["slice-02-plugin-bootstrap"]
- **Discovery-Quelle:** Slice 1 "Plugin Foundation" (Default-Options setzen)

---

### Slice-06: i18n-Textdomain-Loader
- **Scope:** `load_plugin_textdomain('spreadconnect-pod', false, dirname(plugin_basename) . '/languages')` registrieren. `languages/` Verzeichnis mit leerem `de_DE.po` Stub anlegen (wird in Slice 46 mit Strings gefuellt).
- **Deliverables:**
  - Edit `includes/Bootstrap/Plugin.php` (`plugins_loaded`-Hook)
  - `wordpress/plugins/spreadconnect-pod/languages/spreadconnect-pod-de_DE.po` (Stub)
- **Done-Signal:** PHPUnit: `load_plugin_textdomain` wird mit korrekten Argumenten aufgerufen; `.po`-Datei existiert.
- **Dependencies:** ["slice-02-plugin-bootstrap"]
- **Discovery-Quelle:** Slice 1 "Plugin Foundation" (i18n-Setup)

---

### Slice-07: HTTP-Client Basis (Bearer-Auth + Base-URL-Toggle)
- **Scope:** `Api\SpreadconnectClient` mit `request($method, $path, $body)`-Kern, Bearer-Token aus Option, Staging-/Prod-Base-URL via `spreadconnect_use_staging`. Logging-Stub (Source `spreadconnect-api-client`). NOCH KEINE Retry- oder Rate-Limit-Logik.
- **Deliverables:**
  - `includes/Api/SpreadconnectClient.php`
  - `includes/Api/SpreadconnectClientError.php` (Permanent)
  - `includes/Api/SpreadconnectTransientError.php` (Retryable)
- **Done-Signal:** PHPUnit: `request('GET', '/authentication')` setzt korrekten `Authorization: Bearer ...` Header; Staging-Toggle wechselt Base-URL.
- **Dependencies:** ["slice-05-options-defaults"]
- **Discovery-Quelle:** Slice 2 "API Client + Authentication"

---

### Slice-08: HTTP-Client Rate-Limit + Single-429-Retry
- **Scope:** Erweitert `SpreadconnectClient` um `X-RateLimit-Remaining`-Awareness (proactive 1s sleep bei <=5), 429-Retry-After-Honor mit genau einem Wiederholungsversuch innerhalb des Jobs. 4xx -> `SpreadconnectClientError`, 5xx/Network -> `SpreadconnectTransientError`.
- **Deliverables:**
  - Edit `includes/Api/SpreadconnectClient.php`
  - `tests/slices/pod-shop-mvp/slice-02-client-retry.php`
- **Done-Signal:** PHPUnit: 429 mit Retry-After loest genau einen Inner-Retry aus; doppeltes 429 wirft Transient; 4xx wirft Client; 5xx wirft Transient.
- **Dependencies:** ["slice-07-http-client-base"]
- **Discovery-Quelle:** Slice 2 "API Client + Authentication" (Rate-Limit-Header, 429-Handling)

---

### Slice-09: DTO Value Objects
- **Scope:** Alle DTOs unter `Api\Dto\` als `final readonly`-Klassen mit Factory-Validation: `OrderCreate`, `OrderItem`, `Address`, `Money`, `ArticleSummary`, `ArticleDetail`, `Variant`, `ShippingType`, `Subscription`, `StockEntry`, `Preview`, `WebhookEvent`, `AuthOk`. Snake_case <-> camelCase Mapper als Helper.
- **Deliverables:**
  - `includes/Api/Dto/` (alle DTOs als Datei pro Klasse — gruppiert; ein-Slice-Ausnahme fuer Boilerplate-Setup, alle Klassen sind kleine Pure-Value-Objects)
  - `includes/Api/Dto/DtoMapper.php` (snake/camel)
  - `tests/slices/pod-shop-mvp/slice-02-dto-validation.php`
- **Done-Signal:** PHPUnit: jede DTO-Factory rejectet ungueltige Inputs (leerer SKU, falsche ISO-Country); valide Inputs ergeben unveraenderbare Instanz.
- **Dependencies:** ["slice-02-plugin-bootstrap"]
- **Discovery-Quelle:** Slice 2 "API Client + Authentication" (Methods typed Request/Response-DTOs)

---

### Slice-10: Endpoint-Wrapper-Methoden (27 Methods)
- **Scope:** Alle 27 typed Endpoint-Methoden auf `SpreadconnectClient`: `authenticate()`, `getArticles()`, `getArticle()`, `createOrder()`, `getOrder()`, `confirmOrder()`, `cancelOrder()`, `getShipments()`, `getShippingTypes()`, `setShippingType()`, `getSubscriptions()`, `createSubscription()`, `deleteSubscription()`, `simulateOrderCancelled()`/`simulateOrderProcessed()`/`simulateShipmentSent()`, `getProductTypes()`, `getProductType()`, `getProductTypeViews()`, `getProductTypeSizeChart()`, `getHotspot()`, `createPreviews()`, `getStock()`, `getStockBySku()`, `getStockByProductType()`. Plus 4 reservierte Wrapper (`POST /articles`, `DELETE /articles/{id}`, `PUT /orders/{id}`, `POST /designs/upload`) mit `throw NotImplemented`.
- **Deliverables:**
  - Edit `includes/Api/SpreadconnectClient.php`
  - `tests/slices/pod-shop-mvp/slice-02-endpoints.php`
- **Done-Signal:** PHPUnit: pro Methode wird der korrekte HTTP-Verb + Path + Body-Shape an die mock'ed `request()` weitergegeben; Response wird zur korrekten DTO mapped.
- **Dependencies:** ["slice-08-rate-limit-retry", "slice-09-dto-value-objects"]
- **Discovery-Quelle:** Slice 2 "API Client + Authentication" (Methods fuer alle 27 Endpoints typed)

---

### Slice-11: Settings-Form (Settings API + Settings-Validator)
- **Scope:** `Hub\View\Settings`-Page mit allen Form-Feldern (API-Key masked, Staging-Toggle, Auto-Confirm-Radio, Default-Shipping-Type-Dropdown, Auto-Cancel-Toggle, Pull-Images, Stock-Sync-Interval, Low-Stock-Threshold, Live-Cache-TTL, Notify-Emails, Failure-Recipients, Retention-Days). `Settings\SettingsValidator::sanitize()` mit Auto-Confirm-Gating-Regel (Server forced `off` bei leerem Default-Shipping-Type).
- **Deliverables:**
  - `includes/Hub/View/Settings.php`
  - `includes/Settings/SettingsValidator.php`
- **Done-Signal:** PHPUnit: `sanitize()` zwingt `auto_confirm='off'` bei leerem default-shipping-type; alle 19 Felder werden persistiert.
- **Dependencies:** ["slice-05-options-defaults", "slice-06-i18n-textdomain"]
- **Discovery-Quelle:** Slice 7 "Hub-Page Skeleton + Settings"

---

### Slice-12: AJAX-Action `spreadconnect_test_connection`
- **Scope:** Admin-AJAX-Handler ruft `GET /authentication` mit unsaved API-Key aus POST-Body. Verifiziert `manage_woocommerce` + Nonce. Liefert `{ok, message}`. Wired an "Test Connection"-Button auf Settings.
- **Deliverables:**
  - `includes/Hub/Ajax/TestConnection.php`
  - Edit `includes/Hub/View/Settings.php` (JS-Handler + Button)
- **Done-Signal:** PHPUnit: ungueltige Nonce -> 403; gueltige Nonce -> Client wird mit POST-Body-Key aufgerufen; Response-Mapping `ok=true/false`.
- **Dependencies:** ["slice-10-endpoint-methods", "slice-11-settings-form"]
- **Discovery-Quelle:** Slice 2 "API Client + Authentication" (Test-Connection-Roundtrip)

---

### Slice-13: Hub-Page-Skeleton + Section-Routing
- **Scope:** `Hub\Controller` registriert `add_submenu_page('woocommerce', 'Spreadconnect', ..., 'spreadconnect')`. Routet `?section=...` zu Section-View-Klassen (Dashboard, Catalog, Orders, Webhooks, Failed, Logs, Settings, Subscriptions). Default = `dashboard`. Capability-Gate `manage_woocommerce`. Sidebar-Nav-Markup-Helper.
- **Deliverables:**
  - `includes/Hub/Controller.php`
  - `includes/Hub/View/Sidebar.php` (Nav-Helper)
  - `includes/Hub/View/Dashboard.php` (Stub mit 5 Cards, Daten kommen in Folge-Slices)
- **Done-Signal:** Manueller QA: alle 8 Sections lassen sich aufrufen; Capability-Check redirected ohne `manage_woocommerce`. PHPUnit: Routing dispatched zur korrekten View-Klasse.
- **Dependencies:** ["slice-11-settings-form"]
- **Discovery-Quelle:** Slice 7 "Hub-Page Skeleton + Settings"

---

### Slice-14: Webhook-Secret-Manager + One-Time-Reveal-Panel
- **Scope:** `Subscription\WebhookSecretManager` generiert (random_bytes(32)+base64), persistiert in `spreadconnect_webhook_secret`. AJAX `spreadconnect_regenerate_secret` triggert Generation + One-Time-Reveal-Panel-Payload. Nested-Sub-Panel `initial_secret_reveal_panel` im `save_success_panel` bei erstem Save.
- **Deliverables:**
  - `includes/Subscription/WebhookSecretManager.php`
  - Edit `includes/Hub/View/Settings.php` (Reveal-Panel-Markup + Regenerate-Button)
  - `includes/Hub/Ajax/RegenerateSecret.php`
- **Done-Signal:** PHPUnit: erste Generation schreibt Option + gibt Plaintext-Reveal zurueck; zweite Generation invalidiert alte; Regenerate triggert Re-Subscribe (Slice 18 Hook). UI: Plaintext nur einmal sichtbar.
- **Dependencies:** ["slice-13-hub-page-skeleton"]
- **Discovery-Quelle:** Slice 3 "Webhook Receiver + Subscriptions" (HMAC-Secret-Manager)

---

### Slice-15: Webhook-Receiver-Route + HMAC-Verifier
- **Scope:** `Webhook\WebhookController` registriert REST-Route `POST /spreadconnect/v1/webhook`. `permission_callback` ruft `Webhook\WebhookSignatureVerifier::verify($raw_body, $header, $secret)` mit `hash_equals()` constant-time-compare. Handler liest raw body, verifiziert HMAC, gibt bei Mismatch 401.
- **Deliverables:**
  - `includes/Webhook/WebhookController.php`
  - `includes/Webhook/WebhookSignatureVerifier.php`
- **Done-Signal:** PHPUnit: gueltige HMAC -> 200/202; ungueltige HMAC -> 401 + Log-Eintrag mit IP+Headers (kein Payload). Constant-time-compare verifiziert.
- **Dependencies:** ["slice-14-webhook-secret-manager"]
- **Discovery-Quelle:** Slice 3 "Webhook Receiver + Subscriptions" (HMAC-SHA256 Constant-Time-Verify)

---

### Slice-16: Event-ID-Hasher + Webhook-Log-Insert + ACK 202
- **Scope:** `Webhook\EventIdHasher` (`sha256(eventType+":"+entity.id+":"+sha256(raw_body))`). `Webhook\WebhookLogRepo::insertOrIgnore()` mit UNIQUE-Constraint-Check; bei Duplikat -> Update `processing_status='duplicate'`, return 200+`duplicate`. Bei Fresh -> 202 + `[accepted]` literal Body. Async-Schedule `spreadconnect/process_webhook_event` mit `log_id`.
- **Deliverables:**
  - `includes/Webhook/EventIdHasher.php`
  - `includes/Webhook/WebhookLogRepo.php`
  - Edit `includes/Webhook/WebhookController.php` (Insert+Schedule+ACK)
- **Done-Signal:** PHPUnit: identischer raw body 2x -> 1x 202+`[accepted]`, 1x 200+`duplicate`; Action `spreadconnect/process_webhook_event` wird mit `log_id` enqueued.
- **Dependencies:** ["slice-15-webhook-route"]
- **Discovery-Quelle:** Slice 3 "Webhook Receiver + Subscriptions" (Event-Log + Async-Schedule + Idempotency)

---

### Slice-17: Process-Webhook-Event-Job (Dispatcher)
- **Scope:** `Webhook\ProcessWebhookEventJob::handle($log_id)` laedt Row, parsed Payload, dispatched per `eventType`-Prefix (`Order.*`, `Article.*`, `Shipment.*`) an Domain-Handler (Stubs in dieser Phase). Updated `processing_status='success'/'error'`. Bei Error: `FailedOpsRepo::record(op_type='handle_webhook')` (Repo wird in Slice 37 implementiert; bis dahin Logging-only Stub).
- **Deliverables:**
  - `includes/Webhook/ProcessWebhookEventJob.php`
  - Edit `includes/Bootstrap/Plugin.php` (`add_action('spreadconnect/process_webhook_event', ...)`)
- **Done-Signal:** PHPUnit: Job mit Stub-Handlers schreibt `success`; bei thrown Exception schreibt `error`+`processing_error`.
- **Dependencies:** ["slice-16-event-id-hasher"]
- **Discovery-Quelle:** Slice 3 "Webhook Receiver + Subscriptions" (Async Schedule: process_webhook_event)

---

### Slice-18: Subscription-Manager + Auto-Register on Save
- **Scope:** `Subscription\SubscriptionManager::diff()`, `register()`, `removeOrphans()`. Bei Settings-Save mit gueltiger Connection: alle 7 erwarteten Subscriptions registrieren (idempotent: skip existing). `as_schedule_recurring_action('spreadconnect/auto_subscription_check', WEEK_IN_SECONDS)` fuer Drift-Detection.
- **Deliverables:**
  - `includes/Subscription/SubscriptionManager.php`
  - Edit `includes/Settings/SettingsValidator.php` (Hook nach Save)
- **Done-Signal:** PHPUnit: nach Save mit valid Auth werden 7 `POST /subscriptions` aufgerufen mit unserer URL+Secret; bei existing wird skipped.
- **Dependencies:** ["slice-12-test-connection-ajax", "slice-14-webhook-secret-manager"]
- **Discovery-Quelle:** Slice 3 "Webhook Receiver + Subscriptions" (Subscription-Auto-Register)

---

### Slice-19: Subscriptions-Manager-UI + Repair-Button
- **Scope:** `Hub\View\Subscriptions`-Page listet alle 7 erwarteten Events mit Status `active`/`missing`/`orphan`/`unknown`. AJAX-Action `spreadconnect_repair_subscriptions` ruft `SubscriptionManager::repair()` (POST fehlende, DELETE orphans deren URL = unsere). Save-Success-Panel zeigt Step-Result.
- **Deliverables:**
  - `includes/Hub/View/Subscriptions.php`
  - `includes/Hub/Ajax/RepairSubscriptions.php`
- **Done-Signal:** Manuell: Repair-Klick zeigt `{added: N, removed: M}`-Banner. PHPUnit: Diff-Logic markiert orphan korrekt (URL-Match-only fuer DELETE).
- **Dependencies:** ["slice-18-subscription-manager"]
- **Discovery-Quelle:** Slice 3 "Webhook Receiver + Subscriptions" (Subscription-Manager-UI mit Repair)

---

### Slice-20: Attribute-Provisioner (`pa_groesse`/`pa_farbe`)
- **Scope:** `Catalog\AttributeProvisioner::ensure()` legt fehlende WC-Attribute-Taxonomies `pa_groesse` und `pa_farbe` idempotent an (via `wc_create_attribute()` falls fehlend). Wird auf Plugin-Activate + vor jedem Sync aufgerufen.
- **Deliverables:**
  - `includes/Catalog/AttributeProvisioner.php`
  - Edit `includes/Bootstrap/Plugin.php` (Activate-Hook)
- **Done-Signal:** PHPUnit: bei fehlendem `pa_groesse` wird `wc_create_attribute()` aufgerufen; bei vorhandenem nicht.
- **Dependencies:** ["slice-04-schema-dbdelta"]
- **Discovery-Quelle:** Slice 4 "Catalog-Sync" (Attribute-Slugs werden vom Plugin angelegt)

---

### Slice-21: Image-Sideloader (Cron-Context-Safe)
- **Scope:** `Catalog\ImageSideloader::ensureAdminIncludesLoaded()` (idempotent guard) + `sideload(string $url, int $product_id)` -> attachment_id | WP_Error. Wird in Cron-Context von SyncArticleJob genutzt.
- **Deliverables:**
  - `includes/Catalog/ImageSideloader.php`
  - `tests/slices/pod-shop-mvp/slice-04-image-sideloader.php`
- **Done-Signal:** PHPUnit: ohne `media_sideload_image()` definiert -> includes werden geladen; mit definiert -> kein Re-Require; Failure -> WP_Error returned.
- **Dependencies:** ["slice-02-plugin-bootstrap"]
- **Discovery-Quelle:** Slice 4 "Catalog-Sync" (Image-Sideload-Pflichtincludes)

---

### Slice-22: Product-Mapper (Article + ProductType -> WC-Variable-Product)
- **Scope:** `Catalog\ProductMapper::upsert($articleDetail, $productType, $previews)` erstellt/updated `WC_Product_Variable` mit Variations pro Size/Color (SKU = SC-SKU). Schreibt Meta `_spreadconnect_article_id`, `_spreadconnect_product_type_id`, `_spreadconnect_cost`, `_spreadconnect_last_sync`. WC-Preis NIE setzen. Idempotent via `_spreadconnect_article_id`-Reverse-Lookup.
- **Deliverables:**
  - `includes/Catalog/ProductMapper.php`
  - `tests/slices/pod-shop-mvp/slice-04-product-mapper.php`
- **Done-Signal:** PHPUnit: erster Aufruf -> WC-Product erstellt; zweiter Aufruf mit selber `article_id` -> update, kein Duplicate; WC-Preis bleibt unveraendert ueber Calls.
- **Dependencies:** ["slice-20-attribute-provisioner"]
- **Discovery-Quelle:** Slice 4 "Catalog-Sync" (WC-Variable-Product-Upsert)

---

### Slice-23: Sync-Article-Job (Per-Article)
- **Scope:** `Catalog\SyncArticleJob::handle(['article_id'=>..., 'run_id'=>...])`. Sequenz: `getArticle()` + `getProductType()` (cached) + `createPreviews()` + `ImageSideloader::sideload()` + `ProductMapper::upsert()`. Schreibt `sync_history.details`-Eintrag. Bei Image-Failure -> `partial`. Registriert Action-Hook.
- **Deliverables:**
  - `includes/Catalog/SyncArticleJob.php`
  - `includes/Catalog/SyncHistoryRepo.php`
  - Edit `includes/Bootstrap/Plugin.php` (`add_action('spreadconnect/sync_article', ...)`)
- **Done-Signal:** PHPUnit: erfolgreicher Lauf -> WC-Product + `sync_history.details[i].status='created'/'updated'`; Image-Failure -> `partial`; 4xx -> Job-Failure.
- **Dependencies:** ["slice-10-endpoint-methods", "slice-21-image-sideloader", "slice-22-product-mapper"]
- **Discovery-Quelle:** Slice 4 "Catalog-Sync" (Action sync_article)

---

### Slice-24: Sync-Catalog-Job + History-Row
- **Scope:** `Catalog\SyncCatalogJob::handle(['trigger'=>...])` paginated `GET /articles`, schedult pro Article-ID einen `spreadconnect/sync_article`-Job mit `run_id`. Insert-Row in `wp_spreadconnect_sync_history` mit `state='in_progress'`. Bei Abschluss aller per-article-Jobs (Tracker via Counter) -> `state='complete'`.
- **Deliverables:**
  - `includes/Catalog/SyncCatalogJob.php`
  - Edit `includes/Catalog/SyncHistoryRepo.php` (Counter-Increment-Methoden)
  - Edit `includes/Bootstrap/Plugin.php` (`add_action('spreadconnect/sync_catalog', ...)`)
- **Done-Signal:** PHPUnit: Catalog mit 50 Articles -> 50 sync_article-Actions enqueued + 1 history-Row.
- **Dependencies:** ["slice-23-sync-article-job"]
- **Discovery-Quelle:** Slice 4 "Catalog-Sync" (Action sync_catalog paginiert)

---

### Slice-25: Article-Removed-Job + Article-Webhook-Handler
- **Scope:** `Catalog\ArticleRemovedJob::handle(['article_id'])` setzt WC-Product-Status auf `draft` (NICHT delete). `Webhook\ArticleEventHandler` dispatched `Article.added`/`updated` zu `sync_article`-Schedule und `Article.removed` zu `handle_article_removed`. Wired in `ProcessWebhookEventJob`.
- **Deliverables:**
  - `includes/Catalog/ArticleRemovedJob.php`
  - `includes/Webhook/ArticleEventHandler.php`
  - Edit `includes/Webhook/ProcessWebhookEventJob.php` (Dispatcher-Wiring)
- **Done-Signal:** PHPUnit: `Article.removed`-Webhook -> WC-Product-Status `draft` (kein Delete); `Article.added`/`updated` -> `sync_article` enqueued.
- **Dependencies:** ["slice-17-process-webhook-event-job", "slice-24-sync-catalog-job"]
- **Discovery-Quelle:** Slice 4 "Catalog-Sync" (Webhook-Reaktion `Article.*`)

---

### Slice-26: Catalog-Sync-UI + Live-Progress-AJAX
- **Scope:** `Hub\View\Catalog`-Page mit "Jetzt synchronisieren"-Button (AJAX `spreadconnect_sync_now` enqueues `spreadconnect/sync_catalog`). Live-Progress-Bar pollt alle 3s `/wp-json/spreadconnect/v1/sync-progress?run_id=...`. REST-Route `/sync-progress` registrieren. Sync-History-Table.
- **Deliverables:**
  - `includes/Hub/View/Catalog.php`
  - `includes/Hub/Rest/SyncProgress.php`
  - `includes/Hub/Ajax/SyncNow.php`
- **Done-Signal:** Manuell: Klick triggered Sync, Progress-Bar updated alle 3s. PHPUnit: REST-Route liefert `{run_id, processed, total, ...}` aus `sync_history`-Row.
- **Dependencies:** ["slice-13-hub-page-skeleton", "slice-24-sync-catalog-job"]
- **Discovery-Quelle:** Slice 4 "Catalog-Sync" (Hub-Page Catalog-Sync + Live-Progress AJAX)

---

### Slice-27: Order-State-Machine (Compare-and-Set)
- **Scope:** `Order\OrderStateMachine` mit `compareAndSet(WC_Order $order, string $expected, string $target)` als atomic-ish Wrapper um `_spreadconnect_state`. Verhindert `submitting->NEW`, wenn ein Webhook bereits `PROCESSED` geschrieben hat.
- **Deliverables:**
  - `includes/Order/OrderStateMachine.php`
  - `tests/slices/pod-shop-mvp/slice-05-state-machine.php`
- **Done-Signal:** PHPUnit: bei `expected='submitting'` und State bereits `'PROCESSED'` -> CAS returned `false`, kein Write; bei Match -> Write erfolgreich.
- **Dependencies:** ["slice-04-schema-dbdelta"]
- **Discovery-Quelle:** Slice 5 "Order-Lifecycle" (Race Protection)

---

### Slice-28: Order-Submit-Job (`create_order`)
- **Scope:** `Order\OrderSubmitJob::handle(['order_id'])` baut `OrderCreate`-DTO aus WC-Order, ruft `createOrder()`. Bei 2xx: meta-Update via StateMachine `submitting->NEW`, Order-Note "Submitted #SC-N". Bei 4xx: state `failed_to_submit` + (Stub-)FailedOpsRepo. Bei 5xx: re-throw `SpreadconnectTransientError` -> AS-Retry. WC-Hook `woocommerce_order_status_processing` -> `OrderHandler::on_processing` enqueues Job (idempotent: skip wenn `_spreadconnect_order_id` bereits gesetzt).
- **Deliverables:**
  - `includes/Order/OrderSubmitJob.php`
  - `includes/Order/OrderHandler.php`
  - Edit `includes/Bootstrap/Plugin.php` (Hook + Action-Wiring)
- **Done-Signal:** PHPUnit: `processing`-Hook enqueues 1x; doppelter Hook -> idempotent skip; 2xx-Response -> meta `_spreadconnect_state='NEW'`+`_spreadconnect_order_id`; 4xx -> `failed_to_submit`; 5xx -> Transient.
- **Dependencies:** ["slice-10-endpoint-methods", "slice-27-order-state-machine"]
- **Discovery-Quelle:** Slice 5 "Order-Lifecycle" (Action create_order)

---

### Slice-29: Order-Confirm + Order-Cancel-Jobs
- **Scope:** `Order\OrderConfirmJob` (`POST /confirm`, State `NEW->CONFIRMED`, Pre-Check Shipping-Type-set). `Order\OrderCancelJob` (`POST /cancel`, nur wenn State `NEW`). Action-Hooks `spreadconnect/confirm_order` und `spreadconnect/cancel_order`.
- **Deliverables:**
  - `includes/Order/OrderConfirmJob.php`
  - `includes/Order/OrderCancelJob.php`
  - Edit `includes/Bootstrap/Plugin.php` (Action-Wiring)
- **Done-Signal:** PHPUnit: Confirm ohne Shipping-Type -> Pre-Check-Failure (kein API-Call); mit Shipping-Type -> `POST /confirm` + State `CONFIRMED`. Cancel nur bei State `NEW`.
- **Dependencies:** ["slice-28-order-submit-job"]
- **Discovery-Quelle:** Slice 5 "Order-Lifecycle" (Confirm/Cancel-Jobs + Pre-Check)

---

### Slice-30: Order-Webhooks-Handler (Order.* + Shipment.sent)
- **Scope:** `Webhook\OrderEventHandler` verarbeitet `Order.processed` (State `PROCESSED`), `Order.cancelled` (State `CANCELLED` + WC-Status `cancelled`), `Order.needs-action` (`_spreadconnect_needs_action=true` + persistente Admin-Notice + Order-Note), `Shipment.sent` (Schedule `fetch_tracking` -> `getShipments()` + Tracking-Meta + WC-Status `completed`). Wired in `ProcessWebhookEventJob`. Reverse-Lookup WC-Order via `_spreadconnect_order_id`.
- **Deliverables:**
  - `includes/Webhook/OrderEventHandler.php`
  - `includes/Order/FetchTrackingJob.php`
  - Edit `includes/Webhook/ProcessWebhookEventJob.php` (Dispatcher-Wiring)
- **Done-Signal:** PHPUnit: jedes der 4 Events mutiert State + WC-Status korrekt; State-Override `submitting->PROCESSED` ueberlebt spaeter eintreffende `submitting->NEW` (LWW).
- **Dependencies:** ["slice-28-order-submit-job", "slice-25-article-removed-job"]
- **Discovery-Quelle:** Slice 5 "Order-Lifecycle" (Webhook-Reaktionen)

---

### Slice-31: WC-Cancel-Mirror + Auto-Confirm-Timer
- **Scope:** `Order\OrderHandler::on_cancelled` -> `as_unschedule_action('spreadconnect/confirm_order')` (Race-Schutz) -> `OrderCancelMirrorJob`. Auto-Confirm-Timer: bei OrderSubmit-Success mit Setting `auto_confirm` != off -> `as_schedule_single_action('spreadconnect/confirm_order', $when)`. Auto-Confirm-Pre-Check-Failure -> persistente Admin-Notice (kein FailedOps).
- **Deliverables:**
  - `includes/Order/OrderCancelMirrorJob.php`
  - Edit `includes/Order/OrderHandler.php` (Cancel-Hook + Auto-Confirm-Schedule)
  - Edit `includes/Bootstrap/Plugin.php` (`woocommerce_order_status_cancelled`)
- **Done-Signal:** PHPUnit: WC-Cancel mit State `NEW` -> `as_unschedule` + Cancel-Mirror enqueued; Cancel mit State `CONFIRMED` -> Order-Note + Admin-Notice (kein API-Call). Auto-Confirm-Schedule bei Setting `immediate` enqueues sofort.
- **Dependencies:** ["slice-29-order-confirm-cancel-jobs", "slice-30-order-webhooks-handler"]
- **Discovery-Quelle:** Slice 5 "Order-Lifecycle" (WC-Cancel-Mirror)

---

### Slice-32: Order-Edit-Meta-Box (Inline-UX)
- **Scope:** `Inline\OrderMetaBox` registriert via `add_meta_boxes` (HPOS-Screen + Legacy). Rendert State-Badge, Shipping-Type-Dropdown (lazy load `getShippingTypes()`), Confirm/Cancel/Refresh/Resend-Buttons, Shipments-List, Webhook-Activity-Last-5 (Read aus `webhook_log_repo`). AJAX-Actions: `confirm_order`, `cancel_order`, `refresh_order_state`, `save_shipping_type`, `cancel_auto_confirm`.
- **Deliverables:**
  - `includes/Inline/OrderMetaBox.php`
  - `includes/Hub/Ajax/OrderActions.php` (alle 5 Order-AJAX-Actions zusammen)
  - `assets/js/order-meta-box.js`
- **Done-Signal:** Manuell: Order-Edit-Page zeigt Meta-Box mit allen Bloecken; Confirm-Button triggert `POST /confirm`. PHPUnit: AJAX-Handler verifiziert Cap+Nonce.
- **Dependencies:** ["slice-29-order-confirm-cancel-jobs", "slice-13-hub-page-skeleton"]
- **Discovery-Quelle:** Slice 8 "Inline UX" (Order-Edit Meta-Box)

---

### Slice-33: Order-List-Columns + Filter + Bulk-Action (HPOS + Legacy)
- **Scope:** `Inline\OrderListColumns` registriert auf BEIDEN Hook-Sets (`manage_edit-shop_order_columns` legacy + `manage_woocommerce_page_wc-orders_columns` HPOS) Spalten "SC-State" (Badge) + "SC-OrderID" (Link). Filter-Dropdown `SC-State`. Bulk-Action "Re-send to Spreadconnect" mit `bulk_resend_outcome_panel` (Pre-Flight-Count + Per-Row-Outcome).
- **Deliverables:**
  - `includes/Inline/OrderListColumns.php`
  - `includes/Failure/BulkResendCoordinator.php`
- **Done-Signal:** PHPUnit: beide Hook-Sets registrieren Spalten; Filter `?sc_state=NEW` filtert korrekt. Bulk pre-flight liefert `{will_resend, will_skip}`.
- **Dependencies:** ["slice-32-order-meta-box"]
- **Discovery-Quelle:** Slice 8 "Inline UX" (Order-List Spalten dual)

---

### Slice-34: Product-Edit-Meta-Box (Article-Picker + Margin + Stock)
- **Scope:** `Inline\ProductMetaBox` rendert: Article-Picker (debounced search via `getArticles?search`), Cost-Display, Margin-Live-JS-Calc bei Preis-Form-Change (rot/gelb/gruen), Live-Stock-Indicator-Tabelle (cached 5min), Re-Sync-Button. AJAX-Actions: `link_article`, `unlink_article`, `refresh_stock`.
- **Deliverables:**
  - `includes/Inline/ProductMetaBox.php`
  - `includes/Hub/Ajax/ProductActions.php`
  - `assets/js/product-meta-box.js`
- **Done-Signal:** Manuell: Picker zeigt Search-Results; Margin updated live bei Preis-Change. PHPUnit: `link_article` schreibt `_spreadconnect_article_id` + enqueued `sync_article`.
- **Dependencies:** ["slice-22-product-mapper", "slice-23-sync-article-job"]
- **Discovery-Quelle:** Slice 8 "Inline UX" (Product-Edit Meta-Box)

---

### Slice-35: Product-List-Spalten + Filter
- **Scope:** `Inline\ProductListColumns` fuegt Spalten "SC-Linked" (Icon), "SC-Cost", "Margin" (farbcodiert) zur WC-Product-List hinzu. Filter-Dropdown "All / Linked / Unlinked / Margin <20%".
- **Deliverables:**
  - `includes/Inline/ProductListColumns.php`
  - `tests/slices/pod-shop-mvp/slice-08-product-list.php`
- **Done-Signal:** PHPUnit: Spalten registriert; Filter-Query joins auf postmeta korrekt. Margin <20% filtert nur Produkte mit Cost+WC-Preis.
- **Dependencies:** ["slice-34-product-meta-box-margin-stock"]
- **Discovery-Quelle:** Slice 8 "Inline UX" (Product-List Spalten)

---

### Slice-36: Stock-Cache + Periodic Stock-Sync-Job
- **Scope:** `Stock\StockCache` (Transient `sc_stock_{sku}`, configurable TTL 60-900s). `Stock\LiveStockRefresher::refresh($product_id)` ruft Bulk `getStock()` mit Variation-SKUs. `Stock\StockSyncJob::handle()` (recurring per Setting-Intervall) updated WC-Variation-Stock + `outofstock` nur bei `quantity < threshold`. `Webhook\ArticleEventHandler` triggered Stock-Refresh bei `Article.updated`.
- **Deliverables:**
  - `includes/Stock/StockCache.php`
  - `includes/Stock/StockSyncJob.php`
  - `includes/Stock/LiveStockRefresher.php`
- **Done-Signal:** PHPUnit: Cache-Hit liefert ohne API-Call; Periodic-Job updated `outofstock` bei `quantity=0`; bei `quantity >= threshold` keine WC-Stock-Mutation. Webhook re-triggert Refresh.
- **Dependencies:** ["slice-23-sync-article-job", "slice-25-article-removed-job"]
- **Discovery-Quelle:** Slice 6 "Stock-Sync"

---

### Slice-37: Failed-Ops-Repo + AS-Retry-Policy + Permanent-Record
- **Scope:** `Failure\FailedOpsRepo` (CRUD auf `wp_spreadconnect_failed_ops`). Action-Scheduler-on-Failure-Hook (`action_scheduler_failed_action`): nach 3 Retries -> `FailedOpsRepo::record()`. Update der Job-Handler in Slice 23/24/25/28/29/30/31, sodass sie konsistent `SpreadconnectTransientError` werfen (-> AS-Retry) bzw. `SpreadconnectClientError` (-> permanent direkt zu DLQ).
- **Deliverables:**
  - `includes/Failure/FailedOpsRepo.php`
  - `includes/Failure/RetryPolicyListener.php`
- **Done-Signal:** PHPUnit: Job mit 3x 5xx -> Retry-Counter=3 -> Failed-Ops-Row mit `op_type`+`payload`+`error_message`. 4xx -> direkt Row, kein Retry.
- **Dependencies:** ["slice-28-order-submit-job", "slice-23-sync-article-job"]
- **Discovery-Quelle:** Slice 9 "Failure-Recovery" (Auto-Retry + DLQ-Eintrag)

---

### Slice-38: Failed-Ops-UI + Dismiss-Resolution-Modal
- **Scope:** `Hub\View\FailedOps`-Page listet `wp_spreadconnect_failed_ops`. Pro-Row [Resend]/[Dismiss]/[View Detail]. AJAX `spreadconnect_resend_failed_op` re-enqueues korrespondierende Action; `spreadconnect_dismiss_failed_op` (refused fuer `op_type=create_order` ohne Resolution); `spreadconnect_resolve_create_order` (3-Choice-Modal: resend / cancel_wc / submitted_externally + ext-SC-OrderID-Input).
- **Deliverables:**
  - `includes/Hub/View/FailedOps.php`
  - `includes/Hub/Ajax/FailedOpsActions.php`
  - `assets/js/failed-ops-modal.js`
- **Done-Signal:** Manuell: Plain-Dismiss eines `create_order`-Eintrags zeigt 3-Choice-Modal. PHPUnit: Server refusiert plain-Dismiss fuer create_order; `submitted_externally` schreibt `_spreadconnect_order_id` + State `NEW`.
- **Dependencies:** ["slice-37-failed-ops-repo"]
- **Discovery-Quelle:** Slice 9 "Failure-Recovery" (Failed-Ops-UI mit Resend/Dismiss/Bulk + Dismiss-Resolution-Modal)

---

### Slice-39: Failure-Notifier + Persistent-Admin-Notice-Store
- **Scope:** `Failure\FailureNotifier::dispatch($failedOp)` -> `wp_mail` an Recipients aus Setting (gated per `notify_on_*`-Flag). `Failure\AdminNoticeStore` (Option-basierte Liste): persistent Notices mit per-op-type-Dismiss-Policy. Admin-Notices-Hook rendert sie auf jeder Admin-Page.
- **Deliverables:**
  - `includes/Failure/FailureNotifier.php`
  - `includes/Failure/AdminNoticeStore.php`
- **Done-Signal:** PHPUnit: bei FailedOp-Insert wird `wp_mail` mit Recipients aufgerufen (wenn Flag on); Notice landet in Option + wird auf `admin_notices`-Hook gerendert.
- **Dependencies:** ["slice-37-failed-ops-repo"]
- **Discovery-Quelle:** Slice 9 "Failure-Recovery" (Email + Persistent Admin-Notice)

---

### Slice-40: Bulk-Resend-Coordinator (Outcome-Panel)
- **Scope:** Verbindet `Failure\BulkResendCoordinator` (aus Slice 33 Stub) mit `FailedOpsRepo`: pre-flight zaehlt eligible Rows, postFlight liefert per-row outcome. Banner-Refusal bei 0 eligible. Bulk-Dismiss respektiert per-Op-Type-Regel (stoppt bei `create_order` in Selection).
- **Deliverables:**
  - Edit `includes/Failure/BulkResendCoordinator.php` (vollstaendige Implementation)
  - Edit `includes/Hub/View/FailedOps.php` (Bulk-UI)
  - `tests/slices/pod-shop-mvp/slice-09-bulk-resend.php`
- **Done-Signal:** PHPUnit: Bulk mit 5 Rows (3 eligible, 2 not) -> Banner `3 of 5 re-queued, 2 skipped`. Bulk-Dismiss mit `create_order` in Selection -> abort + Hint.
- **Dependencies:** ["slice-38-failed-ops-ui", "slice-33-order-list-columns-bulk"]
- **Discovery-Quelle:** Slice 9 "Failure-Recovery" (Bulk-Action)

---

### Slice-41: Webhook-Log-UI
- **Scope:** `Hub\View\Webhooks`-Page liest `wp_spreadconnect_webhook_log` mit Filter (event-type, date-range, hmac-status, processing-status). Pro-Row expandable -> JSON-Payload + Processing-Log. Pagination via `idx_received_at`.
- **Deliverables:**
  - `includes/Hub/View/Webhooks.php`
  - `assets/js/webhook-log-expand.js`
- **Done-Signal:** Manuell: Filter funktioniert, Expand zeigt JSON. PHPUnit: Filter-Query nutzt korrekte Indexes.
- **Dependencies:** ["slice-16-event-id-hasher", "slice-13-hub-page-skeleton"]
- **Discovery-Quelle:** Slice 10 "Logs + Polish" (Webhook-Log-UI)

---

### Slice-42: Logs-UI + WC-Logger-Adapter
- **Scope:** `Logging\WcLoggerAdapter` mit Sources `spreadconnect-api-client`, `spreadconnect-order-service`, `spreadconnect-webhook-receiver`, `spreadconnect-sync-job`, `spreadconnect-failure`, `spreadconnect-cli`. Redaction fuer Bearer-Token + `X-SPRD-SIGNATURE`. `Hub\View\Logs`-Page liest `wc-logs/`-Files mit Filter (level, source, date-range), Tail-View (last 200), CSV-Export.
- **Deliverables:**
  - `includes/Logging/WcLoggerAdapter.php`
  - `includes/Hub/View/Logs.php`
- **Done-Signal:** Manuell: Logs-Page zeigt Tail. PHPUnit: Adapter redacted `Authorization`-Header zu `Bearer ***`. Replace `error_log` calls -> 0 in Plugin-Sources.
- **Dependencies:** ["slice-13-hub-page-skeleton", "slice-07-http-client-base"]
- **Discovery-Quelle:** Slice 10 "Logs + Polish" (Logs-UI + WC_Logger-Adapter)

---

### Slice-43: Purge-Old-Logs-Recurring-Job
- **Scope:** `Catalog\PurgeOldLogsJob::handle()` (Action `spreadconnect/purge_old_logs`, recurring daily): DELETE in `webhook_log` und `failed_ops` aelter als jeweilige Retention-Setting. `as_schedule_recurring_action` registriert beim Activate.
- **Deliverables:**
  - `includes/Logging/PurgeOldLogsJob.php`
  - Edit `includes/Bootstrap/Plugin.php` (Recurring-Schedule)
- **Done-Signal:** PHPUnit: mit Setting `webhook_retention=90`: Rows >90 Tage werden geloescht; juengere bleiben.
- **Dependencies:** ["slice-42-logs-ui-wc-logger-adapter"]
- **Discovery-Quelle:** Slice 10 "Logs + Polish" (Auto-Purge-Cron)

---

### Slice-44: Dev-Tools (Simulate-Endpoints, nur Staging)
- **Scope:** Settings-Section "Dev-Tools" (sichtbar nur bei `spreadconnect_use_staging=true`). Buttons triggern `simulateOrderCancelled()`, `simulateOrderProcessed()`, `simulateShipmentSent()` per Test-Order-ID-Input. AJAX-Action gated auf Staging.
- **Deliverables:**
  - `includes/Hub/View/SettingsDevTools.php`
  - `includes/Hub/Ajax/SimulateEvent.php`
- **Done-Signal:** Manuell auf Staging: Klick triggert SC-Simulate-Endpoint -> Webhook eingeht in Plugin. Production: UI-Section hidden.
- **Dependencies:** ["slice-30-order-webhooks-handler", "slice-11-settings-form"]
- **Discovery-Quelle:** Slice 10 "Logs + Polish" (Dev-Tools Simulate-Endpoints)

---

### Slice-45: Export/Import-Settings (JSON)
- **Scope:** AJAX-Actions `spreadconnect_export_settings` (serialize alle `spreadconnect_*` Options ohne `api_key`+`webhook_secret` -> JSON-Download) und `spreadconnect_import_settings` (deserialize + validate via `SettingsValidator::sanitize()`).
- **Deliverables:**
  - `includes/Hub/Ajax/ExportImportSettings.php`
  - Edit `includes/Hub/View/Settings.php` (Footer-Buttons)
- **Done-Signal:** PHPUnit: Export-Output enthaelt nicht `spreadconnect_api_key`+`spreadconnect_webhook_secret`. Import valid JSON -> Options gesetzt; invalid -> Error.
- **Dependencies:** ["slice-11-settings-form"]
- **Discovery-Quelle:** Slice 10 "Logs + Polish" (Export/Import-Settings)

---

### Slice-46: i18n-de_DE.po + README + Final-Polish
- **Scope:** Alle `__()`-Strings in `de_DE.po` uebersetzen (deutsche UI-Beschriftungen). README.md fuer Plugin (Setup, Features, Architecture-Overview-Link). Hub-Dashboard-Karten mit echten Daten (Counts aus repos: failed_ops, sync_history, subscription-state).
- **Deliverables:**
  - `wordpress/plugins/spreadconnect-pod/languages/spreadconnect-pod-de_DE.po` (vollstaendig uebersetzt)
  - `wordpress/plugins/spreadconnect-pod/README.md`
  - Edit `includes/Hub/View/Dashboard.php` (echte Aggregat-Counts)
- **Done-Signal:** Manuell: WP mit `WPLANG=de_DE` zeigt deutsche Strings; Dashboard zeigt korrekte Zahlen aus DB. `i18n-extract` dry-run findet 0 missing strings.
- **Dependencies:** ["slice-43-purge-old-logs-job", "slice-39-failure-notifier"]
- **Discovery-Quelle:** Slice 10 "Logs + Polish" (i18n + README)

---

## Flow-Traceability

Jeder "Integration:"-Testfall aus den Discovery-Slices muss in mindestens einem atomaren Slice landen. Die Tabelle ueberprueft Regel 7 (Datenfluss endet bei der konsumierenden Funktion).

| Discovery-Slice | Integration-Testfall (Testability-Spalte) | Abgedeckt in Slice | Done-Signal |
|---|---|---|---|
| Slice 1 "Plugin Foundation" | Activate-Hook erstellt Tables | slice-04-schema-dbdelta | dbDelta erzeugt 3 Tables |
| Slice 1 | Uninstall droppt Tables | slice-04-schema-dbdelta | uninstall.php droppt alle 3 |
| Slice 1 | HPOS-Declare via FeaturesUtil-Mock | slice-03-hpos-declare | FeaturesUtil-Mock-Call verifiziert |
| Slice 2 "API Client + Auth" | HTTP 200/4xx/5xx/429 Cases via wp_remote_*-Mock | slice-08-rate-limit-retry | 4 Status-Code-Cases gecovered |
| Slice 2 | Test-Connection-Roundtrip | slice-12-test-connection-ajax | AJAX-Handler ruft `getAuthentication()` |
| Slice 3 "Webhook Receiver + Subs" | HMAC valid/invalid -> 202/401 | slice-15-webhook-route | beide Cases gecovered |
| Slice 3 | Event landet in Log-Table | slice-16-event-id-hasher | Insert via WebhookLogRepo verifiziert |
| Slice 3 | Subscription-Repair-Logic | slice-19-subscriptions-ui | Repair-AJAX diff'ed + POST/DELETE |
| Slice 4 "Catalog-Sync" | Article->WC-Product Mapping | slice-22-product-mapper | upsert-Test |
| Slice 4 | Idempotency (re-sync update, kein Dupe) | slice-22-product-mapper | zweiter Aufruf Update |
| Slice 4 | Image-Failure -> partial | slice-23-sync-article-job | partial-State im sync_history |
| Slice 4 | Webhook -> re-sync | slice-25-article-removed-job | ArticleEventHandler enqueued sync_article |
| Slice 4 | Removal -> draft-State | slice-25-article-removed-job | WC-Status `draft` |
| Slice 5 "Order-Lifecycle" | WC-Hook -> POST /orders | slice-28-order-submit-job | processing-Hook -> create_order Job |
| Slice 5 | Confirm-Pre-Check (Shipping-Type required) | slice-29-order-confirm-cancel | Pre-Check ohne Shipping-Type bricht ab |
| Slice 5 | Cancel-Mirror nur bei NEW | slice-31-wc-cancel-mirror | State-Check NEW |
| Slice 5 | Webhook-Tracking -> WC-completed | slice-30-order-webhooks-handler | Shipment.sent -> WC-Status completed |
| Slice 6 "Stock-Sync" | Cache-Hit/Miss | slice-36-stock-cache | Cache-Hit ohne API-Call |
| Slice 6 | Threshold-Logic | slice-36-stock-cache | outofstock nur unter threshold |
| Slice 6 | Bulk-Stock-Update | slice-36-stock-cache | LiveStockRefresher Bulk-Call |
| Slice 6 | WC-Stock + outofstock-Setting | slice-36-stock-cache | WC-Variation-Stock-Update |
| Slice 7 "Hub-Page + Settings" | Page-Load aller Sections | slice-13-hub-page-skeleton | Routing-Test alle 8 Sections |
| Slice 7 | Settings-Save persistiert | slice-11-settings-form | sanitize -> Options-Write |
| Slice 8 "Inline UX" | Spalten-Render (HPOS + Legacy) | slice-33-order-list-columns | beide Hook-Sets |
| Slice 8 | Filter-Funktion | slice-33-order-list-columns | sc_state-Filter-Query |
| Slice 8 | Margin-Live-Calc | slice-34-product-meta-box | JS-Recalc bei Preis-Form-Change |
| Slice 9 "Failure-Recovery" | 3-Retry-then-Failed-Ops-Eintrag | slice-37-failed-ops-repo | Retry-Counter=3 -> DLQ-Row |
| Slice 9 | Email-Send | slice-39-failure-notifier | wp_mail-Mock-Call |
| Slice 9 | Resend-Re-Queue | slice-38-failed-ops-ui | AJAX-Resend re-enqueues Action |
| Slice 10 "Logs + Polish" | Purge-Logic | slice-43-purge-old-logs | Rows >Retention geloescht |

---

## Qualitaets-Checkliste (Verifikation)

- [x] Jeder Slice hat maximal 3 produktive Deliverable-Dateien (Ausnahme: Slice 09 DTOs als Boilerplate-Setup zugelassen pro Regel 3-Ausnahme)
- [x] Jeder Slice hat ein messbares Done-Signal
- [x] Dependencies sind azyklisch (DAG aufgezeichnet)
- [x] Alle Discovery-Slices sind abgedeckt: 1->04+05+03+02+06, 2->07+08+09+10+12, 3->15+16+17+18+19+14, 4->20+21+22+23+24+25+26, 5->27+28+29+30+31, 6->36, 7->11+13, 8->32+33+34+35, 9->37+38+39+40, 10->41+42+43+44+45+46
- [x] Kein Slice mischt mehrere Concerns (Schema/Service/UI streng getrennt)
- [x] Schema/Service-Slices kommen vor UI-Slices (Beispiel: Slice 22 ProductMapper vor Slice 26 Catalog-Sync-UI)
- [x] Stack ist korrekt erkannt (PHP 8.2 + WordPress 6.9 + WC 10.5)
- [x] Flow-Completeness: jeder "Integration:"-Testfall aus Discovery-Testability ist in Tabelle gemapped
