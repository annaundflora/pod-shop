# Architecture: POD Shop MVP

**Epic:** –
**Status:** Ready
**Discovery:** `discovery.md` (same folder)
**Derived from:** Discovery constraints, NFRs, and risks

---

## Problem & Solution

**Problem:**
- Spreadshirt-Marketplace bietet kein Pinterest Conversion Tracking
- Kein ROAS-Tracking, keine Kontrolle über Checkout, Tracking, SEO, Branding
- Abhängigkeit von Spreadshirts Plattform-Entscheidungen

**Solution:**
- Eigener POD-Shop: WooCommerce (Backend) + Next.js (Frontend)
- Hybrid-Checkout: Warenkorb in Next.js, Checkout redirect auf WooCommerce
- Pinterest Tag (Client-side) + Conversions API (Server-side)
- Spreadconnect API für POD-Fulfillment
- Lokale Entwicklung mit Docker Compose

**Business Value:**
- Pinterest Conversion-Tracking (PageVisit → AddToCart → Checkout → Purchase)
- Automatische Bestellweiterleitung an Spreadconnect
- Tracking-Nummern + automatische Rechnungen

---

## Scope & Boundaries

| In Scope |
|----------|
| Produktkatalog (Startseite, Kategorien, Produktdetail mit Varianten) |
| Warenkorb im Next.js Frontend |
| Checkout via WooCommerce-Seite (Redirect) |
| Zahlungen via Mollie (Klarna, PayPal, Kreditkarte) |
| Rechnungserstellung via Faktur Pro |
| Rechtliche Seiten (Impressum, AGB, Datenschutz, Widerruf) |
| Cookie Consent |
| Kleinunternehmerregelung §19 UStG |
| POD-Anbindung via Spreadconnect API |
| Tracking-Nummern + Kundenbenachrichtigung |
| Pinterest Tag + Pinterest Conversions API |
| User-Accounts (WooCommerce-seitig) |
| Mobile-first Design |
| Lokale Entwicklung mit Docker Compose |

| Out of Scope |
|--------------|
| Suche / Volltextsuche |
| Wishlist / Bewertungen / Blog |
| Google Ads / Newsletter / Multi-Language |
| Rabattcodes / Coupons |
| SevDesk / Buchhaltung |
| Hetzner Deployment (separates Feature) |
| Eigener Checkout-Flow |

---

## API Design

### Overview

| Aspect | Specification |
|--------|---------------|
| Style | GraphQL (WPGraphQL + WooGraphQL) für Frontend-Queries |
| Authentication | WooGraphQL Session Tokens (JWT-basiert) für Cart-Operationen |
| Rate Limiting | Keine (lokale Entwicklung, WP-Standard) |
| Additional APIs | Spreadconnect REST API, Pinterest Conversions API (Server-side) |

### GraphQL Queries (Frontend → WordPress)

| Query | Parameters | Response | Auth | Purpose |
|-------|-----------|----------|------|---------|
| `products` | `where: { categoryIn, orderby }` | `[{ id, slug, name, price, image, productCategories }]` | No | Produktliste (Homepage, Kategorie) |
| `product` | `slug: String` | `{ id, name, description, price, galleryImages, variations { size, color, stockStatus }, productCategories }` | No | Produktdetailseite |
| `productCategories` | `where: { parent }` | `[{ id, slug, name, image, count }]` | No | Kategorien-Navigation + Homepage |
| `cart` | – | `{ contents { nodes { key, product, variation, quantity, subtotal } }, subtotal, shippingTotal, total }` | Session Token | Warenkorb-Daten |

### GraphQL Mutations (Frontend → WordPress)

| Mutation | Input | Response | Auth | Purpose |
|----------|-------|----------|------|---------|
| `addToCart` | `{ productId, variationId, quantity }` | `{ cart { ...CartFields }, cartItem { key, quantity } }` | Session Token | Produkt in Warenkorb |
| `updateCartItemQuantities` | `{ items: [{ key, quantity }] }` | `{ cart { ...CartFields } }` | Session Token | Menge ändern |
| `removeItemsFromCart` | `{ keys: [String] }` | `{ cart { ...CartFields } }` | Session Token | Produkt entfernen |
| `updateShippingMethod` | `{ shippingMethods: [String] }` | `{ cart { ...CartFields, chosenShippingMethod } }` | Session Token | Versandart setzen |

