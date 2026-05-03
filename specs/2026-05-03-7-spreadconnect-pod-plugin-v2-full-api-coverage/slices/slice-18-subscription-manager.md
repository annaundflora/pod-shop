# Slice 18: Subscription-Manager + Auto-Register on Settings-Save

> **Slice 18 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-18-subscription-manager` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-12-test-connection-ajax", "slice-14-webhook-secret-manager"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork + Action-Scheduler) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `get_option`/`update_option`/`do_action`/`add_action`/`add_filter`/`rest_url`/`as_schedule_recurring_action`/`as_next_scheduled_action`/`as_unschedule_action`/`current_user_can`/`__()`; `SpreadconnectClient::getSubscriptions`/`createSubscription`/`deleteSubscription` via Mockery-Stub oder Test-Subclass) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: Settings-Save mit valider Connection -> Save-Success-Panel zeigt `Subscriptions: 7/7 active`; SC-Backend listet 7 Webhooks auf unsere `wp-json/spreadconnect/v1/webhook`-URL) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (kein realer SC-API-Call; Action-Scheduler-Funktionen via Brain\Monkey gestubbt — Test verifiziert nur "wurde mit erwarteten Args aufgerufen", nicht den AS-Internal-State) |

---

## Ziel

Liefert die Application-Schicht fuer Webhook-Subscription-Lifecycle: `Subscription\SubscriptionManager` diff't expected-vs-actual Subscriptions, registriert fehlende per `POST /subscriptions`, entfernt Orphans (nur URL-Match-Owner) per `DELETE /subscriptions/{id}` und exponiert `register()` fuer (a) Auto-Register direkt nach erfolgreichem Settings-Save mit valider Connection, (b) Re-Subscribe nach Secret-Rotation (Listener auf `spreadconnect/webhook_secret_rotated` aus Slice 14), (c) wiederkehrenden Drift-Check via `as_schedule_recurring_action('spreadconnect/auto_subscription_check', WEEK_IN_SECONDS)`. Slice 19 baut darauf die Repair-UI; diese Slice liefert ausschliesslich die Service-Logik plus Settings-Save-Hook-Wiring.

---

## Acceptance Criteria

> **Quelle der 7 Expected Events:** `architecture.md` Z. 41 + Z. 175 + DTO `WebhookEvent.eventType`-Enum (Z. 175). Liste unten in Constraints "Expected Events".

1) **GIVEN** `SubscriptionManager::diff()` wird aufgerufen UND `getSubscriptions()` liefert ein Array mit 3 active Subscriptions auf unsere callback URL (`Article.added`, `Article.updated`, `Order.processed`) plus 1 Subscription auf eine fremde callback URL (`Order.cancelled` -> `https://other-shop.example/webhook`)
   **WHEN** der Diff-Algorithmus laeuft
   **THEN** liefert `diff()` ein Result-Objekt/Array mit drei disjunkten Listen: `missing` enthaelt genau die 4 Events `Order.cancelled`, `Order.needs-action`, `Shipment.sent`, `Article.removed`; `active` enthaelt die 3 `eventType`-Strings auf unserer URL; `orphans` ist leer (fremde URLs werden nie in `orphans` aufgenommen — Eigentums-Filter via URL-Match auf `home_url('wp-json/spreadconnect/v1/webhook')`). Architecture-Referenz: `architecture.md` Z. 605, Z. 108.

2) **GIVEN** `SubscriptionManager::diff()` wird aufgerufen UND `getSubscriptions()` liefert eine Subscription auf `Article.added` mit unserer callback URL aber einer **veralteten** `callbackUrl` (`http://localhost:8080/wp-json/...` waehrend `home_url(...)` jetzt `https://shop.example/wp-json/...` ist)
   **WHEN** der Diff laeuft
   **THEN** wird die veraltete Subscription als `orphans` klassifiziert (URL-Match-Logik vergleicht **exakte** Strings — nicht Host-Substring), und `Article.added` ist gleichzeitig in `missing` enthalten. Repair muss DELETE+POST nacheinander ausfuehren.

