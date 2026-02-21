# Gate 1: Architecture Compliance Report

**Gepruefte Architecture:** `E:\WebDev\pod-shop\docs\features\pod-shop-mvp\architecture.md`
**Pruefdatum:** 2026-02-21
**Discovery:** `E:\WebDev\pod-shop\docs\features\pod-shop-mvp\discovery.md`
**Wireframes:** `E:\WebDev\pod-shop\docs\features\pod-shop-mvp\wireframes.md`

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 49 |
| BLOCKING | 0 |

**Verdict:** APPROVED

---

## Previously Reported Issues -- All Resolved

| # | Issue | Resolution | Status |
|---|-------|-----------|--------|
| 1 | @woographql/session-utils privates Registry | Architecture Zeile 294+364: Custom TokenManager dokumentiert, privates Registry Problem erkannt, eigene Implementierung basierend auf WooGraphQL Docs | RESOLVED |
| 2 | phpMyAdmin Docker Image ungepinnt ("latest") | Architecture Zeile 374: `phpmyadmin:5.2` gepinnt | RESOLVED |
| 3 | Spreadconnect Rate Limit als "unbekannt" dokumentiert | Architecture Zeile 227: "60 Calls/Minute (Token-Bucket)" mit `X-RateLimit-Retry-After-Seconds` Header-basiertem Retry und `X-RateLimit-Remaining` fuer proaktives Throttling | RESOLVED |
| 4 | Pinterest CAPI Timeout fehlt und Rate Limit ungenau | Architecture Zeile 228: "5000 Calls/Minute, 1000 Events/Batch", Timeout 10s. Zeile 153: "async via wp_schedule_single_event" | RESOLVED |
| 5 | WPGraphQL/WooGraphQL Versionsinkonsistenz in Risks-Tabelle | Architecture Zeile 345: Versionen korrekt auf 2.9.0/0.21.2 aktualisiert | RESOLVED |

---

## A) Feature Mapping

| # | Discovery Feature | Architecture Section | API Endpoint | DB Schema | Status |
|---|-------------------|---------------------|--------------|-----------|--------|
| 1 | Produktkatalog (Startseite, Kategorien, Produktdetail mit Varianten) | API Design - GraphQL Queries | `products`, `product`, `productCategories` | `wp_posts`, `wp_postmeta`, `wp_terms` | PASS |
| 2 | Warenkorb im Frontend | API Design - GraphQL Mutations | `addToCart`, `updateCartItemQuantities`, `removeItemsFromCart` | `wp_woocommerce_sessions` | PASS |
| 3 | Checkout via WooCommerce-Seite (Redirect) | Server Logic - CheckoutRedirectService | Redirect-Flow dokumentiert | WooCommerce Session | PASS |
| 4 | Zahlungen via Mollie (Klarna, PayPal, Kreditkarte) | Constraints and Integrations | Mollie Plugin Integration | `wp_options` (API Keys) | PASS |
| 5 | Rechnungserstellung via Faktur Pro | Constraints and Integrations | Hook: `order_status_processing` | Faktur Pro Plugin | PASS |
| 6 | Rechtliche Seiten (Impressum, AGB, Datenschutz, Widerruf) | Scope and Boundaries | WooCommerce WordPress Pages | WordPress Pages | PASS |
| 7 | Cookie Consent | Constraints - Cookie Consent | localStorage Flag, conditional load | Kein DB Schema noetig | PASS |
| 8 | Kleinunternehmerregelung 19 UStG | Constraints | WooCommerce Tax: "Keine Steuer", 19-Hinweis in Cart + Faktur Pro | WooCommerce Tax Config | PASS |
| 9 | POD-Anbindung via Spreadconnect API | API Design - Spreadconnect REST API | GET `/articles`, POST `/orders`, GET `/orders/{id}` | Custom Post Meta (4 Felder) | PASS |
| 10 | Tracking-Nummern + Kundenbenachrichtigung | Server Logic - SpreadconnectTrackingService | GET `/orders/{id}` | `_spreadconnect_tracking_number`, `_spreadconnect_tracking_url` | PASS |
| 11 | Pinterest Tag (Client-side) | Constraints and Integrations | JavaScript SDK (pintrk) | Kein DB Schema noetig | PASS |
| 12 | Pinterest Conversions API (Server-side) | API Design - Pinterest CAPI, Server Logic | POST `/ad_accounts/{id}/events` | Kein eigenes Schema | PASS |
| 13 | User-Accounts (Login, Registrierung, Bestellhistorie, Adressen) | Scope and Boundaries | WooCommerce-seitig (kein eigener API Endpoint) | WooCommerce Standard | PASS |
| 14 | Mobile-first Design | Constraints, Quality Attributes | Tailwind CSS v4 Mobile-first, Touch Targets 44px+ | N/A | PASS |
| 15 | Lokale Entwicklung mit Docker Compose | Technology Decisions, Architecture Layers | Docker Compose mit WordPress + MySQL + phpMyAdmin | MySQL 8.0 Container | PASS |