### Spreadconnect REST API (WordPress → Spreadconnect)

| Method | Endpoint | Request | Response | Purpose |
|--------|----------|---------|----------|---------|
| GET | `/articles` | – | `[{ id, name, productTypeId, designs, appearances }]` | Produkte synchronisieren |
| GET | `/productTypes` | – | `[{ id, name, sizes, appearances }]` | Verfügbare Produkttypen |
| POST | `/orders` | `{ shippingAddress, items: [{ articleId, size, quantity }] }` | `{ orderId, status }` | Bestellung weiterleiten |
| GET | `/orders/{id}` | – | `{ orderId, status, trackingNumber, trackingUrl }` | Tracking abfragen |

### Pinterest Conversions API (WordPress → Pinterest)

| Method | Endpoint | Request | Purpose |
|--------|----------|---------|---------|
| POST | `/ad_accounts/{id}/events` | `{ data: [{ event_name, event_time, event_id, event_source_url, user_data: { em, client_ip_address, client_user_agent }, custom_data }] }` | Server-side Event senden |

### Data Transfer Objects (DTOs)

| DTO | Fields | Validation | Notes |
|-----|--------|------------|-------|
| CartItemInput | `productId: Int!, variationId: Int, quantity: Int` | productId > 0, quantity >= 1 | WooGraphQL Standard |
| SpreadconnectOrderItem | `articleId: String!, sizeId: String!, quantity: Int!` | Muss gültige Spreadconnect IDs sein | Mapping von WooCommerce Variation → Spreadconnect Article |
| PinterestEvent | `event_name: String!, event_id: String!, event_time: Int!, user_data: Object, custom_data: Object` | event_name in [page_visit, view_category, add_to_cart, checkout, purchase] | SHA-256 Hash für email/phone |

---

## Database Schema

### Hinweis

WooCommerce verwaltet das Datenbankschema. Keine eigenen Tables nötig. Relevante WooCommerce-Tabellen:

### Entities

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `wp_posts` (product) | Produkte + Variationen | `ID, post_title, post_content, post_type` |
| `wp_postmeta` | Produktdetails (Preis, SKU, Bilder) | `post_id, meta_key, meta_value` |
| `wp_terms` / `wp_term_taxonomy` | Kategorien, Attribute (Größe, Farbe) | `term_id, name, slug, taxonomy` |
| `wp_woocommerce_order_items` | Bestellpositionen | `order_item_id, order_id, order_item_type` |
| `wp_woocommerce_sessions` | Cart Sessions (WooGraphQL) | `session_key, session_value, session_expiry` |
| `wp_options` | Plugin-Einstellungen (Mollie, Spreadconnect API Keys) | `option_name, option_value` |

### Custom Post Meta (für Spreadconnect-Integration)

| Meta Key | Post Type | Type | Purpose |
|----------|-----------|------|---------|
| `_spreadconnect_article_id` | `product` | TEXT | Zuordnung WooCommerce-Produkt → Spreadconnect Article |
| `_spreadconnect_order_id` | `shop_order` | TEXT | Spreadconnect Order ID nach Weiterleitung |
| `_spreadconnect_tracking_number` | `shop_order` | TEXT | Tracking-Nummer von Spreadconnect |
| `_spreadconnect_tracking_url` | `shop_order` | TEXT | Tracking-URL von Spreadconnect |

---

## Server Logic

### Services & Processing

| Service | Responsibility | Input | Output | Side Effects |
|---------|----------------|-------|--------|--------------|
| **WooGraphQL** (Plugin) | Produkt-Queries, Cart-Mutations, Session-Management | GraphQL Requests | GraphQL Responses | Cart Session Updates |
| **SpreadconnectOrderService** (Custom Plugin) | Bestellung an Spreadconnect weiterleiten (Timeout: 30s, Retry: 3x mit Backoff) | WooCommerce Order Object | Spreadconnect Order ID | API Call an Spreadconnect, Order Meta Update |
| **SpreadconnectTrackingService** (Custom Plugin) | Tracking-Nummer empfangen und speichern | Spreadconnect Webhook/Poll | Tracking-Nummer + URL | Order Meta Update, E-Mail an Kunde |
| **PinterestCAPIService** (Custom Plugin) | Server-side Events an Pinterest senden (async via wp_schedule_single_event, Timeout: 10s) | Order Complete Event | Pinterest API Response | Async API Call an Pinterest, Silent Fail bei Fehler |
| **CheckoutRedirectService** (Next.js) | Cart-Session an WooCommerce Checkout übergeben | Cart Session Token | Redirect URL | Browser Redirect |