3) **GIVEN** `SubscriptionManager::register()` wird mit gueltigem `WebhookSecretManager::peek()`-Output und einer `home_url()`-Webhook-URL aufgerufen UND `getSubscriptions()` liefert eine leere Liste (`[]`)
   **WHEN** der Manager den Diff verarbeitet
   **THEN** wird `SpreadconnectClient::createSubscription()` genau **7 Mal** aufgerufen — jeder Call hat `eventType` aus der Expected-Events-Liste (siehe Constraints), `callbackUrl` exakt `home_url('wp-json/spreadconnect/v1/webhook', 'https')` (oder `'rest_url'`-Aequivalent), und `secret` exakt `WebhookSecretManager::peek()`. Die Reihenfolge der 7 Calls ist nicht kritisch (Tests duerfen `expect`-Order-agnostisch sein), aber alle 7 `eventType`-Strings muessen abgedeckt sein.

4) **GIVEN** `SubscriptionManager::register()` wird aufgerufen UND `getSubscriptions()` liefert bereits **alle 7** expected Subscriptions auf unsere callback URL mit dem aktuellen Secret
   **WHEN** der Manager den Diff verarbeitet
   **THEN** wird `createSubscription()` **niemals** aufgerufen (`missing` ist leer); `deleteSubscription()` wird **niemals** aufgerufen (`orphans` ist leer); der Manager liefert ein Summary `['added' => 0, 'removed' => 0, 'skipped' => 7, 'errors' => []]` zurueck. Idempotenz-Verifikation.

5) **GIVEN** `SubscriptionManager::removeOrphans()` wird aufgerufen UND `getSubscriptions()` liefert 2 Subscriptions auf unsere veraltete URL UND 1 Subscription auf `https://other-shop.example/webhook`
   **WHEN** der Manager Orphans entfernt
   **THEN** wird `deleteSubscription($id)` genau **2 Mal** mit den IDs der eigenen Orphans aufgerufen; die fremde URL wird **niemals** geloescht (Pflicht-Constraint, architecture.md Z. 108: "Never deletes foreign URLs"). Bei jedem `deleteSubscription`-Aufruf wird zuvor URL-Match gegen `home_url(...)` ausgefuehrt.

6) **GIVEN** `SubscriptionManager::register()` laeuft UND ein einzelner `createSubscription()`-Aufruf wirft `SpreadconnectClientError` (4xx, z. B. Konflikt)
   **WHEN** der Manager das Error verarbeitet
   **THEN** wird der Loop **fortgesetzt** (kein Abbruch), das Error wird im Summary unter `errors[]` als `['eventType' => $event, 'message' => __('Subscription registration failed', 'spreadconnect-pod')]` gesammelt, und die verbleibenden Events werden weiterhin registriert. Bei `SpreadconnectTransientError` wird die Exception **re-thrown** (damit Action-Scheduler retried) — kein Sammeln, kein Continue.

7) **GIVEN** `SettingsValidator::sanitize()` wird mit einer Settings-Save-Submission aufgerufen, die einen **neuen** API-Key enthaelt UND ein vorheriger Save-Hook hat das Secret schon initialisiert (oder `WebhookSecretManager::peek()` liefert leeren String — Initial-Setup)
   **WHEN** Sanitize abgeschlossen ist und die Persistenz erfolgreich war
   **THEN** wird ein registrierter `update_option_*`-Post-Save-Hook (oder `add_action('updated_option_spreadconnect_api_key', ...)`) ausgeloest, der: (a) `SpreadconnectClient::authenticate()` aufruft (Connection-Verify), (b) bei Erfolg und leerem Secret `WebhookSecretManager::generate()` aufruft, (c) `SubscriptionManager::register()` aufruft. Bei `authenticate()`-Failure wird `register()` **nicht** aufgerufen (kein Subscribe ohne valid Connection). Architecture-Referenz: `architecture.md` Z. 51 + `discovery.md` Z. 649.

8) **GIVEN** der Listener fuer `spreadconnect/webhook_secret_rotated` (gefeuert von Slice 14 `WebhookSecretManager::regenerate()`) wird mit dem neuen Secret invoked
   **WHEN** der Listener `SubscriptionManager::resubscribeAll($newSecret)` aufruft
   **THEN** werden **alle** existierenden Subscriptions auf unsere URL (active + orphans) zuerst per `deleteSubscription` entfernt und anschliessend werden alle 7 expected Subscriptions per `createSubscription` mit dem `$newSecret` neu registriert. Reihenfolge ist Pflicht: DELETE-Phase muss vollstaendig abgeschlossen sein, bevor POST-Phase startet (Vermeidung doppelter Registrierungen mit Mix aus altem und neuem Secret).