**Feature Mapping Result:** 15/15 PASS -- Alle Discovery Features sind in der Architecture abgedeckt.

---

## B) Constraint Mapping

| # | Constraint | Source | Wireframe Ref | Architecture | Status |
|---|-----------|--------|---------------|--------------|--------|
| 1 | Kleinunternehmer-Hinweis im Warenkorb | Discovery: Business Rules | Wireframe Cart: "Gemaess 19 UStG wird keine Umsatzsteuer berechnet." | Constraints: "19-Hinweis in Cart + Faktur Pro Template" | PASS |
| 2 | Versandkosten als separate Zeile im Warenkorb | Discovery: Business Rules | Wireframe Cart: Annotation 7 "Versandkosten 4,99 EUR" | Cart Query Response: `shippingTotal` Feld | PASS |
| 3 | "inkl. Versandkosten" Hinweis auf Produktdetailseite | Discovery: UI Layout | Wireframe PDP: "inkl. Versandkosten" unter Preis | Frontend-only, kein Architecture Impact | PASS |
| 4 | Cookie Consent vor Pinterest Tag | Discovery: Business Rules | Wireframe Cookie Banner: Accept/Reject | Constraints: "Cookie Consent State in localStorage, Pinterest Tag conditional load" | PASS |
| 5 | Variante muss vor "In den Warenkorb" gewaehlt sein | Discovery: UI Components | Wireframe PDP: Button disabled "Bitte Groesse und Farbe waehlen" | Validation Rules: "Muss vor In den Warenkorb gewaehlt sein" | PASS |
| 6 | Produkt-Card: Hover State (Scale/Shadow) | Discovery: UI Components | Wireframe: "Subtle scale/shadow effect" | CSS-only, kein Architecture Impact | PASS |
| 7 | Warenkorb: Menge min 1, max 99 | Discovery: UI Components | Wireframe Cart: Annotation 4 "min 1" | Validation Rules: ">= 1, <= 99" | PASS |
| 8 | Image Gallery: Swipe + Dots (mobil), Thumbnails (desktop) | Discovery: UI Layout | Wireframe PDP: Dots navigation, Desktop thumbnails | Frontend Component-Level, `galleryImages` in Product Query | PASS |
| 9 | Warenkorb-Icon mit Badge Counter | Discovery: UI Components | Wireframe Header: Badge with number | Cart Query liefert `contents { nodes }` | PASS |
| 10 | Leerer Warenkorb State | Discovery: Feature State Machine | Wireframe Cart: "Dein Warenkorb ist leer" + "Weiter shoppen" | Cart Query liefert leere nodes-Liste | PASS |
| 11 | Filter-Chips auf Kategorieseite | Discovery: UI Layout | Wireframe Category: Annotation 2 horizontal scrollable chips | `productCategories` Query verfuegbar | PASS |
| 12 | Produktanzahl auf Kategorieseite | Discovery: UI Layout | Wireframe Category: Annotation 3 "12 Produkte" | `productCategories` Response enthaelt `count` Feld | PASS |
| 13 | Mobile Hamburger Menu | Wireframe Header | Wireframe: Overlay with category links, legal links, "Mein Konto" | Frontend-only, Navigation-Links vorhanden | PASS |
| 14 | Print-on-Demand immer verfuegbar | Discovery: Business Rules | N/A | Constraints: "WooCommerce Stock Management deaktivieren, Produkte immer In Stock" | PASS |
| 15 | Session-Uebergabe bei Checkout Redirect | Discovery: Checkout-Strategie | Wireframe: "Zur Kasse" Button triggers redirect | Constraints: "WooGraphQL Session Token in URL Parameter bei Redirect" | PASS |
| 16 | Hero-Bereich auf Startseite | Discovery: UI Layout | Wireframe Homepage: Hero Image + Tagline + CTA | Content in WP verwaltet, Frontend-only Layout | PASS |
| 17 | Kategorie-Uebersicht auf Startseite | Discovery: UI Layout | Wireframe Homepage: Annotation 4 Kategorien | `productCategories` Query vorhanden | PASS |

**Constraint Mapping Result:** 17/17 PASS -- Alle Constraints abgedeckt.