### Business Logic Flow

```
[Next.js Frontend]
    │
    ├─ Product Queries ──► WPGraphQL ──► WooCommerce DB
    │
    ├─ Cart Mutations ──► WooGraphQL ──► WooCommerce Session
    │
    └─ "Zur Kasse" ──► Checkout Redirect ──► WooCommerce Checkout Page
                                                    │
                                              [Mollie Payment]
                                                    │
                                              Order Created (status: processing)
                                                    │
                                    ┌───────────────┼───────────────┐
                                    │               │               │
                              Spreadconnect    Faktur Pro     Pinterest CAPI
                              Order Create    Invoice PDF     Purchase Event
                                    │
                              [Tracking Webhook/Poll]
                                    │
                              Order Update + Versand-E-Mail
```

### Validation Rules

| Field | Rule | Error |
|-------|------|-------|
| Cart Quantity | >= 1, <= 99 | "Ungültige Menge" |
| Variante (Größe + Farbe) | Muss vor "In den Warenkorb" gewählt sein | "Bitte Größe und Farbe wählen" (Frontend-Validation) |
| Spreadconnect Article ID | Muss auf Produkt existieren | Bestellung nicht weitergeleitet (Admin-Benachrichtigung) |

---

## Security

### Authentication & Authorization

| Area | Mechanism | Notes |
|------|-----------|-------|
| Frontend Product Queries | Keine Auth nötig | Öffentliche Produktdaten |
| Cart Operations | WooGraphQL Session Token | Token wird bei erster Cart-Mutation erstellt, im Browser (localStorage) gespeichert |
| WooCommerce Checkout | WooCommerce Session Cookie | Standard WP Session Handling |
| WP Admin | WordPress Login (Username + Password) | Standard WordPress Auth |
| Spreadconnect API | API Key (Header: `Authorization`) | Gespeichert in wp_options, nicht im Code |
| Pinterest CAPI | Access Token (Bearer Token) | Gespeichert in wp_options, nicht im Code |
| Mollie | API Key (Live + Test) | Verwaltet durch Mollie Plugin |

### Data Protection

| Data Type | Protection | Notes |
|-----------|------------|-------|
| API Keys (Spreadconnect, Pinterest, Mollie) | Gespeichert in WordPress DB (wp_options), nicht im Quellcode | `.env` für Docker, wp_options für Runtime |
| Kunden-E-Mail (Pinterest CAPI) | SHA-256 Hash vor dem Senden | Pinterest Requirement |
| Zahlungsdaten | Verarbeitet durch Mollie (PCI-DSS Level 1) | Keine Kartendaten auf eigenem Server |
| WooCommerce Session Tokens | Expiry nach 48h (WooCommerce Standard) | Automatische Bereinigung |

### Input Validation & Sanitization

| Input | Validation | Sanitization |
|-------|------------|--------------|
| GraphQL Queries | WPGraphQL Schema-Validierung | Automatisch durch WPGraphQL |
| Cart Inputs | WooGraphQL Type Checking (Int!, String!) | Automatisch durch GraphQL Schema |
| Checkout Formular | WooCommerce Standard Validation | WooCommerce sanitized Inputs |

### Rate Limiting & Abuse Prevention

| Resource | Limit | Notes |
|----------|-------|-------|
| GraphQL Endpoint | WP-Standard (keine spezifische Limitierung) | Für MVP ausreichend, Lokale Entwicklung |
| Spreadconnect API | 60 Calls/Minute (Token-Bucket) | HTTP 429 Handling: `X-RateLimit-Retry-After-Seconds` Header auswerten, Retry nach Header-Wert. `X-RateLimit-Remaining` für proaktives Throttling. Timeout: 30s pro Request. Bei anhaltender 429: Admin-Benachrichtigung |
| Pinterest CAPI | 5000 Calls/Minute, 1000 Events/Batch | Timeout: 10s pro Request. Async/non-blocking Ausführung via `wp_schedule_single_event()`. Silent Fail bei Timeout (kein User-Impact) |