9) **GIVEN** das Plugin wird aktiviert (`register_activation_hook`-Pfad bzw. `Bootstrap\Plugin::onActivate()`)
   **WHEN** der Manager seine Recurring-Action registriert
   **THEN** wird `as_next_scheduled_action('spreadconnect/auto_subscription_check')` geprueft; falls **nicht** geplant, wird `as_schedule_recurring_action(time(), WEEK_IN_SECONDS, 'spreadconnect/auto_subscription_check', [], 'spreadconnect')` aufgerufen — genau einmal, idempotent. Bei wiederholtem Plugin-Aktivieren wird die Action **nicht** doppelt geplant.

10) **GIVEN** der Action-Hook `spreadconnect/auto_subscription_check` feuert (per Action-Scheduler)
    **WHEN** der Handler `SubscriptionManager::driftCheck()` ausgefuehrt wird
    **THEN** ruft er `diff()` auf; bei `missing` oder `orphans` non-empty wird (a) `register()` aufgerufen (selbstheilend, idempotent), (b) ein Persistent-Admin-Notice via `Failure\AdminNoticeStore` (oder Stub bis Slice 39) mit Message `Subscriptions out of sync — auto-repaired (added: N, removed: M)` geschrieben. Bei vollstaendiger Drift-Free-Lage wird kein Notice geschrieben. Architecture-Referenz: `architecture.md` Z. 555 + Z. 703.

11) **GIVEN** ein User ohne `manage_woocommerce`-Capability triggert irgendeinen public-callable Pfad in dieser Slice (z. B. ein hypothetischer direkter Method-Call ueber AJAX, der hier nicht explizit registriert wird)
    **WHEN** der Pfad ausgefuehrt wird
    **THEN** existiert in dieser Slice **keine** AJAX-Action; alle Settings-Save-Hooks und Recurring-Actions laufen im Kontext von WP-Admin-Save bzw. Action-Scheduler — der Capability-Gate liegt im Settings-Save-Pfad (Slice 11 + WP-Settings-API). `SubscriptionManager`-Methoden werden **nicht** als public AJAX exponiert (Slice 19 liefert die `spreadconnect_repair_subscriptions`-Action separat).

