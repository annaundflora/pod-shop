# Slice 09: DTO Value Objects (Api\Dto\*) + Snake/Camel-Mapper

> **Slice 9 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-09-dto-value-objects` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-02-plugin-bootstrap"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 `final readonly` + PHPUnit 11 + Brain\Monkey 2.6) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (DTO-Factory-Calls + Mapper-Roundtrip ohne externe Dependencies) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `n/a` (Pure Value-Objects, kein Runtime-Boot noetig) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `no_mocks` (Pure-PHP Factories; kein WP/HTTP/DB-Aufruf in den DTOs) |

---

## Ziel

Erzeugt das vollstaendige DTO-Layer unter `SpreadconnectPod\Api\Dto\`: 13 `final readonly` Value-Objects mit Factory-Validation (`fromArray()`/`fromResponse()`) und einen Helper `DtoMapper` fuer snake_case <-> camelCase Konvertierung. Alle Folge-Slices (insbesondere Slice 10 Endpoint-Wrapper) typisieren ihre Request- und Response-Bodies ueber diese DTOs. Ungueltige API-Antworten und ungueltige WC-Order-Inputs werden bereits an der DTO-Grenze abgewiesen, bevor sie das Domain-Layer erreichen.

---

## Acceptance Criteria

> **Validation-Quelle:** Jedes AC referenziert die Spalte "Validation" der DTO-Tabelle in `architecture.md` -> Section "Data Transfer Objects (DTOs)" (ca. Zeilen 163-175). DTOs sind in der Tabelle in Section "Deliverables" gelistet und werden hier nicht erneut detailliert.

1) **GIVEN** alle 13 DTO-Klassen unter `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/`
   **WHEN** sie via Reflection inspiziert werden
   **THEN** ist jede Klasse als `final` und `readonly` deklariert (`ReflectionClass::isFinal() === true` und `ReflectionClass::isReadOnly() === true`), liegt im Namespace `SpreadconnectPod\Api\Dto`, und es existiert keine `set*`-Methode oder oeffentlicher Setter.

2) **GIVEN** ein gueltiges Eingabe-Array gemaess Validation-Regeln aus `architecture.md` (siehe Mapping unten in "DTO-Inventar")
   **WHEN** die Factory-Methode (`fromArray()` oder `fromResponse()`) aufgerufen wird
   **THEN** liefert sie eine Instanz der jeweiligen DTO-Klasse zurueck; alle Properties haben den erwarteten Typ und die uebergebenen Werte; ein wiederholter Aufruf mit identischem Input liefert eine equivalente, aber separate Instanz (Wert-Gleichheit, keine Singleton-Identitaet).

3) **GIVEN** ein Eingabe-Array, das eine Required-Field-Regel verletzt (siehe Verletzungs-Matrix in "Test Skeletons")
   **WHEN** die Factory-Methode aufgerufen wird
   **THEN** wirft sie eine `\InvalidArgumentException` mit einer Message, die das verletzende Feld benennt (z. B. enthaelt das Wort `sku`, `country`, `amount` o. ae., je nach DTO).

4) **GIVEN** `OrderCreate::fromArray()`
   **WHEN** das Eingabe-Array `orderItems` als leere Liste enthaelt
   **THEN** wirft die Factory `\InvalidArgumentException` (Architecture-Regel "`orderItems` >= 1"); bei `orderItems` mit mindestens einem Element wird die Instanz ohne Fehler konstruiert.

5) **GIVEN** `Address::fromArray()`
   **WHEN** das Feld `country` einen Wert enthaelt, der **nicht** ISO 3166-1 alpha-2 entspricht (Beispiele: `"DEU"`, `"de"`, `""`, `"D"`)
   **THEN** wirft die Factory `\InvalidArgumentException`; bei Werten wie `"DE"`, `"AT"`, `"FR"` wird die Instanz erfolgreich konstruiert. (Format: genau 2 ASCII-Grossbuchstaben.)

6) **GIVEN** `Money::fromArray()`
   **WHEN** das Feld `amount` nicht dem Format `^\d+\.\d{2}$` entspricht (Beispiele: `"19"`, `"19.5"`, `"19.999"`, `"-1.00"`, `1990` als int)
   **THEN** wirft die Factory `\InvalidArgumentException`; bei `"19.99"`/`"0.00"`/`"1234567.89"` wird die Instanz konstruiert. **AND** bei `currency` != 3 ASCII-Grossbuchstaben (z. B. `"eur"`, `"EU"`, `""`) wirft sie `\InvalidArgumentException`.

7) **GIVEN** `Preview::fromArray()`
   **WHEN** `imageUrl` nicht mit `https://` beginnt (z. B. `"http://..."`, `""`, `"ftp://..."`)
   **THEN** wirft die Factory `\InvalidArgumentException`; bei `"https://..."` wird die Instanz konstruiert.