---

## Architecture Layers

### Layer Responsibilities

| Layer | Responsibility | Technology |
|-------|----------------|------------|
| **Presentation** (Next.js) | UI Rendering, Client-side State, Pinterest Tag | Next.js 16 (App Router), React 19, Tailwind CSS v4 |
| **API Gateway** (WordPress) | GraphQL Endpoint, Session Management | WPGraphQL v2.9.0, WooGraphQL v0.21.2 |
| **Business Logic** (WordPress) | Bestellungen, Zahlungen, Rechnungen | WooCommerce 9.x, Mollie Plugin, Faktur Pro |
| **Integration** (WordPress Plugin) | Spreadconnect + Pinterest CAPI | Custom WooCommerce Plugin (PHP) |
| **Data** (MySQL) | Produkte, Bestellungen, Sessions | MySQL 8.0 (Docker Container) |
| **Infrastructure** (Docker) | Container-Orchestrierung | Docker Compose |

### Data Flow

```
[Browser]
    │
    ├── Next.js (localhost:3000) ─── GraphQL ──► WordPress (localhost:8080) ──► MySQL
    │       │                                        │
    │       ├── Pinterest Tag (nach Consent)          ├── WooGraphQL Session
    │       └── Cart State (Context + localStorage)   ├── Mollie Payment Gateway
    │                                                  ├── Faktur Pro Invoice
    │                                                  ├── Spreadconnect API ──► Fulfillment
    │                                                  └── Pinterest CAPI ──► Pinterest
    │
    └── WooCommerce Checkout (localhost:8080/checkout)
            │
            └── Mollie Redirect ──► Zahlung ──► Return URL
```

### Error Handling Strategy

| Error Type | Handling | User Response | Logging |
|------------|----------|---------------|---------|
| GraphQL Network Error | Retry (1x), dann Fehlermeldung | "Verbindungsfehler. Bitte Seite neu laden." | Console Error |
| Cart Mutation Error | Fehlermeldung anzeigen | "Produkt konnte nicht hinzugefügt werden." | WP Error Log |
| Spreadconnect API Error | Retry (3x mit Backoff), dann Admin-Benachrichtigung | Nicht kundenrelevant (Backend) | WP Error Log + E-Mail an Admin |
| Pinterest CAPI Error | Silent Fail (kein User-Impact) | Kein Feedback | WP Error Log |
| Mollie Payment Error | WooCommerce Standard Error Handling | "Zahlung fehlgeschlagen. Bitte erneut versuchen." | WooCommerce Order Notes |
| Product Not Found (404) | Next.js 404 Page | "Produkt nicht gefunden" | – |

---

## Constraints & Integrations

### Constraints

| Constraint (from Discovery) | Technical Implication | Solution |
|-----------------------------|----------------------|----------|
| Kleinunternehmerregelung §19 UStG | Keine MwSt in Preisen/Rechnungen | WooCommerce Tax: "Keine Steuer" konfigurieren. §19-Hinweis in Cart + Faktur Pro Template |
| Versandkosten von Spreadconnect | Müssen in WooCommerce konfiguriert werden | WooCommerce Shipping Zones: Pauschale Versandkosten basierend auf Spreadconnect-Preisliste |
| Cookie Consent vor Pinterest Tag | Tag darf erst nach Consent feuern | Cookie Consent State in localStorage, Pinterest Tag conditional load in Next.js |
| Print-on-Demand (immer verfügbar) | Kein Bestandsmanagement nötig | WooCommerce Stock Management deaktivieren, Produkte immer "In Stock" |
| Hybrid Checkout (Next.js → WooCommerce) | Cart Session muss übergeben werden | WooGraphQL Session Token in URL Parameter bei Redirect |
| Mobile-first (Pinterest Traffic) | Responsive Design, Touch-Optimierung | Tailwind CSS v4 Mobile-first, Touch Targets min 44px |

### Integrations