---

## C) Realistic Data Check

### Codebase Evidence

```
Greenfield-Projekt: Keine existierende Codebase, keine Migrations, kein package.json,
kein composer.json vorhanden.

WooCommerce Standard: wp_postmeta.meta_value ist LONGTEXT (bis 4GB).
Custom Post Meta Felder werden als key-value Paare in wp_postmeta gespeichert.
Architecture definiert Custom Post Meta mit Typ "TEXT" -- das ist die logische Beschreibung.
WooCommerce speichert alles in LONGTEXT meta_value. Kein Laengen-Risiko.

Quellen:
- WordPress Database Description: https://codex.wordpress.org/Database_Description
- wp_postmeta Table Structure: https://www.wpdir.com/wp-postmeta-table/
```

### External API Analysis

| API | Field | Evidence | Measured/Expected Length | Arch Type | Recommendation | Status |
|-----|-------|----------|------------------------|-----------|----------------|--------|
| Spreadconnect | `_spreadconnect_article_id` | API Docs: `articleId` ist Integer (Path Parameter). WP meta_value speichert Integers als Strings | Max 10 chars (Integer als String) | TEXT (Post Meta) | TEXT korrekt -- WP meta_value ist LONGTEXT | PASS |
| Spreadconnect | `_spreadconnect_order_id` | API Docs: `id` ist Integer, `orderReference` ist Integer | Max 10 chars (Integer als String) | TEXT (Post Meta) | TEXT korrekt -- LONGTEXT in DB | PASS |
| Spreadconnect | `_spreadconnect_tracking_number` | API Docs: `tracking.code` ist String. Carrier-Nummern: DHL 20 chars, DPD 14 chars, Hermes 16 chars | Max ~35 chars | TEXT (Post Meta) | TEXT korrekt -- LONGTEXT in DB | PASS |
| Spreadconnect | `_spreadconnect_tracking_url` | API Docs: `tracking.url` ist String. DHL Beispiel: `https://www.dhl.de/de/privatkunden/...?piececode=00340...` | 80-300 chars typisch | TEXT (Post Meta) | TEXT korrekt -- LONGTEXT in DB | PASS |
| Pinterest CAPI | `event_id` | Pinterest API v5 Docs: String, keine dokumentierte Max-Laenge. Empfehlung: UUID v4 fuer Deduplizierung | 36 chars (UUID v4) | String (DTO) | Korrekt, kein DB-Feld | PASS |
| Pinterest CAPI | `user_data.em` | Pinterest API v5: SHA-256 Hash, lowercase hex | Exakt 64 hex chars | Object (DTO) | Korrekt, kein DB-Feld | PASS |
| Pinterest CAPI | `custom_data.value` | Pinterest API v5: String, parsed as double | Variable Laenge | String (DTO) | Korrekt | PASS |
| Pinterest CAPI | `custom_data.content_ids` | Pinterest API v5: Array of strings (product IDs) | Variable Laenge | Array (DTO) | Korrekt | PASS |
| WooGraphQL | Session Token | JWT Token (Header.Payload.Signature, HS256) | 200-500 chars typisch | localStorage im Browser | Korrekt, WP-seitig in wp_woocommerce_sessions (LONGTEXT) | PASS |

### Data Type Verdicts

| Field | Arch Type | Evidence | Verdict | Issue |
|-------|-----------|----------|---------|-------|
| `_spreadconnect_article_id` | TEXT | Spreadconnect API: Integer ID. WP meta_value = LONGTEXT. Integer-als-String ist WP Standard-Pattern | PASS | Keine |
| `_spreadconnect_order_id` | TEXT | Spreadconnect API: Integer ID. WP meta_value = LONGTEXT | PASS | Keine |
| `_spreadconnect_tracking_number` | TEXT | Spreadconnect API: String. WP meta_value = LONGTEXT, max ~35 chars | PASS | Keine |
| `_spreadconnect_tracking_url` | TEXT | Spreadconnect API: String. WP meta_value = LONGTEXT, URLs variabel 80-300+ chars | PASS | Keine |
| CartItemInput.productId | Int! | WooCommerce Post IDs sind MySQL BIGINT(20). GraphQL Int (32-bit) reicht fuer lokale Instanz | PASS | Keine |
| CartItemInput.quantity | Int | Architecture: 1-99, GraphQL Int ausreichend | PASS | Keine |
| SpreadconnectOrderItem.articleId | String! | Spreadconnect API nutzt Integer IDs. String-Typ im DTO ist akzeptabel (typische API-Abstraktion) | PASS | Keine |
| PinterestEvent.event_name | String! | Pinterest API: Enum von 22 Werten, max 18 chars ("submit_application") | PASS | Keine |
| PinterestEvent.event_id | String! | UUID v4: 36 chars. Kein dokumentiertes Max-Limit | PASS | Keine |
| PinterestEvent.event_time | Int! | Unix timestamp. GraphQL Int (32-bit signed) reicht bis 2038. MVP Zeithorizont unter 2038 | PASS | Keine |
| Product price | WooCommerce Standard | EUR, > 0, verwaltet als meta_value (String in LONGTEXT) | PASS | Keine |
| PLZ (Checkout) | 5 Ziffern (DE) | WooCommerce Checkout Validation Standard | PASS | Keine |