8) **GIVEN** `WebhookEvent::fromArray()`
   **WHEN** `eventType` nicht in der zugelassenen Enum-Liste (`Article.added`, `Article.updated`, `Article.removed`, `Order.cancelled`, `Order.processed`, `Order.needs-action`, `Shipment.sent`) liegt
   **THEN** wirft die Factory `\InvalidArgumentException`; bei einem zugelassenen Wert wird die Instanz konstruiert. `data.entity` bleibt als `array` erhalten und wird nicht weiter typisiert (per Architecture-Note "Raw `entity` kept untyped").

9) **GIVEN** `Subscription::fromArray()`
   **WHEN** `eventType` nicht in derselben 7-Event-Enum liegt wie in AC-8
   **THEN** wirft die Factory `\InvalidArgumentException`.

10) **GIVEN** `OrderItem::fromArray()`
    **WHEN** `sku` ein leerer String ist **OR** `quantity` < 1 (`0`, `-1`, oder fehlend)
    **THEN** wirft die Factory `\InvalidArgumentException`; bei `sku="ABC-123"` und `quantity=1` wird die Instanz konstruiert.

11) **GIVEN** ein DTO mit camelCase-Properties (Beispiel: `OrderCreate->externalOrderReference`, `Address->zipCode`)
    **WHEN** `DtoMapper::camelToSnake($input)` mit dem zugehoerigen Eingabe-Array (snake_case-Keys aus dem Spreadconnect-API-Body) aufgerufen wird
    **THEN** liefert er ein Array mit camelCase-Keys (`externalOrderReference`, `zipCode`, `customerEmail`, `taxType`, `streetAnnex`, `firstName`, `lastName`, `taxRate`, `taxAmount`, `productTypeId`, `designId`, `colorId`, `sizeId`, `priceCalculation`, `callbackUrl`, `eventType`, `viewId`, `imageUrl`, `expiresAt`, `pointOfSaleId`, `errorReason`).

12) **GIVEN** `DtoMapper::snakeToCamel($input)` mit einem rein camelCase-Array
    **WHEN** der Mapper aufgerufen wird
    **THEN** liefert er ein Array mit snake_case-Keys (Inverse der AC-11-Liste); bei verschachtelten Arrays (z. B. `orderItems`, `data.entity`) konvertiert er rekursiv. **AND** Roundtrip `snakeToCamel(camelToSnake($a))` ist gleich `$a` fuer alle in den DTOs verwendeten Felder.

13) **GIVEN** `composer dump-autoload` (Root) wurde ausgefuehrt
    **WHEN** PHPUnit den Klassen-Resolver aufruft
    **THEN** sind alle 13 DTO-Klassen plus `DtoMapper` ueber den Root-Autoloader unter `SpreadconnectPod\Api\Dto\*` aufloesbar (kein `class_exists`-Fail, kein Fatal Error).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** EIN PHPUnit-Test-File fuer alle DTOs (Slim-Slices nennt es `slice-02-dto-validation.php`). Jeder DTO bekommt einen `it_constructs_with_valid_input` und einen `it_rejects_invalid_input` Block. Plus ein dedizierter Mapper-Test. Test-Writer implementiert die Assertions selbststaendig auf Basis der "Validation"-Spalte aus `architecture.md` Section "DTOs".

### Verletzungs-Matrix (Test-Writer-Hilfe)