| Area | System | Interface | Version / Config |
|------|--------|-----------|-----------------|
| GraphQL API | WPGraphQL + WooGraphQL | GraphQL über HTTP (POST /graphql) | WPGraphQL v2.9.0, WooGraphQL v0.21.2 |
| Session Management | WooGraphQL Session Handling | JWT-basierte Session Tokens | WooGraphQL built-in `woocommerce-session` Header. Custom TokenManager in Next.js (localStorage). Falls @woographql/session-utils (privates Registry) nicht verfügbar: eigene Session-Utils basierend auf WooGraphQL Docs |
| Payment Gateway | Mollie for WooCommerce | WooCommerce Payment Gateway API | Mollie Plugin v8.1.3, Test + Live API Keys |
| POD Fulfillment | Spreadconnect REST API | REST API (JSON), API Key Auth | Base URL: api.spreadconnect.com, Staging: staging.spreadconnect.com |
| Invoicing | Faktur Pro (WooRechnung) | WooCommerce Hook (order_status_processing) | Faktur Pro Plugin, Account auf faktur.pro |
| Tracking | Pinterest Conversions API | REST API (POST), Bearer Token Auth | Pinterest API v5, Ad Account ID, Access Token |
| Client-side Tracking | Pinterest Tag | JavaScript SDK (pintrk) | Pinterest Tag ID, Consent-gated |
| Cookie Consent | Kostenloses WP Plugin / Custom | localStorage Flag | z.B. CookieYes, Complianz, oder Custom-Banner |

---

## Quality Attributes (NFRs)

### From Discovery → Technical Solution

| Attribute | Target | Technical Approach | Measure / Verify |
|-----------|--------|--------------------|------------------|
| Performance (Frontend) | LCP < 2.5s, FID < 100ms | Next.js SSG/ISR für Produktseiten, Image Optimization (next/image), Tailwind CSS (kein Runtime CSS) | Lighthouse Score, Web Vitals |
| Performance (API) | GraphQL Response < 500ms | WPGraphQL Object Cache, MySQL Query Optimization | WP Query Monitor Plugin |
| Mobile Experience | Usable auf 320px+ Screens | Tailwind CSS v4 Mobile-first, Touch Targets 44px+, Swipe-Gestures | Manuelle Tests auf echten Geräten |
| SEO | Indexierbare Produktseiten | Next.js SSG mit Meta Tags, Structured Data (JSON-LD Product Schema) | Google Search Console |
| Tracking Accuracy | > 90% Event-Erfassung | Pinterest Tag + CAPI dual, event_id Deduplizierung | Pinterest Events Manager |
| Reliability (Orders) | Keine verlorenen Bestellungen | Spreadconnect Retry (3x), Admin-Notification bei Fehler | WP Error Log, manuelle Prüfung |

### Monitoring & Observability

| Metric | Type | Target | Method |
|--------|------|--------|--------|
| GraphQL Errors | Error Count | 0 pro Session | WP Error Log |
| Spreadconnect Order Success | Success Rate | 100% | WP Error Log + Admin E-Mail bei Fehler |
| Pinterest Event Delivery | Event Count | Matches Frontend Events | Pinterest Events Manager Dashboard |
| Page Load Time | LCP | < 2.5s | Lighthouse CI |

---

## Risks & Assumptions

### Assumptions

| Assumption (from Discovery) | Technical Validation | Impact if Wrong |
|-----------------------------|---------------------|-----------------|
| Kleinunternehmerregelung bleibt | WooCommerce Tax Config | Tax-Plugin nachrüsten (Germanized oder manuell) |
| Spreadconnect API verfügbar + kompatibel | Staging Area testen, API Docs prüfen | Alternativer POD-Anbieter (Shirtigo, Gelato) |
| Docker auf Windows stabil | Docker Desktop for Windows mit WSL2 | Fallback: LocalWP oder XAMPP |
| Mollie unterstützt Kleinunternehmer | Mollie Account erstellen und prüfen | Alternative: Stripe |
| Pinterest CAPI ohne Mindestbudget | Pinterest Business Account erstellen | Nur Client-side Tag nutzen |
| WooGraphQL Session Redirect funktioniert | Prototyp in Slice 3 testen | Alternative: WooCommerce Store API für Cart, REST-basiert |

### Risks & Mitigation