**Data Type Verdict:** 12/12 PASS -- Alle Datentypen sind realistisch gewaehlt. WooCommerce's wp_postmeta mit LONGTEXT eliminiert Feldlaengen-Risiken fuer Custom Post Meta.

---

## D) External Dependencies

### D1) Dependency Version Check

| # | Dependency | Arch Version | Pinning Method | Pinned? | Status |
|---|-----------|-------------|----------------|---------|--------|
| 1 | Next.js | 15.x (App Router) | Major-Range | Ja | PASS |
| 2 | React | 19 | Major (durch Next.js 15) | Ja | PASS |
| 3 | Tailwind CSS | v4 | Major | Ja | PASS |
| 4 | Apollo Client | 3.x | Major-Range | Ja | PASS |
| 5 | WooGraphQL Session | Custom TokenManager | Eigene Implementierung (kein externes Package) | N/A | PASS |
| 6 | WordPress | 6.9.x | Docker Image: `wordpress:6.9-php8.2-apache` | Ja | PASS |
| 7 | WooCommerce | 9.x | Major-Range | Ja | PASS |
| 8 | WPGraphQL | 2.9.0 | Exakt gepinnt | Ja | PASS |
| 9 | WooGraphQL | 0.21.2 | Exakt gepinnt | Ja | PASS |
| 10 | Mollie for WooCommerce | 8.1.3 | Exakt gepinnt | Ja | PASS |
| 11 | Faktur Pro | 4.x (Download Feb 2026) | Download-Datum dokumentiert | Ja (kein oeffentliches Versionsschema) | PASS |
| 12 | MySQL | 8.0 | Docker Image: `mysql:8.0` | Ja | PASS |
| 13 | Docker Compose | v2.x+ | Minimum-Version | Ja | PASS |
| 14 | Docker Image: wordpress | 6.9-php8.2-apache | Exakt gepinnt | Ja | PASS |
| 15 | Docker Image: mysql | 8.0 | Major.Minor gepinnt | Ja | PASS |
| 16 | Docker Image: phpmyadmin | 5.2 | Major.Minor gepinnt | Ja | PASS |
| 17 | Pinterest Conversions API | v5 | API-Version | Ja | PASS |
| 18 | Spreadconnect REST API | v1 | API-Version | Ja | PASS |
| 19 | Pinterest Tag | pintrk JS SDK | CDN-hosted, versionsfrei | Akzeptabel fuer CDN-SDK | PASS |
| 20 | Cookie Consent | Custom Implementation | Eigene Implementierung | N/A | PASS |

**Dependency Version Result:** 20/20 PASS -- Alle Dependencies sind versioniert oder haben dokumentierte Ausnahmen.

### D2) External APIs and Services

| Dependency | Rate Limits | Auth | Errors | Timeout | Status |
|------------|-------------|------|--------|---------|--------|
| Spreadconnect REST API | 60 Calls/Minute (Token-Bucket). Header: `X-RateLimit-Retry-After-Seconds` fuer Retry, `X-RateLimit-Remaining` fuer proaktives Throttling | API Key (Header: Authorization) | Retry 3x mit Backoff, Admin-Notification. HTTP 429: Header-basierter Retry | 30s pro Request | PASS |
| Pinterest Conversions API | 5000 Calls/Minute, 1000 Events/Batch | Bearer Token | Silent Fail (kein User-Impact), WP Error Log | 10s pro Request, async via `wp_schedule_single_event` | PASS |
| Mollie Payment Gateway | Mollie-seitig verwaltet | API Key (Live + Test) | WooCommerce Standard Error Handling | WooCommerce Standard | PASS |
| WPGraphQL (lokal) | WP-Standard, keine spezifische Limitierung | Session Token (JWT) fuer Cart | Retry 1x, Fehlermeldung | Lokal, kein Timeout-Risiko | PASS |