| DTO | Required-Field-Verletzung (mind. ein Test pro Zelle) |
|---|---|
| `OrderCreate` | leere `orderItems`, leerer `externalOrderReference`, fehlende `billingAddress`/`shippingAddress` |
| `OrderItem` | leerer `sku`, `quantity=0`, `quantity=-1` |
| `Address` | leerer `firstName`/`lastName`/`street`/`zipCode`/`city`, `country` ohne ISO-2-Format |
| `Money` | `amount` ohne 2-Decimal-Pattern, `currency` ohne 3-ASCII-Upper-Pattern |
| `ArticleSummary` / `ArticleDetail` | leerer `id`, leerer `title`, leerer `productTypeId` |
| `Variant` | (keine harten Validierungen lt. Architecture; nur Type-Asserts) |
| `ShippingType` | (keine harten Validierungen; nur Type-Asserts auf `id`/`company`/`name`/`price`) |
| `Subscription` | unbekannter `eventType` (siehe AC-9) |
| `StockEntry` | `quantity < 0` |
| `Preview` | `imageUrl` nicht `https://` |
| `WebhookEvent` | unbekannter `eventType` (siehe AC-8) |
| `AuthOk` | (keine harten Validierungen; Type-Asserts auf erwartetes Shape, leere Response zulaessig) |

