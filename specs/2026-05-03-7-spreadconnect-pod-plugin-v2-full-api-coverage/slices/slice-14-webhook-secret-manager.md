# Slice 14: Webhook-Secret-Manager + One-Time-Reveal-Panel

> **Slice 14 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-14-webhook-secret-manager` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-13-hub-page-skeleton"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `get_option`/`update_option`/`delete_option`/`add_option`/`current_user_can`/`check_ajax_referer`/`wp_send_json_*`/`do_action`/`__()`/`esc_html__`/`esc_attr`/`esc_html`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: Settings -> "Regenerate Secret" -> Reveal-Panel zeigt Klartext genau einmal; nach `[Done]` -> Maskierung; Reload -> kein Klartext mehr) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer WP Options/AJAX-API; Patchwork-Replace fuer `random_bytes` via Wrapper-Method, um deterministische Bytes in Tests einzuspielen; in-memory Options-Map fuer Persistenz-Verifikation) |

---

## Ziel

Liefert den HMAC-Secret-Lifecycle: `Subscription\WebhookSecretManager` generiert kryptographisch starke Secrets (`random_bytes(32)` -> base64), persistiert in Option `spreadconnect_webhook_secret`, und gibt den Plaintext **nur** als One-Time-Reveal-Payload zurueck. Die AJAX-Action `spreadconnect_regenerate_secret` triggert Generation + feuert einen Re-Subscribe-Hook (von Slice 18 konsumiert). Settings-Page bekommt zwei UI-Bausteine: Regenerate-Button in Section ③ (Webhook Security) und das nested `initial_secret_reveal_panel` im `save_success_panel` beim allerersten Settings-Save.

---

## Acceptance Criteria

1) **GIVEN** Option `spreadconnect_webhook_secret` ist leer (`""`)
   **WHEN** `WebhookSecretManager::generate()` wird aufgerufen
   **THEN** wird `random_bytes(32)` ausgefuehrt, das Ergebnis base64-encodiert (Laenge ≈ 44 Zeichen, kein Trailing-Whitespace), via `update_option('spreadconnect_webhook_secret', $secret)` persistiert, und ein Reveal-Payload `['secret' => string, 'generated_at' => int (unix-timestamp), 'is_initial' => true]` zurueckgegeben. Die Persistenz-Form ist ASCII-base64 (kein hex, kein binary).

2) **GIVEN** Option `spreadconnect_webhook_secret` enthaelt bereits einen Wert `$old`
   **WHEN** `WebhookSecretManager::regenerate()` wird aufgerufen
   **THEN** wird ein neues Secret `$new !== $old` generiert (verschiedene `random_bytes`-Aufrufe), via `update_option(...)` persistiert (alter Wert ueberschrieben), und das Reveal-Payload mit `'is_initial' => false` zurueckgegeben. Der alte Wert ist nach dem Call nicht mehr aus der Option lesbar.

3) **GIVEN** `WebhookSecretManager::regenerate()` wurde erfolgreich ausgefuehrt
   **WHEN** der Manager den Persistenz-Schritt abgeschlossen hat
   **THEN** wird genau einmal `do_action('spreadconnect/webhook_secret_rotated', $newSecret)` gefeuert — der Event-Name ist Single-Source-of-Truth fuer den Slice-18-Subscribe-Listener (siehe Provides-To). Bei `generate()` (Initial-Setup) wird derselbe Action mit `'is_initial' => true`-Context gefeuert: `do_action('spreadconnect/webhook_secret_rotated', $newSecret, ['is_initial' => true])`.

4) **GIVEN** `WebhookSecretManager::peek()` (Read-Only-Helper)
   **WHEN** der Caller den **persistierten** Secret-Wert lesen will (z. B. fuer HMAC-Verify in Slice 15)
   **THEN** liefert `peek()` den Plaintext aus `get_option('spreadconnect_webhook_secret', '')`. **Keine** UI-Komponente, **kein** Logger, **kein** AJAX-Response darf `peek()` aufrufen — nur HMAC-Verifier (Slice 15) und die optionale Settings-Export-Filter-Liste (Slice 45). Architecture-Referenz: `architecture.md` -> Data Protection (Z. 492).

5) **GIVEN** AJAX-Action `spreadconnect_regenerate_secret` wird ohne `manage_woocommerce`-Capability oder ohne gueltige Nonce aufgerufen
   **WHEN** `RegenerateSecret::handle()` laeuft
   **THEN** terminiert der Handler via `wp_send_json_error([...], 403)` (Capability-Miss) bzw. `check_ajax_referer(..., false)` -> 403. Es wird **kein** `WebhookSecretManager::regenerate()` aufgerufen. Capability-Helper ist `Hub\Controller::ensureCapability` (Slice-13-Provides).

6) **GIVEN** AJAX-Action `spreadconnect_regenerate_secret` wird mit gueltiger Capability + Nonce aufgerufen
   **WHEN** `RegenerateSecret::handle()` laeuft
   **THEN** ruft der Handler `WebhookSecretManager::regenerate()` und antwortet mit `wp_send_json_success(['secret' => $plaintext, 'generated_at' => $unix, 'is_initial' => false])`. Der Plaintext erscheint **nur** in dieser Response — nicht im Log (siehe AC-9), nicht in einer zweiten Lesebewegung.

7) **GIVEN** das Settings-Page-Markup wird gerendert (Slice 11 `Hub\View\Settings::render()`, durch diese Slice editiert)
   **WHEN** Section ③ "Webhook Security" gerendert wird
   **THEN** enthaelt das Markup: (a) den Regenerate-Button mit Wireframe-Annotation `④ regenerate_secret_button` (siehe wireframes.md Screen 7 Z. 624), (b) die Maskierung `••••••••••••••••••••` (statisch — der Plaintext wird **nie** ins Server-Markup ausgegeben), (c) den Last-Regenerated-Timestamp aus Option `spreadconnect_webhook_secret_generated_at` als formatiertes Datum, (d) den Inline-Hint aus Wireframe-Annotation ⑤. Alle Strings sind via `__()`/`esc_html__()` mit Domain `spreadconnect-pod` lokalisiert, alle Outputs escaped (`esc_html`/`esc_attr`).

8) **GIVEN** der erste erfolgreiche Settings-Save ist gerade abgeschlossen UND Option `spreadconnect_webhook_secret_revealed_at` ist leer
   **WHEN** `Hub\View\Settings::render()` das `save_success_panel` (Wireframe Screen 7 Z. 650-672) emittiert
   **THEN** wird das nested `initial_secret_reveal_panel` als Sub-Element inline gerendert mit dem **frisch generierten** Plaintext-Secret (transient via `set_transient('spreadconnect_initial_secret_reveal', $secret, 5 * MINUTE_IN_SECONDS)` aus dem Save-Hook), Wireframe-konform mit Monospace-Block + `[⎘ Copy]` + `[Done]`-Acknowledgement. Nach Klick auf `[Done]` schreibt eine zugehoerige AJAX-Sub-Action (Teil dieser Slice) `update_option('spreadconnect_webhook_secret_revealed_at', time())` und `delete_transient('spreadconnect_initial_secret_reveal')`. Subsequent Page-Loads zeigen das Panel **nicht** mehr — Plaintext ist permanent UI-locked.

9) **GIVEN** ein Logger-Aufruf irgendwo im Manager-Pfad
   **WHEN** `WebhookSecretManager::generate()` oder `regenerate()` einen Log-Eintrag schreibt
   **THEN** enthaelt der Log-Message NICHT den Plaintext-Secret. Erlaubte Inhalte: `'secret_generated'`/`'secret_rotated'`-Event-Marker, Length-Hint (`'len' => 44`), `is_initial`-Flag. Der vollstaendige base64-String ist redacted analog zu Bearer-Token-Redaction in `WcLoggerAdapter` (architecture.md Z. 494).

10) **GIVEN** `WebhookSecretManager::generate()` und `WebhookSecretManager::regenerate()` werden in unterschiedlichen Test-Cases mit derselben gemockten `random_bytes`-Source aufgerufen
    **WHEN** der Test denselben 32-Byte-Input zweimal einspielt
    **THEN** ist die base64-Encodierung deterministisch gleich (Konsistenz-Check); aber `regenerate()` darf das Test-Skeleton mit unterschiedlichen Mock-Bytes pro Call sehen und MUSS dann unterschiedliche Outputs liefern. Damit ist nachgewiesen, dass keine Caching-Schicht den Output stabilisiert.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey fuer `get_option`/`update_option`/`add_option`/`delete_option`/`set_transient`/`get_transient`/`delete_transient`/`do_action`/`time`/`current_user_can`/`check_ajax_referer`/`wp_send_json_success`/`wp_send_json_error`/`__`/`esc_html`/`esc_attr`/`esc_html__`. Die `random_bytes`-Source MUSS hinter einer Manager-Method `protected function generateRandomBytes(int $len): string` versteckt sein, damit der Test sie via Test-Subclass oder Patchwork ueberschreiben kann. In-Memory-Options-Map fuer Persistenz-Verifikation. Kein Test fuer eine echte HTTP-Subscribe-Aktion — der `do_action`-Hook wird nur auf "wurde gefeuert"-Ebene gepruefft (Slice 18 testet den Listener-Effekt).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-14-webhook-secret-manager.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class WebhookSecretManagerTest extends TestCase
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

    // AC-1: Initiale Generation schreibt Option + gibt Reveal-Payload zurueck
    public function test_generate_writes_option_and_returns_initial_reveal(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Secret ist base64-encoded mit Laenge ~44
    public function test_generate_produces_base64_44_chars(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Regenerate ueberschreibt alten Wert und liefert anderen Plaintext
    public function test_regenerate_replaces_previous_secret(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: regenerate() feuert do_action('spreadconnect/webhook_secret_rotated')
    public function test_regenerate_fires_rotation_action_with_new_secret(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: generate() (Initial) feuert dieselbe Action mit is_initial=true
    public function test_initial_generate_fires_rotation_action_with_is_initial_flag(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: peek() liefert persistierten Wert ohne Side-Effects
    public function test_peek_returns_persisted_secret_without_mutation(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: AJAX ohne Capability -> 403, kein regenerate-Call
    public function test_regenerate_secret_ajax_rejects_without_capability(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: AJAX ohne gueltige Nonce -> 403, kein regenerate-Call
    public function test_regenerate_secret_ajax_rejects_invalid_nonce(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: AJAX mit Cap+Nonce -> regenerate aufgerufen + Plaintext in Response
    public function test_regenerate_secret_ajax_returns_plaintext_on_success(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Settings-Markup enthaelt Regenerate-Button + Maskierung + Last-Regenerated-Timestamp
    public function test_settings_section_renders_regenerate_button_and_mask(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Plaintext wird NIE ins Server-Markup ausgegeben
    public function test_settings_markup_never_contains_plaintext_secret(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: initial_secret_reveal_panel sichtbar bei erstem Save (revealed_at leer)
    public function test_initial_reveal_panel_visible_when_revealed_at_empty(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Done-AJAX schreibt revealed_at + loescht Transient
    public function test_acknowledge_initial_reveal_locks_panel_permanently(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Subsequent Page-Load (revealed_at gesetzt) zeigt Panel nicht mehr
    public function test_initial_reveal_panel_hidden_after_acknowledgement(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Logger-Aufruf enthaelt nicht den Plaintext-Secret
    public function test_logger_does_not_emit_plaintext_secret(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Verschiedene random_bytes-Inputs ergeben verschiedene Outputs (kein Caching)
    public function test_regenerate_is_not_cached_between_calls(): void
    {
        $this->markTestIncomplete('AC-10');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-05-options-defaults` | Option `spreadconnect_webhook_secret` ist registriert (Default `""`) | WP Option | `WebhookSecretManager` schreibt via `update_option` — kein `register_setting`-Re-Register noetig. Auch `spreadconnect_webhook_secret_generated_at` (int, default `0`) und `spreadconnect_webhook_secret_revealed_at` (int, default `0`) muessen in Slice 05 als Defaults vorhanden sein — falls nicht, ergaenzt diese Slice via Activate-Hook-Edit (siehe Constraints "Option-Defaults"). |