| Risk | Likelihood | Impact | Technical Mitigation | Fallback |
|------|------------|--------|---------------------|----------|
| WooGraphQL Breaking Changes | Medium | High | Versionen pinnen (WPGraphQL 2.9.0, WooGraphQL 0.21.2), Composer Lock | WooCommerce REST API als Fallback |
| Spreadconnect API Downtime | Low | High | Retry-Logik (3x mit exponential Backoff), Order Queue | Manuelle Bestellweiterleitung via Spreadconnect Dashboard |
| Cart Session verloren bei Redirect | Medium | High | Session Token in URL + localStorage Backup | WooCommerce Store API Cart Token als Alternative |
| Pinterest Tag geblockt (AdBlocker) | High | Medium | CAPI als Server-side Backup (AdBlocker-resistent) | Nur CAPI nutzen |
| Docker Performance auf Windows | Medium | Low | WSL2 Backend, Volume Mounts optimieren | LocalWP als Alternative |
| Mollie Plugin inkompatibel mit WPGraphQL | Low | Medium | Standard WooCommerce Checkout (kein Headless Checkout) | Plugin-Konflikte in Slice 3 testen |

---

## Technology Decisions

### Stack Choices

| Area | Technology | Version | Rationale |
|------|------------|---------|-----------|
| Frontend Framework | Next.js | 16.x (App Router) | SSG/ISR, React Server Components, Image Optimization |
| Frontend UI | Tailwind CSS | v4 | CSS-first, kein Runtime Overhead, Mobile-first Utilities |
| Frontend State | React Context | React 19 | Cart State lokal, kein Redux nötig für MVP Scope |
| GraphQL Client | Apollo Client | 4.x | Mature, Caching, WooGraphQL-kompatibel. v4: kleinerer Bundle (~20-30%), opt-in lokaler State |
| Session Utils | WooGraphQL Session (Custom TokenManager) | – | WooGraphQL `woocommerce-session` Header + Custom TokenManager (localStorage). @woographql/session-utils ist privates Registry (ggf. Pro-Lizenz) – eigene Implementierung basierend auf WooGraphQL Docs |
| Backend CMS | WordPress | 6.9.x | WooCommerce Ökosystem, Plugin-Support |
| E-Commerce | WooCommerce | 10.x | Checkout, Orders, Payments out of the box |
| GraphQL Layer | WPGraphQL + WooGraphQL | 2.9.0 / 0.21.2 | Aktuelle stabile Versionen (Feb 2026), Greenfield-Projekt |
| Payment | Mollie for WooCommerce | 8.1.3 | Klarna + PayPal + Kreditkarte in einem Plugin |
| Invoicing | Faktur Pro | 4.x (Download Feb 2026) | GoBD-konforme Rechnungen, bewährtes Plugin. Kein öffentliches Versionierungsschema - Download-Datum dokumentieren |
| POD Fulfillment | Spreadconnect REST API | v1 | Bestehender Account, kostenlos, JS SDK vorhanden |
| Tracking (Client) | Pinterest Tag | pintrk JS SDK | Standard Pinterest Client-side Tracking |
| Tracking (Server) | Pinterest Conversions API | v5 | Server-side Events, AdBlocker-resistent |
| Database | MySQL | 8.0 | WooCommerce Standard, Docker Image verfügbar |
| Local Dev | Docker Compose | v2.x+ | WordPress + MySQL + phpMyAdmin Container. Images: wordpress:6.9-php8.2-apache, mysql:8.0, phpmyadmin:5.2 |
| Cookie Consent | Custom Implementation | – | Einfacher Banner, localStorage, kein Plugin-Overhead |

### Trade-offs