12) **GIVEN** der `SubscriptionManager` schreibt einen Logger-Eintrag (Source `spreadconnect-api-client` oder spaeter `spreadconnect-subscription-service`)
    **WHEN** ein Subscribe/Delete-Outcome geloggt wird
    **THEN** enthaelt der Log-Message **nicht** den Plaintext-Secret-Wert; erlaubt sind: `'subscription_registered'`-Marker, `eventType`-String, `subscription.id`-Echo (das ist von SC vergeben, nicht das Secret). Bearer-Token + Secret sind via Slice-07/14-Redaction maskiert.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey-Mocks fuer `get_option`, `update_option`, `home_url`/`rest_url`, `do_action`, `add_action`, `as_schedule_recurring_action`, `as_next_scheduled_action`, `__`. `SpreadconnectClient` via Mockery-Stub oder Test-Subclass — `getSubscriptions(): array<Subscription>`, `createSubscription(SubscriptionCreate): Subscription`, `deleteSubscription(string $id): void`. `WebhookSecretManager::peek` via Patchwork-Replace oder Subclass-Override (Slice 14 hat `protected static function generateRandomBytes` als Test-Override-Point; analog hier `WebhookSecretManager::peek` darf gestubbt werden). KEIN realer HTTP-Roundtrip noetig.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-18-subscription-manager.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class SubscriptionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // AC-1: diff() klassifiziert active/missing/orphans korrekt (URL-Match-only fuer orphans)
    public function test_diff_classifies_subscriptions_into_three_disjoint_lists(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: veraltete URL gleicher Host -> orphan + missing parallel
    public function test_diff_treats_outdated_callback_url_as_orphan_plus_missing(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: register() bei leerer SC-Liste registriert alle 7 Events
    public function test_register_creates_seven_subscriptions_on_empty_remote_state(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: jeder createSubscription-Call enthaelt unsere callback URL + Secret
    public function test_register_passes_home_url_and_peek_secret_to_each_call(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: register() bei vollstaendiger Remote-Liste skippt alle (Idempotenz)
    public function test_register_is_idempotent_when_all_seven_already_active(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: removeOrphans loescht nur eigene veraltete URLs, niemals fremde
    public function test_remove_orphans_never_deletes_foreign_callback_urls(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: 4xx waehrend createSubscription wird in errors[] gesammelt, Loop laeuft weiter
    public function test_register_continues_on_client_error_and_collects_into_summary(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: 5xx/Transient wird re-thrown (Action-Scheduler-Retry-Pfad)
    public function test_register_rethrows_transient_error_for_action_scheduler_retry(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Settings-Save-Hook ruft authenticate -> generate -> register in dieser Reihenfolge
    public function test_settings_save_hook_orchestrates_authenticate_then_generate_then_register(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Settings-Save mit invalid Auth ueberspringt register-Aufruf
    public function test_settings_save_hook_skips_register_when_authenticate_fails(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Listener auf spreadconnect/webhook_secret_rotated triggert resubscribeAll mit DELETE-then-POST-Reihenfolge
    public function test_secret_rotation_listener_deletes_then_recreates_with_new_secret(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: as_schedule_recurring_action wird genau einmal geplant (Idempotenz bei Re-Activate)
    public function test_recurring_drift_check_scheduled_exactly_once(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: driftCheck() bei missing/orphans laeuft register + Admin-Notice
    public function test_drift_check_triggers_self_heal_and_writes_admin_notice(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-10: driftCheck() bei vollstaendiger Sync schreibt kein Notice
    public function test_drift_check_writes_no_notice_when_in_sync(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-12: Logger enthaelt nicht den Plaintext-Secret
    public function test_logger_does_not_emit_plaintext_secret(): void
    {
        $this->markTestIncomplete('AC-12');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-10-endpoint-methods` | `SpreadconnectClient::getSubscriptions(): array<Subscription>`, `createSubscription(SubscriptionCreate): Subscription`, `deleteSubscription(string $id): void` | public methods | Slice-10-AC dokumentiert Signaturen + Exception-Pass-Through. Diese Slice ruft sie ueber den persistierten `spreadconnect_api_key` (kein POST-Body-Override). |
| `slice-09-dto-value-objects` | `Api\Dto\Subscription`, `Api\Dto\SubscriptionCreate` | DTO | `Subscription.eventType`, `Subscription.callbackUrl`, `Subscription.id` werden ausgelesen; `SubscriptionCreate{eventType, callbackUrl, secret}` wird konstruiert. |
| `slice-12-test-connection-ajax` | `SpreadconnectClient::authenticate(): AuthOk` | public method | Settings-Save-Hook (AC-7) ruft `authenticate()` als Connection-Verify; **kein** AJAX-Re-Use — Slice 12 liefert die UI-Action, Slice 18 ruft die Client-Methode direkt im Settings-Save-Pfad. |
| `slice-14-webhook-secret-manager` | `Subscription\WebhookSecretManager::peek(): string`, `WebhookSecretManager::generate(): array` | static methods | `peek()` liefert das aktuelle Secret fuer `register()`; `generate()` wird im Settings-Save-Pfad aufgerufen wenn Secret leer. |
| `slice-14-webhook-secret-manager` | Action-Hook `spreadconnect/webhook_secret_rotated` | WP action | Diese Slice registriert den Listener (AC-8) via `add_action('spreadconnect/webhook_secret_rotated', [SubscriptionManager::class, 'resubscribeAll'])`. |
| `slice-11-settings-form` | `Settings\SettingsValidator::sanitize()` als Persistierungs-Pfad | static method | Diese Slice editiert die Datei zur Insertion eines Post-Save-Hook-Wirings (oder registriert separat `add_action('updated_option_spreadconnect_api_key', ...)`); Implementer waehlt die idiomatische WP-Variante. Sanitize-Logik aus Slice 11 bleibt unveraendert. |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::onActivate()` (Activate-Hook-Slot) | method | AC-9 plant Recurring-Action im Activate-Hook — diese Slice editiert `Bootstrap\Plugin` zur Insertion des Schedule-Calls. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Subscription\SubscriptionManager::diff` | static method | Slice 19 (Subscriptions-UI Repair-Logic), Slice 26 (Hub-Dashboard Card 4 "Subscriptions: X/7") | `public static function diff(): array{active: array<string>, missing: array<string>, orphans: array<array{id:string, eventType:string, callbackUrl:string}>}` |
| `SpreadconnectPod\Subscription\SubscriptionManager::register` | static method | Slice 19 Repair-AJAX, Settings-Save-Hook (this slice), Drift-Check-Handler | `public static function register(): array{added:int, removed:int, skipped:int, errors: array<array{eventType:string, message:string}>}` |
| `SpreadconnectPod\Subscription\SubscriptionManager::removeOrphans` | static method | Slice 19 Repair-AJAX | `public static function removeOrphans(): int` (returns count) |
| `SpreadconnectPod\Subscription\SubscriptionManager::resubscribeAll` | static method | Slice 14 Listener (this slice registers the listener) | `public static function resubscribeAll(string $newSecret, array $context = []): array` (Summary wie `register()`) |
| `SpreadconnectPod\Subscription\SubscriptionManager::driftCheck` | static method | Action-Hook `spreadconnect/auto_subscription_check` | `public static function driftCheck(): void` (terminiert silent; schreibt Admin-Notice falls Drift) |
| Action-Hook `spreadconnect/auto_subscription_check` | WP/Action-Scheduler hook | Recurring AS schedule (registered in this slice) | weekly recurring — Hook-Body = `SubscriptionManager::driftCheck` |
| Constant `SubscriptionManager::EXPECTED_EVENTS` | `final const array` | Slice 19 (UI listet 7 Events), Slice 26 (Dashboard-Card-Aggregat) | `final public const EXPECTED_EVENTS = ['Article.added', 'Article.updated', 'Article.removed', 'Order.processed', 'Order.cancelled', 'Order.needs-action', 'Shipment.sent']` |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Subscription/SubscriptionManager.php` — Klasse `SpreadconnectPod\Subscription\SubscriptionManager` mit `diff()`, `register()`, `removeOrphans()`, `resubscribeAll(string $newSecret, array $context = [])`, `driftCheck()`, `scheduleRecurringDriftCheck()`, `bootListeners()` (registriert `add_action('spreadconnect/webhook_secret_rotated', ...)` + `add_action('spreadconnect/auto_subscription_check', ...)` + `add_action('updated_option_spreadconnect_api_key', ...)`). Constant `EXPECTED_EVENTS` als `final public const` mit den 7 Strings (siehe Constraints "Expected Events"). URL-Helper `currentCallbackUrl(): string` -> `home_url('wp-json/spreadconnect/v1/webhook')` (oder `rest_url('spreadconnect/v1/webhook')`).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Settings/SettingsValidator.php` (Slice 11) — Insertion eines Post-Save-Side-Effects: nach erfolgreicher Persistenz (am Ende von `sanitize()` oder via `register_setting`-`sanitize_callback`-Return-Path bzw. via `add_action('updated_option_spreadconnect_api_key', ...)`-Subscriber, Implementer-Wahl). Side-Effect ruft: (a) `SpreadconnectClient::authenticate()` Connection-Verify, (b) bei leerem Secret: `WebhookSecretManager::generate()`, (c) `SubscriptionManager::register()`. **Keine Aenderung an der bestehenden Sanitize-Logik (Auto-Confirm-Gating, Field-Validation).** Der Side-Effect ist nicht-blocking (Errors werden in Admin-Notice gesammelt, nicht als Form-Validation-Failure).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02) — Mount-Points: (a) im `init`/`plugins_loaded`-Hook: `SubscriptionManager::bootListeners()` aufrufen (registriert die `add_action`-Listener fuer `spreadconnect/webhook_secret_rotated`, `spreadconnect/auto_subscription_check`, `updated_option_spreadconnect_api_key`); (b) im Activate-Hook: `SubscriptionManager::scheduleRecurringDriftCheck()` aufrufen. Bestehende Bootstrap-Logik bleibt unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-18-subscription-manager.php` wird vom Test-Writer-Agent erstellt. Keine separate Subscriptions-UI-Datei in dieser Slice — Slice 19 liefert `Hub\View\Subscriptions` + Repair-AJAX.

---

## Constraints

**Scope-Grenzen:**
- KEINE Subscriptions-Manager-UI — Slice 19 liefert `Hub\View\Subscriptions` + `spreadconnect_repair_subscriptions`-AJAX.
- KEINE Hub-Dashboard-Card-Integration — Slice 13/26 liest `SubscriptionManager::diff()->active` fuer Card 4.
- KEINE Webhook-Verify-Logik — Slice 15 liefert `WebhookSignatureVerifier`.
- KEINE eigene `wp_options`-Persistenz fuer Subscription-State — Single Source of Truth ist `getSubscriptions()` (live aus SC-API). Optionaler Read-Through-Cache (transient `sc_subscriptions_state`, kurze TTL z. B. 60s) ist Implementer-Optimierung, nicht Pflicht; Slice 19 / Slice 26 darf bei Bedarf cachen.
- KEINE WP-CLI-Variante — Slice 46 (Polish) optional `wp spreadconnect repair-subs`.
- KEINE FailedOpsRepo-Integration fuer Subscription-Errors — `errors[]`-Sammlung bleibt im Summary-Return; Slice 39 (FailureNotifier) entscheidet spaeter ueber persistente Notice/Email-Pfad. Bis dahin: Admin-Notice via `Failure\AdminNoticeStore`-Stub bzw. `update_option('spreadconnect_admin_notices', ...)` Inline-Stub.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in der neuen Datei.
- `SubscriptionManager` als `final class` mit ausschliesslich `static` Methoden — Stateless-Service-Pattern (konsistent mit `WebhookSecretManager`, `TestConnection`).
- **Diff-Algorithmus**: O(n)-Iteration ueber `getSubscriptions()`-Response; `EXPECTED_EVENTS`-Hashset-Membership-Check; URL-Match per `===`-strict-Compare gegen `currentCallbackUrl()` (kein Substring, kein Host-only-Compare; AC-2 Pflicht).
- **Idempotenz**: `register()` darf **nie** ein Event mehrfach registrieren; pre-flight `diff()` ist Pflicht.
- **Reihenfolge in `resubscribeAll`** (AC-8): erst alle `deleteSubscription` (sequentiell oder pseudo-parallel; Implementer-Wahl), erst nach Abschluss der Delete-Phase die `createSubscription`-Phase. KEIN Mix.
- **Retry-Verhalten**: `SpreadconnectClientError` -> sammeln in `errors[]` (kein Re-throw); `SpreadconnectTransientError` -> re-thrown (Action-Scheduler-Retry-Pfad). KEIN inner Retry-Loop in dieser Slice.
- **Recurring-Schedule**: `as_next_scheduled_action(...)`-Pre-Check ist Pflicht (AC-9 Idempotenz). `WEEK_IN_SECONDS` als WP-Konstante (kein Magic-Number).
- **Group-Slug**: `'spreadconnect'` (architecture.md Z. 558 — alle Plugin-AS-Actions teilen diesen Group fuer Tools->Scheduled-Actions-Sichtbarkeit).
- **Hook-Registration-Strategie**: `bootListeners()` wird **einmalig** in `Bootstrap\Plugin::init()` aufgerufen; nicht im Class-Body, nicht im Constructor (final class hat keinen Constructor-Call-Path).
- **Settings-Save-Side-Effect**: Implementer waehlt zwischen (a) Inline-Aufruf am Ende von `SettingsValidator::sanitize()` (nach Persistenz, vor Return), (b) WP-Hook `add_action('updated_option_spreadconnect_api_key', ...)`. Variante (b) ist idiomatisch und entkoppelt — bevorzugt. Variante (a) erfordert direkten Method-Call. Beide Varianten muessen die gleichen drei Schritte ausfuehren (authenticate -> generate-if-empty -> register).
- **Connection-Verify im Settings-Save-Pfad**: `authenticate()` darf hier **synchron** aufgerufen werden (kein Action-Scheduler), weil der User auf der Settings-Page wartet. Bei Failure: kein `register()`-Call, Notice wird gesetzt, Save bleibt erfolgreich (Settings-Persistenz hat bereits stattgefunden).
- **URL-Helper-Konsistenz**: `currentCallbackUrl()` muss **immer** dieselbe URL zurueckliefern wie der Webhook-Receiver-Route-Path in Slice 15 (`spreadconnect/v1/webhook` Namespace). Helper als Single-Source-of-Truth.

**Expected Events (Single Source of Truth):**

| # | eventType | Source / Reason |
|---|-----------|-----------------|
| 1 | `Article.added` | architecture.md Z. 41; Catalog-Webhook-Pfad (Slice 25) |
| 2 | `Article.updated` | architecture.md Z. 41; Catalog-Webhook + Stock-Refresh-Trigger (Slice 25, 36) |
| 3 | `Article.removed` | architecture.md Z. 41; ArticleRemovedJob -> WC-Status `draft` (Slice 25) |
| 4 | `Order.processed` | architecture.md Z. 41; OrderEventHandler -> State `PROCESSED` (Slice 30) |
| 5 | `Order.cancelled` | architecture.md Z. 41; OrderEventHandler -> State `CANCELLED` (Slice 30) |
| 6 | `Order.needs-action` | architecture.md Z. 41; OrderEventHandler -> Admin-Notice (Slice 30) |
| 7 | `Shipment.sent` | architecture.md Z. 41; OrderEventHandler -> FetchTrackingJob (Slice 30) |

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | **Import** — `getSubscriptions`, `createSubscription`, `deleteSubscription`, `authenticate` werden aufgerufen. NICHT modifizieren. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/Subscription.php` (Slice 09) | **Import** — Response-Mapping fuer `diff()`. NICHT modifizieren. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/SubscriptionCreate.php` (Slice 09) | **Import** — Request-DTO fuer `createSubscription()`. NICHT modifizieren. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` (Slice 07) | **Import** — Catch-Klausel im register-Loop (sammelt in errors[]). |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectTransientError.php` (Slice 07/08) | **Import** — re-thrown im register-Loop (AS-Retry-Pfad). |
| `wordpress/plugins/spreadconnect-pod/includes/Subscription/WebhookSecretManager.php` (Slice 14) | **Import** — `peek()` fuer Secret-Read; `generate()` fuer Initial-Setup im Settings-Save-Pfad. NICHT modifizieren. |
| `wordpress/plugins/spreadconnect-pod/includes/Settings/SettingsValidator.php` (Slice 11) | **Edit** — Insertion eines Post-Save-Side-Effects (Implementer-Wahl: inline am Ende von `sanitize()` oder via separat registrierter `add_action('updated_option_spreadconnect_api_key', ...)`-Subscriber im selben File). Bestehende Sanitize-Logik bleibt unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02) | **Edit** — Mount-Points: (a) `bootListeners()` im `init`-Hook, (b) `scheduleRecurringDriftCheck()` im Activate-Hook. Bestehende Bootstrap-Logik bleibt unveraendert. |
| Action-Hook `spreadconnect/webhook_secret_rotated` (Slice 14) | **Subscribe** via `add_action` in `bootListeners()` -> `resubscribeAll`. |

**Referenzen:**
- Architecture: `architecture.md` -> Service Map `Subscription\SubscriptionManager` (Z. 383); Endpoint-Tabelle Z. 106-108 (GET/POST/DELETE /subscriptions); `SubscriptionCreate{eventType, callbackUrl, secret}`-Body Z. 107; "Never deletes foreign URLs" Z. 108; 7 Expected Events Z. 41 + Z. 175; Auto-Setup-Trigger Z. 51; AS-Hook `spreadconnect/auto_subscription_check` Z. 555; `subscriptions.drift` Gauge Z. 703; Group-Slug Z. 558; Risk-Mitigation Z. 739 (`SubscriptionManager::repair()` is idempotent).
- Discovery: `discovery.md` -> Flow A.5 (Auto-Register on Settings-Save) Z. 130; Flow H (Subscription-Repair) Z. 204-211; Initial-Setup Z. 649 (`Bei API-Key-Save mit gueltiger Connection: Auto-Subscriptions registrieren`); Webhook-Subscriptions Auto-Activation Z. 1031.
- Slim-Slices: `slices/slim-slices.md` -> Slice-18-Eintrag Z. 339-346 (Done-Signal: 7 POST /subscriptions mit unserer URL+Secret; existing wird skipped).
- Vorgaenger Slice 12: `slices/slice-12-test-connection-ajax.md` -> Provides `SpreadconnectClient::authenticate()` Path; Slice 18 nutzt persistierten Key (kein Override) im Settings-Save-Hook.
- Vorgaenger Slice 14: `slices/slice-14-webhook-secret-manager.md` -> Provides `WebhookSecretManager::peek/generate`; Action-Hook `spreadconnect/webhook_secret_rotated` (AC-3 dort, AC-8 hier).
- Folge-Slice 15: `slices/slim-slices.md` Slice-15 -> WebhookController-Route `/wp-json/spreadconnect/v1/webhook` muss mit `currentCallbackUrl()` exakt uebereinstimmen.
- Folge-Slice 19: `slices/slim-slices.md` Slice-19 -> Repair-UI konsumiert `diff()`/`register()`/`removeOrphans()`.
- Folge-Slice 25/30: `slices/slim-slices.md` Slice-25/30 -> ArticleEventHandler / OrderEventHandler verarbeiten die 7 registrierten Events.