| `slice-11-settings-form` | `Hub\View\Settings::render()` rendert Section ③ Webhook Security als Markup-Slot | static method | Diese Slice editiert die Datei zur Insertion des Reveal-Panel + Regenerate-Button-Markups. Bestehende Form-Felder bleiben unveraendert. |
| `slice-13-hub-page-skeleton` | `Hub\Controller::ensureCapability()` als geteilter Capability-Helper | static method | AJAX-Handler nutzt diesen Helper als erste Zeile — keine eigene `current_user_can`-Logik. |
| `slice-06-i18n-textdomain` | Text-Domain `spreadconnect-pod` ist auf `plugins_loaded` geladen | WP i18n | Reveal-Panel-Strings werden lokalisiert (`__()`/`esc_html__()`). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Subscription\WebhookSecretManager::generate` | static method | Slice 18 (`SubscriptionManager`) bei Initial-Setup nach Settings-Save mit valider Connection | `public static function generate(): array` (Reveal-Payload `['secret', 'generated_at', 'is_initial']`) |
| `SpreadconnectPod\Subscription\WebhookSecretManager::regenerate` | static method | Slice 14 AJAX-Handler + Slice 18 (Settings-Save mit existing Secret) | `public static function regenerate(): array` (Reveal-Payload, `is_initial=false`) |
| `SpreadconnectPod\Subscription\WebhookSecretManager::peek` | static method | Slice 15 `Webhook\WebhookSignatureVerifier` (HMAC-Compare); Slice 45 Settings-Export-Filter (Exclusion-Liste) | `public static function peek(): string` (leerer String wenn nie generiert) |
| Action-Hook `spreadconnect/webhook_secret_rotated` | WP action | Slice 18 (`SubscriptionManager::resubscribeAll`) | `do_action('spreadconnect/webhook_secret_rotated', string $newSecret, array $context = [])` — Listener wird in Slice 18 registriert. |
| AJAX-Action `spreadconnect_regenerate_secret` | WP AJAX | Settings-Page Regenerate-Button | `wp_ajax_spreadconnect_regenerate_secret` -> `RegenerateSecret::handle` (registriert via `add_action` in Slice-13 Bootstrap-Wiring oder direkt in dieser Slice's RegenerateSecret-Klasse) |
| AJAX-Action `spreadconnect_acknowledge_initial_reveal` | WP AJAX | Settings-Page `[Done]`-Button im `initial_secret_reveal_panel` | Schreibt `update_option('spreadconnect_webhook_secret_revealed_at', time())` + `delete_transient(...)`; Capability-Gate. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Subscription/WebhookSecretManager.php` — Klasse `SpreadconnectPod\Subscription\WebhookSecretManager` mit `generate(): array`, `regenerate(): array`, `peek(): string`, `protected static function generateRandomBytes(int $len): string` (Test-Override-Point). Persistiert Option `spreadconnect_webhook_secret` + Companion-Options `spreadconnect_webhook_secret_generated_at` + `spreadconnect_webhook_secret_revealed_at`. Feuert `do_action('spreadconnect/webhook_secret_rotated', ...)`.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/RegenerateSecret.php` — Klasse `SpreadconnectPod\Hub\Ajax\RegenerateSecret` mit `register(): void` (Hooks `wp_ajax_spreadconnect_regenerate_secret` + `wp_ajax_spreadconnect_acknowledge_initial_reveal`) und zwei Handler-Methoden. Capability- + Nonce-Gate via `Hub\Controller::ensureCapability` + `check_ajax_referer`.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` (Slice 11) — Insertion in Section ③ "Webhook Security": Regenerate-Button-Markup (mit Bestaetigungs-Dialog-Trigger via `data-`-Attribute, JS in spaeterer Slice — diese Slice liefert nur HTML-Hooks), Maskierungs-Display, Last-Regenerated-Timestamp. Insertion in `save_success_panel`-Block: nested `initial_secret_reveal_panel` mit Plaintext aus Transient + `[Copy]` + `[Done]`-Buttons. **Bestehende Form-Felder bleiben unveraendert.**
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Mount-Point: `RegenerateSecret::register()` auf `init`- oder `admin_init`-Hook registrieren, sonst sind die beiden AJAX-Actions nicht verdrahtet.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-14-webhook-secret-manager.php` wird vom Test-Writer-Agent erstellt. Keine JS-Datei in dieser Slice — der Confirm-Dialog vor Regenerate (Wireframe State `regenerate_dialog_open`) und das Copy-to-Clipboard-Verhalten sind Markup-Hooks (`data-`-Attribute), das JS liefert eine spaetere Slice (oder ist Teil eines globalen Settings-JS, der hier nicht in Scope ist — Settings-Save-Hook reicht fuer den Server-seitigen Lifecycle).

---

## Constraints

**Scope-Grenzen:**
- **Kein** tatsaechlicher Re-Subscribe-API-Call — Slice 14 feuert nur den Action-Hook `spreadconnect/webhook_secret_rotated`. Slice 18 implementiert den Listener mit `POST/DELETE /subscriptions`-Bulk.
- **Kein** Subscription-Status-UI — Slice 19 liefert die Subscriptions-Page mit Repair-Button.
- **Kein** HMAC-Verify-Code — Slice 15 (`Webhook\WebhookSignatureVerifier`) konsumiert `WebhookSecretManager::peek()`.
- **Kein** Dev-Tools-Simulate-Section — Slice 44 liefert die Staging-only Buttons im Settings-Footer.
- **Kein** generischer "Regenerate"-Trigger ueber WP-CLI — kommt optional in Slice 46 oder als spaetere Erweiterung.
- **Kein** Save-Success-Panel-Framework — diese Slice nutzt das `save_success_panel` aus Slice 11 (sofern dort gerendert) oder fuegt einen minimalen Wrapper hinzu, ist aber nicht fuer das vollstaendige Stepwise-Result-Panel-Framework verantwortlich (das wird voll funktional erst, wenn Slice 18 die Subscription-Steps liefert).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in allen 2 neuen Dateien.
- `WebhookSecretManager` als `final class` mit ausschliesslich `static` Methoden — Stateless-Service.
- `RegenerateSecret` als `final class` mit `static` Methoden (Pattern-Konsistenz mit Slice 12 `TestConnection`).
- **`random_bytes`**: KEIN direkter `random_bytes(32)`-Call im Class-Body. Stattdessen `protected static function generateRandomBytes(int $len): string { return random_bytes($len); }` — diese Methode ist der **einzige** Punkt, an dem die kryptographische Quelle aufgerufen wird, damit Tests via Subclass oder Patchwork-Replace deterministische Bytes einspielen koennen.
- **Encoding**: `base64_encode()` ohne `urlsafe`-Variante — der gespeicherte Wert ist 1:1 das, was in HMAC-Verify (Slice 15) als Compare-Reference dient.
- **Persistenz-Atomicity**: `update_option(...)` -> dann `update_option('..._generated_at', time())` -> dann `do_action(...)`. Reihenfolge ist Pflicht: Action-Hook feuert NUR, wenn Persistenz erfolgreich.
- **Transient fuer Initial-Reveal**: `set_transient('spreadconnect_initial_secret_reveal', $secret, 5 * MINUTE_IN_SECONDS)` — kurze Lifetime, weil Plaintext in Memory gehalten wird; nach Acknowledge sofort `delete_transient(...)`.
- **`spreadconnect_webhook_secret_revealed_at` als One-Way-Flag**: einmal gesetzt (timestamp != 0), niemals wieder gelesen oder geloescht. Bei `regenerate()` wird die Option **nicht** zurueckgesetzt — der Subsequent-Reveal nutzt den `regenerate_success`-Inline-Panel (Wireframe Z. 641), nicht das Initial-Panel.
- **Keine Plaintext-Logs**: Logger-Wrapper (siehe AC-9) muss den base64-String redacten — analog zu Bearer-Token in `WcLoggerAdapter` (architecture.md Z. 494). In dieser Slice optional ein simpler `error_log`-Stub, der den Plaintext nicht enthaelt; volle WC_Logger-Integration kommt in Slice 42.
- **Capability + Nonce**: jede AJAX-Action ruft als allerersten Schritt `Hub\Controller::ensureCapability()` und `check_ajax_referer('spreadconnect_secret_action', 'nonce')`. Nonce-Action-Name `'spreadconnect_secret_action'` ist Single-Source-of-Truth fuer beide Sub-Actions.
- **Markup-Reuse**: das Reveal-Panel-Markup-Schema (Monospace-Block + Copy + Done) wird einmal in `Hub\View\Settings::render()` definiert und sowohl fuer `initial_secret_reveal` als auch fuer `regenerate_success` (spaetere Slice oder hier optional) wiederverwendbar gehalten. CSS-Klassen `spreadconnect-reveal-panel`, `spreadconnect-reveal-panel--initial`, `spreadconnect-reveal-panel--regenerate` als Hooks; keine inline-Styles.

**Option-Defaults (kritisch):**
- Slice 05 `OptionsDefaults` hat `spreadconnect_webhook_secret` (default `""`) bereits gelistet (architecture.md Z. 325). Falls die zwei Companion-Options `spreadconnect_webhook_secret_generated_at` (int, `0`) und `spreadconnect_webhook_secret_revealed_at` (int, `0`) noch nicht in Slice 05 enthalten sind, MUSS diese Slice sie ergaenzen — entweder via Edit in `OptionsDefaults` (zusaetzliches Deliverable) ODER via lazy `get_option('...', 0)`-Fallback. Bevorzugt: lazy-Fallback im Manager + Settings-View, kein zusaetzlicher Edit, um Slice 05's Scope nicht zu verschmutzen.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` (Slice 11) | **Edit** — Insertion in Section ③ Webhook Security (Markup-Slot ist im Slice-11-Render bereits als HTML-Block reserviert; diese Slice fuellt ihn) und in `save_success_panel` (Markup-Slot ebenfalls aus Slice 11). Bestehende Form-Felder + Sanitizer-Wiring bleiben unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02/13) | **Edit** — Mount-Point: `RegenerateSecret::register()` auf `init`-Hook (oder `admin_init`, da AJAX-Actions im Admin-Kontext laufen). Bestehende Hook-Registrierungen aus Slice 02-13 bleiben unveraendert. |
| `Hub\Controller::ensureCapability` (Slice 13) | **Import**, NICHT modifizieren — wird vom AJAX-Handler aufgerufen. |
| Architecture-Tabelle `architecture.md` -> Service Map "`Subscription\WebhookSecretManager`" (Z. 384) + AJAX `spreadconnect_regenerate_secret` (Z. 148) + Data Protection (Z. 492) | **Single Source of Truth** fuer Klassen-FQCN, AJAX-Verhalten, One-Time-Reveal-Pattern. |
| Wireframes `wireframes.md` -> Screen 7 ③ Webhook Security (Z. 573-580) + State `initial_secret_reveal` (Z. 642) + `save_success_panel` ASCII (Z. 650-672) | **Layout-Vorlage** fuer Reveal-Panel + Regenerate-Button. Diese Slice rendert die Markup-Hooks; CSS/JS-Polish kommt in Folge-Slices. |