| Decision | Pro | Con | Mitigation |
|----------|-----|-----|------------|
| WPGraphQL statt REST API | Flexibler für Frontend, weniger Over-fetching, besseres DX | Plugin-Abhängigkeit, Community-maintained | Versionen pinnen, REST als Fallback |
| Hybrid Checkout (Redirect) | Alle Mollie-Zahlungsarten sofort, kein eigener Payment-Code | UX-Bruch beim Redirect, Session-Übergabe nötig | WooCommerce Checkout minimal stylen, Session Token testen |
| Docker statt LocalWP | Reproduzierbar, später auf Hetzner übertragbar | Komplexer Setup, Docker Desktop nötig | docker-compose.yml vorkonfiguriert, Dokumentation |
| Apollo Client statt urql | Größere Community, mehr WooGraphQL-Beispiele. v4: 20-30% kleinerer Bundle, opt-in lokaler State | Breaking Changes zu v3 bei Migration | Greenfield-Projekt → direkt v4 nutzen, kein Migrations-Overhead |
| Custom Cookie Banner statt Plugin | Volle Kontrolle, kein WP-Plugin nötig | Selbst implementieren | Einfache Implementierung (2 Buttons, localStorage) |
| React Context statt Zustand/Redux | Einfachste Lösung für Cart State, kein Extra-Dependency | Re-renders bei Context-Updates | Cart Context isolieren, useMemo für Werte |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| 1 | Domain für lokale Entwicklung? | A) localhost:3000 + localhost:8080 B) Custom .local Domains via hosts-Datei | A) localhost (einfacher) | – |
| 2 | Spreadconnect: Polling oder Webhooks für Tracking? | A) Cron-Job Polling B) Webhook (falls API unterstützt) | B) Webhook (wenn verfügbar), sonst A) | – |
| 3 | Pinterest Tag: Custom oder über WP Plugin? | A) Custom im Next.js Frontend B) WP Plugin für WooCommerce Checkout | A) Custom (mehr Kontrolle über Consent) | – |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-21 | WPGraphQL + WooGraphQL | v2.9.0 / v0.21.2 aktuelle Versionen (verifiziert via GitHub Releases). Referenz-Repo: w3bdesign/nextjs-woocommerce (getestet mit v2.3.3/v0.19.0). Greenfield → aktuelle Versionen nutzen |
| 2026-02-21 | Next.js | v16.x aktuell (16.1.6 stabil, released Dez 2025). App Router, stable Turbopack, React Compiler support. Zuvor fälschlich als 15.x dokumentiert. |
| 2026-02-21 | WooCommerce | v10.x aktuell (10.5.2, released Feb 2026). Major Version 10 seit Ende 2025. Zuvor fälschlich als 9.x dokumentiert. |
| 2026-02-21 | Apollo Client | v4.x aktuell (4.1.4). Breaking Changes zu v3: opt-in lokaler State, kleinerer Bundle. Für Greenfield direkt v4 nutzen. |
| 2026-02-21 | WooGraphQL Session | JWT-basierte Session Tokens via @woographql/session-utils. Cart Mutations: addToCart, updateCartItemQuantities, removeItemsFromCart. Checkout Redirect empfohlen. |
| 2026-02-21 | Spreadconnect API | REST API, JS SDK (spreadconnect-js-sdk npm). Module: Articles, Orders, ProductTypes, Stocks, Designs. Staging Area verfügbar. Auth via API Key Header. |
| 2026-02-21 | Pinterest CAPI | Deduplizierung via event_id (Tag + CAPI gleiche ID). SHA-256 Hash für email/phone. Events: page_visit, view_category, add_to_cart, checkout, purchase. |
| 2026-02-21 | Mollie | WooCommerce Plugin, API Key Setup, Test Mode verfügbar. Klarna, PayPal, Kreditkarte. PCI-DSS Level 1. |
| 2026-02-21 | Docker Local Dev | WordPress + MySQL via Docker Compose. Images: wordpress:6.9-php8.2-apache, mysql:8.0, phpmyadmin:5.2. Ports: 8080 (WP), 3000 (Next.js), 8180 (phpMyAdmin). |
| 2026-02-21 | Checkout Redirect | WooGraphQL Session Token wird bei Redirect an WooCommerce übergeben. Alternative: WooCommerce Store API Cart-Tokens. |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| 1 | Lokale WordPress-Installation: Welches Setup? Docker Compose, LocalWP, oder XAMPP? | Docker Compose – reproduzierbar, isoliert, später auf Hetzner übertragbar |
| 2 | Architecture-Tiefe: Kurz, Standard oder Detailliert? | Standard – alle Sections, gute Balance für MVP |
| 3 | Soll Architecture auch Hetzner Deployment abdecken? | Nein, nur lokale Entwicklung. Deployment wird separates Feature |