**External APIs Result:** 4/4 PASS -- Alle externen APIs vollstaendig dokumentiert.

---

## E) Completeness Check (Architecture Template Sections)

| Section | Vorhanden | Vollstaendig | Status |
|---------|-----------|-------------|--------|
| Problem and Solution | Ja | Problem, Solution, Business Value | PASS |
| Scope and Boundaries | Ja | In Scope (14 Items), Out of Scope (8 Items) | PASS |
| API Design | Ja | Overview, GraphQL Queries (4), Mutations (4), REST APIs (2), DTOs (3) | PASS |
| Database Schema | Ja | WooCommerce Tables (6), Custom Post Meta (4) | PASS |
| Server Logic | Ja | Services (5), Business Logic Flow, Validation Rules (3) | PASS |
| Security | Ja | Auth (7 Areas), Data Protection (4), Input Validation (3), Rate Limiting (3) | PASS |
| Architecture Layers | Ja | Layers (6), Data Flow, Error Handling (6 Types) | PASS |
| Constraints and Integrations | Ja | Constraints (6), Integrations (8) | PASS |
| Quality Attributes (NFRs) | Ja | 6 Attributes mit Targets, 4 Monitoring Metrics | PASS |
| Risks and Assumptions | Ja | 6 Assumptions, 6 Risks mit Mitigation | PASS |
| Technology Decisions | Ja | 16 Stack Choices mit Versionen, 6 Trade-offs | PASS |
| Open Questions | Ja | 3 Questions mit Optionen und Empfehlungen | PASS |
| Research Log | Ja | 7 Research Entries mit Datum und Findings | PASS |
| Q&A Log | Ja | 3 Entries | PASS |

**Completeness Result:** 14/14 PASS -- Alle Architecture Template Sections vorhanden und vollstaendig.

---

## Blocking Issues

Keine.

---

## Recommendations

Keine blockierenden Empfehlungen. Folgende Hinweise fuer die Implementierung:

1. **[Info]** `PinterestEvent.event_time` nutzt GraphQL Int (32-bit signed), was bis Jahr 2038 funktioniert. Fuer ein MVP mit Zeithorizont weit vor 2038 ist dies akzeptabel. Bei langfristiger Nutzung auf Int64/String umstellen.

2. **[Info]** `SpreadconnectOrderItem.articleId` ist in der Architecture als `String!` definiert, waehrend die Spreadconnect API Integer-IDs nutzt. Dies ist ein gaengiges Pattern (API-Abstraktion), aber Entwickler sollten bei der Implementierung Integer-Parsing validieren.

3. **[Info]** Die Pinterest CAPI Docs listen 22 moegliche `event_name` Werte. Die Architecture dokumentiert 5 davon (`page_visit`, `view_category`, `add_to_cart`, `checkout`, `purchase`). Dies ist korrekt fuer den MVP Scope.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 0

**Alle 5 vorherigen Blocking Issues wurden erfolgreich behoben:**
1. @woographql/session-utils durch Custom TokenManager ersetzt (privates Registry Problem geloest)
2. phpMyAdmin Docker Image gepinnt auf 5.2
3. Spreadconnect Rate Limit dokumentiert: 60 Calls/Minute (Token-Bucket) mit Header-basiertem Retry
4. Pinterest CAPI: Timeout 10s, Rate Limit 5000 Calls/Min + 1000 Events/Batch, async via wp_schedule_single_event
5. Risks-Tabelle Versionen auf 2.9.0/0.21.2 aktualisiert

---

## Research Sources

- [WordPress Database Description](https://codex.wordpress.org/Database_Description) -- wp_postmeta.meta_value ist LONGTEXT
- [wp_postmeta Table Structure](https://www.wpdir.com/wp-postmeta-table/) -- Schema-Referenz
- [Spreadconnect API Docs](https://api.spreadconnect.app/docs) -- Endpoints, Field Types, Rate Limits
- [Spreadconnect FAQ](https://faq.spreadconnect.app/hc/en-us/articles/360020927339) -- API Setup
- [Pinterest Conversions API v5](https://developers.pinterest.com/docs/api/v5/events-create/) -- Events Create Schema
- [Pinterest Conversions API Overview](https://developers.pinterest.com/docs/conversions/updated/) -- Best Practices
- [WooGraphQL Session Handling](https://woographql.com/docs/configuring-graphql-client-for-user-session) -- JWT Session Token Format
- [WooGraphQL Session Handler Source](https://github.com/wp-graphql/wp-graphql-woocommerce/blob/develop/includes/utils/class-ql-session-handler.php) -- Token Implementation