### Test-Datei: `tests/slices/pod-shop-mvp/slice-02-dto-validation.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class DtoValidationTest extends TestCase
{
    // AC-1: Alle DTOs sind final readonly + im korrekten Namespace
    public function test_all_dtos_are_final_readonly_value_objects(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Valid input -> instance per DTO (parametrisiert ueber alle 13 DTOs)
    public function test_valid_input_constructs_instance(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Required-Field-Verletzungen werfen InvalidArgumentException (parametrisiert)
    public function test_invalid_input_throws_with_field_name(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: OrderCreate verlangt orderItems >= 1
    public function test_order_create_rejects_empty_order_items(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Address verlangt ISO 3166-1 alpha-2 country
    public function test_address_rejects_non_iso_alpha2_country(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Money verlangt 2-decimal amount + 3-letter currency
    public function test_money_rejects_invalid_amount_or_currency(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Preview verlangt HTTPS imageUrl
    public function test_preview_rejects_non_https_image_url(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: WebhookEvent rejected unbekannte eventTypes
    public function test_webhook_event_rejects_unknown_event_type(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Subscription rejected unbekannte eventTypes
    public function test_subscription_rejects_unknown_event_type(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: OrderItem verlangt non-empty sku + quantity >= 1
    public function test_order_item_rejects_empty_sku_or_zero_quantity(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: DtoMapper::snakeToCamel auf API-Bodies
    public function test_mapper_converts_snake_to_camel_recursively(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: DtoMapper::camelToSnake + Roundtrip-Identitaet
    public function test_mapper_camel_to_snake_roundtrip_is_identity(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    // AC-13: Alle DTO-Klassen + Mapper sind via Root-Autoloader resolvable
    public function test_all_dto_classes_are_autoloadable(): void
    {
        $this->markTestIncomplete('AC-13');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-02-plugin-bootstrap` | PSR-4-Autoloader-Mapping `SpreadconnectPod\\` -> `wordpress/plugins/spreadconnect-pod/includes/` | Composer-Konfiguration | `composer dump-autoload` (Root) findet Klassen unter `includes/Api/Dto/`. |
| `slice-02-plugin-bootstrap` | Plugin-Wurzel-Verzeichnis und `Bootstrap\Plugin`-Skeleton | Filesystem | Plugin-Bootstrap aktiv; PHPUnit-Bootstrap laedt ohne Fatal. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Api\Dto\OrderCreate` | DTO | `slice-10-endpoint-methods` (`createOrder()`), `slice-28-order-submit-job` | `public static function fromArray(array $data): self` |
| `SpreadconnectPod\Api\Dto\OrderItem` | DTO | `slice-28-order-submit-job` | `public static function fromArray(array $data): self` |
| `SpreadconnectPod\Api\Dto\Address` | DTO | `slice-28-order-submit-job` (build aus WC-Order) | `public static function fromArray(array $data): self` |
| `SpreadconnectPod\Api\Dto\Money` | DTO | `OrderItem`, `ShippingType`, `Variant` | `public static function fromArray(array $data): self` |
| `SpreadconnectPod\Api\Dto\ArticleSummary` / `ArticleDetail` | DTO | `slice-10` (`getArticles`/`getArticle`), `slice-22-product-mapper`, `slice-23-sync-article-job` | `public static function fromResponse(array $data): self` |
| `SpreadconnectPod\Api\Dto\Variant` | DTO | `ArticleSummary`/`ArticleDetail`, `slice-22-product-mapper` | `public static function fromArray(array $data): self` |
| `SpreadconnectPod\Api\Dto\ShippingType` | DTO | `slice-10` (`getShippingTypes`), `slice-29` (Pre-Check) | `public static function fromResponse(array $data): self` |
| `SpreadconnectPod\Api\Dto\Subscription` | DTO | `slice-10` (`getSubscriptions`), `slice-18-subscription-manager` | `public static function fromResponse(array $data): self` |
| `SpreadconnectPod\Api\Dto\StockEntry` | DTO | `slice-10` (`getStock*`), `slice-36-stock-cache` | `public static function fromResponse(array $data): self` |
| `SpreadconnectPod\Api\Dto\Preview` | DTO | `slice-10` (`createPreviews`), `slice-21-image-sideloader` | `public static function fromArray(array $data): self` |
| `SpreadconnectPod\Api\Dto\WebhookEvent` | DTO | `slice-15`/`slice-17` (`ProcessWebhookEventJob`) | `public static function fromArray(array $data): self` |
| `SpreadconnectPod\Api\Dto\AuthOk` | DTO | `slice-10` (`authenticate`), `slice-12-test-connection-ajax` | `public static function fromResponse(array $data): self` |
| `SpreadconnectPod\Api\Dto\DtoMapper` | Helper-Klasse | Alle DTO-Factories + `slice-10-endpoint-methods` | `public static function snakeToCamel(array $input): array` / `public static function camelToSnake(array $input): array` |

---

## Deliverables (SCOPE SAFEGUARD)

> **Boilerplate-Setup-Ausnahme (Regel 3):** slim-slices.md erlaubt fuer Slice 09 explizit eine Datei-pro-Klasse-Aufteilung (siehe slim-slices.md Zeile 239 und Qualitaets-Checkliste Zeile 713). Es handelt sich um 14 Pure-Value-Object-Files ohne Hooks, ohne DB-Calls und ohne UI — der Concern bleibt einzeln und ein-Slice-bezogen.

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/OrderCreate.php` — `final readonly class` mit `fromArray()`-Factory.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/OrderItem.php` — `final readonly class` mit `fromArray()`-Factory (validiert `sku`, `quantity`).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/Address.php` — `final readonly class` mit `fromArray()`-Factory (validiert ISO-3166-1 alpha-2).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/Money.php` — `final readonly class` mit `fromArray()`-Factory (validiert decimal-as-string + 3-letter currency).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/ArticleSummary.php` — `final readonly class` mit `fromResponse()`-Factory.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/ArticleDetail.php` — `final readonly class` mit `fromResponse()`-Factory.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/Variant.php` — `final readonly class` mit `fromArray()`-Factory.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/ShippingType.php` — `final readonly class` mit `fromResponse()`-Factory.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/Subscription.php` — `final readonly class` mit `fromResponse()`-Factory + 7-Event-Enum-Check.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/StockEntry.php` — `final readonly class` mit `fromResponse()`-Factory.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/Preview.php` — `final readonly class` mit `fromArray()`-Factory (HTTPS-Validation).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/WebhookEvent.php` — `final readonly class` mit `fromArray()`-Factory + 7-Event-Enum-Check.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/AuthOk.php` — `final readonly class` mit `fromResponse()`-Factory.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/DtoMapper.php` — Helper mit `snakeToCamel()` / `camelToSnake()` (rekursiv ueber verschachtelte Arrays).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-02-dto-validation.php` basierend auf den Test-Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEINE HTTP-Calls, KEINE WP-Hooks, KEINE DB-Mutationen in DTOs — Pure Value-Objects.
- KEINE Endpoint-Wrapper-Methoden (`getArticles()`, `createOrder()`, ...) — gehoert zu `slice-10-endpoint-methods`.
- KEINE 4-reservierten DTOs fuer "NotImplemented"-Endpoints (`POST /articles`, `DELETE /articles/{id}`, `PUT /orders/{id}`, `POST /designs/upload`) — kommen mit Slice 10 erst, wenn benoetigt.
- KEINE persistierte/serialisierte Form (`__serialize`/`__unserialize` o. ae.) — DTOs leben nur in-memory zwischen Client-Call und Caller.
- KEINE `Preview.imageUrl`-Persistierung (per Architecture: "Presigned URL — used immediately, never stored").
- KEINE Domain-Logik (z. B. `OrderCreate->buildFromWcOrder()`); Builder-Code lebt im jeweiligen Caller (Slice 28 OrderSubmitJob).

**Technische Constraints:**
- PHP 8.2 `final readonly class` PFLICHT — nutze native readonly-Properties (kein `__set`-Trick, kein `private` mit `getX()`-Wrapper).
- `declare(strict_types=1);` als zweite Zeile in jeder Datei.
- Alle Factories: `public static function fromArray(array $data): self` (oder `fromResponse()`, identische Signatur — Naming je nach Richtung). Konstruktor selbst kann `public` sein, aber Factories sind die kanonische Konstruktion.
- Validation-Errors PFLICHT als `\InvalidArgumentException` mit aussagekraeftiger Message (Feldname enthalten).
- ISO 3166-1 alpha-2 Pruefung: regex `^[A-Z]{2}$` (keine Pruefung gegen Country-Liste — KISS; OpenAPI-Spec uebernimmt detaillierte Validierung serverseitig).
- ISO 4217 Currency-Pruefung: regex `^[A-Z]{3}$` (analog).
- Money-`amount` als String mit `^\d+\.\d{2}$` (NICHT als float — verhindert Floating-Point-Precision-Verlust per Architecture-Note).
- `WebhookEvent.eventType` und `Subscription.eventType` Enum als Klassen-Konstante-Liste (z. B. `private const ALLOWED_EVENT_TYPES = [...]`) — Slice 15/18 koennen darauf referenzieren.
- `DtoMapper` arbeitet rein auf Array-Keys, **nicht** auf Property-Namen — DTO-Factories rufen den Mapper als ersten Schritt auf, bevor sie Felder lesen.
- `DtoMapper::snakeToCamel()` und `camelToSnake()` verarbeiten **rekursiv** verschachtelte Arrays (z. B. `orderItems` -> `order_items` mit weiterer Iteration in jedes Item).
- Mapper-Konvention: `foo_bar` <-> `fooBar`, `foo_bar_baz` <-> `fooBarBaz`. Keine Sonderzeichen, keine Trennstriche (Spreadconnect-API verwendet ausschliesslich diese beiden Stile).
- Properties gleichen Typ-Hints wie in Architecture-Tabelle (z. B. `?Money $customerPrice`, `array $orderItems` typed via PHPDoc als `OrderItem[]`).

**Reuse:**

Slice 09 nutzt eine bereits in der Repo-Wurzel existierende Komponente:

| Existing File | Usage in this Slice |
|---|---|
| `composer.json` (Root) | Bestehendes PSR-4-Mapping `SpreadconnectPod\\` -> `wordpress/plugins/spreadconnect-pod/includes/` (per Slice 02). DTO-Klassen unter `includes/Api/Dto/` werden ohne weitere Composer-Aenderungen autogeladen. Architecture: "Autoload | Composer PSR-4 ... | Codebase-Scan EXTEND #5". |

Hinweis: Es gibt KEINE v1-DTO-Klassen, die uebernommen werden (Slice 01 hat das v1-Plugin geloescht). Slice 09 ist ein Greenfield-DTO-Layer.

**Referenzen:**
- Architecture: `architecture.md` -> Section "Data Transfer Objects (DTOs)" (Tabelle ca. Zeilen 163-175) — autoritative Quelle fuer Felder + Validation. **Bei Konflikten zwischen dieser Spec und der Tabelle gilt die Tabelle.**
- Architecture: `architecture.md` -> Service-Map-Zeile `Api\Dto\*` (Layer Domain, Responsibility "Typed DTOs + factory validators (snake_case <-> camelCase mapping)").
- Architecture: `architecture.md` -> "Realistic Data Type Audit" Zeile `Preview.imageUrl` (transient, nicht persistiert).
- Architecture: `architecture.md` -> Open-Question-Resolution "SC picks tax-type from shipping address" (`taxType?` als optionales Feld in `OrderCreate`).
- Discovery: `discovery.md` -> Slice 2 "API Client + Authentication" (Methods typed Request/Response-DTOs).
- Slim-Slices: `slices/slim-slices.md` -> Slice-09-Eintrag (Done-Signal: jede Factory rejectet ungueltige Inputs; valide Inputs ergeben unveraenderbare Instanz).
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 09 (UI rendert DTOs erst ab Slice 11/13/19/26/32).