**Referenzen:**
- Architecture: `architecture.md` -> Service Map `Subscription\WebhookSecretManager` (Z. 384); AJAX-Action `spreadconnect_regenerate_secret` (Z. 148); WP-Option `spreadconnect_webhook_secret` (Z. 325); Data Protection (Z. 492); Realistic Data Type Audit (Z. 272 — base64 ≈ 44 chars, LONGTEXT).
- Wireframes: `wireframes.md` -> Screen 7 Section ③ (Z. 573-580); Annotation ④ Regenerate-Button (Z. 624); State `initial_secret_reveal` (Z. 642); State `regenerate_success` (Z. 641); `save_success_panel` ASCII (Z. 650-672); Component-Inventory `initial_secret_reveal_panel` (Z. 35) + `regenerate_secret_button` (Z. 19).
- Discovery: `discovery.md` -> Slice 3 "Webhook Receiver + Subscriptions" (HMAC-Secret-Manager) — Z. 117 REUSE-Pattern HMAC-Secret-Manager; Z. 553 Webhook-Secret-Lifecycle; Z. 554 Regenerate-Konsequenz.
- Slim-Slices: `slices/slim-slices.md` -> Slice-14-Eintrag Z. 293-301 (Done-Signal: Erste Generation schreibt Option + Reveal; zweite invalidiert; Re-Subscribe-Hook).
- Vorgaenger: `slices/slice-13-hub-page-skeleton.md` -> Provides-To `Hub\Controller::ensureCapability` (AC-5/6); Section-Slug `settings`; Routing dispatched zu `Hub\View\Settings::render` (Slice 11).
- Vorgaenger: `slices/slice-11-settings-form.md` -> Section ③ Markup-Slot (Webhook Security, in Slice 11 als reservierter HTML-Block ohne Inhalt); `save_success_panel`-Wrapper.
- Folge: `slices/slim-slices.md` Slice-15 (HMAC-Verifier konsumiert `peek()`); Slice-18 (`SubscriptionManager::resubscribeAll` als Listener auf `spreadconnect/webhook_secret_rotated`).
