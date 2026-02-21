# Integration Map: POD Shop MVP

**Generated:** 2026-02-21
**Slices:** 7
**Connections:** 18

---

## Dependency Graph (Visual)

```
┌───────────────────────────────────────────────────────┐
│          Slice 01: Infrastruktur                      │
│  (TokenManager, apolloClient, ApolloWrapper,          │
│   WPGraphQL Endpoint, WooCommerce DB, Docker Stack)   │
└───────────┬───────────────────────────────────────────┘
            │
            ▼
┌───────────────────────────────────────────────────────┐
│          Slice 02: Produktkatalog Frontend             │
│  (Header, Footer, Types, Fragments, AddToCartButton,  │
│   ProductVariantSelector, Kategorie- & Produktseiten) │
└──────────────────┬────────────────────────────────────┘
                   │
                   ▼
┌───────────────────────────────────────────────────────┐
│       Slice 03: Warenkorb + Checkout-Redirect         │
│  (CartContext, useCart, CartProvider,                 │
│   WooCommerce Bestellsystem, checkoutRedirect)        │
└────┬──────────┬──────────┬──────────┬────────────────┘
     │          │          │          │
     ▼          ▼          ▼          ▼
┌─────────┐ ┌────────┐ ┌────────┐ ┌──────────────────┐
│ Slice 04│ │Slice 05│ │Slice 06│ │    Slice 07      │
│Rechtlich│ │Spreadc.│ │Pinterst│ │  User Accounts   │
│Rechnungen│ │  POD  │ │Tracking│ │ (Mein Konto Link)│
└─────┬───┘ └────────┘ └────────┘ └──────────────────┘
      │
      ▼ (cookie-consent Flag)
┌─────────────────────────────────────────────────────┐
│       Slice 06: Pinterest Tracking                  │
│  (konsumiert cookie-consent von Slice 04)           │
└─────────────────────────────────────────────────────┘
```

---

## Nodes

### Slice 01: Infrastruktur

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Stack | typescript-nextjs + docker-compose |
| Dependencies | None |
| Test Command | `pnpm test tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| — | — | Keine Abhangigkeiten (Foundation Slice) |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `TokenManager` | TypeScript Module | Slice 02, 03, 06 |
| `apolloClient` | Apollo Client Instance | Slice 02, 03, 06 |
| `ApolloWrapper` | React Component | Slice 02, 03, 04, 06 (via layout.tsx) |
| WPGraphQL Endpoint (`POST http://localhost:8080/graphql`) | HTTP API | Slice 02, 03, 06 |
| WooCommerce DB (wp_posts, wp_postmeta, etc.) | MySQL Schema | Slice 02, 03, 04, 05, 06, 07 |
| Docker Compose Stack | Infrastructure | Alle Slices |
| Tailwind v4 CSS Tokens (@theme) | CSS Design System | Slice 02, 03, 04, 06 |
| `NEXT_PUBLIC_WP_URL` / `NEXT_PUBLIC_GRAPHQL_URL` | Env Variables | Slice 02, 03, 04, 06, 07 |

---

### Slice 02: Produktkatalog Frontend

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Stack | typescript-nextjs |
| Dependencies | slice-01-infrastruktur |
| Test Command | `pnpm test tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `apolloClient` / ApolloWrapper | Slice 01 | APPROVED - exportiert aus `frontend/lib/apollo/client.ts` |
| `TokenManager` | Slice 01 | APPROVED - exportiert aus `frontend/lib/apollo/token-manager.ts` |
| WPGraphQL Endpoint | Slice 01 | APPROVED - `http://localhost:8080/graphql` |
| Tailwind v4 CSS Tokens | Slice 01 | APPROVED - `globals.css` @theme Tokens |
| `next.config.ts` remotePatterns | Slice 01 | APPROVED - remotePatterns fur localhost:8080 |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `AddToCartButton` | React Component | Slice 03 (modifiziert) |
| `ProductDetailData` Type | TypeScript Interface | Slice 03 |
| `ProductCardData` Type | TypeScript Interface | Slice 03, 06 |
| `ProductVariation` Type | TypeScript Interface | Slice 03 |
| `SelectedVariant` Type | TypeScript Interface | Slice 03 |
| `PRODUCT_CARD_FRAGMENT` | GraphQL Fragment | Slice 03 |
| `PRODUCT_DETAIL_FRAGMENT` | GraphQL Fragment | Slice 03 |
| `ProductVariantSelector` | React Component | Slice 03 (modifiziert) |
| `extractVariantOptions()` | TypeScript Function | Slice 03 |
| `findVariation()` | TypeScript Function | Slice 03 |
| `Header` Component | React Component | Slice 03 (modifiziert, CartIcon Badge) |
| `Footer` Component | React Component | Slice 04 (modifiziert, Legal Links) |
| `app/page.tsx` | Next.js Page | Slice 06 (modifiziert, page_visit Event) |
| `app/kategorie/[slug]/page.tsx` | Next.js Page | Slice 06 (modifiziert, view_category Event) |
| `app/produkt/[slug]/page.tsx` | Next.js Page | Slice 03 (modifiziert, addToCart implementiert) |
| `mobile-menu.tsx` | React Component | Slice 07 (modifiziert, Mein Konto Link) |

---

### Slice 03: Warenkorb + Checkout-Redirect

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Stack | typescript-nextjs |
| Dependencies | slice-01-infrastruktur, slice-02-produktkatalog-frontend |
| Test Command | `pnpm test tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `TokenManager` | Slice 01 | APPROVED - genutzt in `checkout-redirect.ts` |
| `apolloClient` / ApolloWrapper | Slice 01 | APPROVED - CartProvider nutzt useMutation/useQuery |
| `AddToCartButton` | Slice 02 | APPROVED - MODIFIZIERT: isSuccess State hinzugefugt |
| `ProductDetailData` Type | Slice 02 | APPROVED - genutzt in product-variant-selector.tsx |
| `ProductVariation` / `SelectedVariant` Type | Slice 02 | APPROVED - genutzt in product-variant-selector.tsx |
| `ProductVariantSelector` | Slice 02 | APPROVED - MODIFIZIERT: useCart() + handleAddToCart |
| `Header` Component | Slice 02 | APPROVED - MODIFIZIERT: CartIcon Badge integriert |
| `findVariation()` | Slice 02 | APPROVED - genutzt in product-variant-selector.tsx |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `CartContext` / `useCart()` | React Context Hook | Slice 04, 05, 06, 07 |
| `CartProvider` | React Component | Alle spateren Slices (via app/layout.tsx) |
| `CartItem` Interface | TypeScript Interface | Slice 06 |
| `CartContextValue` Interface | TypeScript Interface | Slice 06 |
| WooCommerce Bestellsystem (`order_status_processing`) | WordPress Event Hook | Slice 04, 05 |
| WooCommerce Order Complete (`order_status_completed`) | WordPress Event Hook | Slice 06 |
| `checkoutRedirect()` | TypeScript Function | Slice 06 (MODIFIZIERT: event_id ubergeben) |
| `app/layout.tsx` (CartProvider eingebunden) | Next.js Layout | Slice 04, 06 (modifiziert) |

---

### Slice 04: Rechtliches + Rechnungen

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Stack | typescript-nextjs |
| Dependencies | slice-01-infrastruktur, slice-02-produktkatalog-frontend, slice-03-warenkorb-checkout-redirect |
| Test Command | `pnpm test tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| Docker Compose Stack | Slice 01 | APPROVED - WordPress unter localhost:8080 |
| WooCommerce Grundkonfiguration (Tax deaktiviert) | Slice 01 | APPROVED - bereits in Slice 1 konfiguriert |
| Tailwind CSS @theme Tokens | Slice 01 | APPROVED - fur Animation-Token erganzung |
| `Footer` Component (zu modifizieren) | Slice 02 | APPROVED - Legal Links hinzugefugt |
| WooCommerce Bestellsystem (`order_status_processing`) | Slice 03 | APPROVED - Faktur Pro Hook auf diesen Trigger |
| `app/layout.tsx` (CartProvider vorhanden) | Slice 03 | APPROVED - CookieConsentBanner eingebunden |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `isConsentAccepted()` | TypeScript Function | Slice 06 |
| `getConsentState()` | TypeScript Function | Slice 06 |
| `CONSENT_KEY` (`"cookie-consent"`) | TypeScript Constant | Slice 06 |
| `ConsentState` Type | TypeScript Type | Slice 06 |
| `cookie-consent` localStorage Flag | Browser Storage | Slice 06 (Pinterest Tag Consent Gate) |
| `cookie-consent-accepted` Custom Event | Browser Event | Slice 06 (PinterestTagInit Listener) |
| WordPress Legal Pages (/impressum, /agb, /datenschutz, /widerruf) | WordPress Pages | Footer (alle Slices), WooCommerce Checkout |
| Faktur Pro PDF-Rechnungen | WordPress Integration | WooCommerce E-Mail (kein Slice-Consumer) |

---

### Slice 05: POD-Anbindung (Spreadconnect)

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Stack | php-wordpress |
| Dependencies | slice-01-infrastruktur, slice-03-warenkorb-checkout-redirect |
| Test Command | `php vendor/bin/phpunit tests/slices/pod-shop-mvp/slice-05-pod-anbindung-spreadconnect.php --testdox` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| Docker Compose Stack (WordPress + WooCommerce) | Slice 01 | APPROVED - wordpress:6.9-php8.2-apache |
| WooCommerce Bestellsystem (`order_status_processing`) | Slice 03 | APPROVED - Hook: `woocommerce_order_status_processing` |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `_spreadconnect_order_id` | WooCommerce Order Meta | Kein Slice-Consumer (internes Plugin-State) |
| `_spreadconnect_tracking_number` | WooCommerce Order Meta | Kein Slice-Consumer (WooCommerce E-Mail) |
| `woocommerce_order_status_completed` (durch apply_tracking) | WordPress Hook (gefeuert) | Slice 06 (indirekt via Hook) |
| REST Endpoint `GET /wp-json/spreadconnect/v1/health` | WordPress REST API | Health-Check |
| REST Endpoint `POST /wp-json/spreadconnect/v1/webhook` | WordPress REST API | Spreadconnect (extern) |

---

### Slice 06: Pinterest Tracking

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Stack | typescript-nextjs + php-wordpress |
| Dependencies | slice-01-infrastruktur, slice-02-produktkatalog-frontend, slice-03-warenkorb-checkout-redirect, slice-04-rechtliches-rechnungen |
| Test Command (Frontend) | `pnpm test tests/slices/pod-shop-mvp/slice-06-pinterest-tracking.test.ts` |
| Test Command (PHP) | `cd wordpress/plugins/pinterest-capi && ./vendor/bin/phpunit tests/ --testdox` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `TokenManager` | Slice 01 | APPROVED - via vi.spyOn(TokenManager, 'getToken') in Tests |
| `app/page.tsx` | Slice 02 | APPROVED - MODIFIZIERT: page_visit Event |
| `app/kategorie/[slug]/page.tsx` | Slice 02 | APPROVED - MODIFIZIERT: view_category Event |
| `CartContext` (addToCart Hook) | Slice 03 | APPROVED - MODIFIZIERT: add_to_cart Event nach addToCart |
| `checkoutRedirect()` | Slice 03 | APPROVED - MODIFIZIERT: pinterest_event_id URL-Parameter |
| `app/layout.tsx` | Slice 03 | APPROVED - MODIFIZIERT: PinterestTagInit eingebunden |
| WooCommerce `order_status_completed` Hook | Slice 05 (indirekt) | APPROVED - CAPI purchase Event bei Order Complete |
| `cookie-consent` localStorage Flag | Slice 04 | APPROVED - Consent Gate fur Pinterest Tag |
| `cookie-consent-accepted` Custom Event | Slice 04 | APPROVED - PinterestTagInit Listener |
| `isConsentAccepted()` / `CONSENT_KEY` | Slice 04 | APPROVED - exportiert aus cookie-consent.ts |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `_pinterest_event_id` | WooCommerce Order Meta | CAPI purchase Event (interne Verwendung) |
| Pinterest CAPI purchase Events | External API | Pinterest (kein Slice-Consumer) |
| Pinterest Tag page_visit / view_category / add_to_cart Events | External (Browser) | Pinterest (kein Slice-Consumer) |

---

### Slice 07: User Accounts

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Stack | typescript-nextjs |
| Dependencies | slice-01-infrastruktur, slice-02-produktkatalog-frontend, slice-03-warenkorb-checkout-redirect |
| Test Command | `pnpm test tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `NEXT_PUBLIC_WP_URL` Env Variable | Slice 01 | APPROVED - in frontend/.env.local gesetzt |
| `mobile-menu.tsx` | Slice 02 | APPROVED - MODIFIZIERT: Mein Konto Link aktiviert |
| `Footer` Component | Slice 02 | APPROVED - bereits enthalt Mein Konto Link (durch Slice 04 Legal Links Modifikation) |
| WooCommerce Checkout Flow | Slice 03 | APPROVED - Bestellungen im Account sichtbar |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| — | — | Letzter Slice, keine weiteren Consumers |

---

## Connections

| # | From | To | Resource | Type | Status |
|---|------|----|----------|------|--------|
| 1 | Slice 01 | Slice 02 | `apolloClient` + `ApolloWrapper` | TypeScript Module + React Component | VALID |
| 2 | Slice 01 | Slice 02 | `TokenManager` | TypeScript Module | VALID |
| 3 | Slice 01 | Slice 02 | WPGraphQL Endpoint | HTTP API | VALID |
| 4 | Slice 01 | Slice 02 | Tailwind v4 CSS Tokens | CSS Design System | VALID |
| 5 | Slice 01 | Slice 03 | `TokenManager` | TypeScript Module | VALID |
| 6 | Slice 01 | Slice 03 | `apolloClient` / ApolloWrapper | TypeScript Module + React Component | VALID |
| 7 | Slice 02 | Slice 03 | `AddToCartButton` | React Component (modifiziert) | VALID |
| 8 | Slice 02 | Slice 03 | `ProductDetailData` / `ProductVariation` / `SelectedVariant` Types | TypeScript Interfaces | VALID |
| 9 | Slice 02 | Slice 03 | `ProductVariantSelector` | React Component (modifiziert) | VALID |
| 10 | Slice 02 | Slice 03 | `Header` Component | React Component (modifiziert) | VALID |
| 11 | Slice 02 | Slice 03 | `findVariation()` | TypeScript Function | VALID |
| 12 | Slice 03 | Slice 04 | WooCommerce Bestellsystem (`order_status_processing`) | WordPress Event Hook | VALID |
| 13 | Slice 03 | Slice 05 | WooCommerce Bestellsystem (`order_status_processing`) | WordPress Event Hook | VALID |
| 14 | Slice 03 | Slice 06 | `CartContext` (add_to_cart event_id) + `checkoutRedirect()` | React Context + TypeScript Function | VALID |
| 15 | Slice 03 | Slice 06 | WooCommerce Order Complete (`order_status_completed`) | WordPress Event Hook | VALID |
| 16 | Slice 03 | Slice 07 | WooCommerce Checkout Flow | WooCommerce Integration | VALID |
| 17 | Slice 04 | Slice 06 | `cookie-consent` localStorage Flag + `cookie-consent-accepted` Custom Event | Browser Storage + DOM Event | VALID |
| 18 | Slice 02 | Slice 07 | `mobile-menu.tsx` + `Footer` Component | React Components (modifiziert) | VALID |

---

## Validation Results

### Valid Connections: 18

Alle deklarierten Dependencies haben passende Outputs in den Vorgangerslices.

### Orphaned Outputs: 0

Alle Outputs werden von mindestens einem Consumer genutzt oder sind finale User-facing Outputs (Rechnungen, E-Mails, Pinterest Events).

**Erlauterte Outputs ohne direkte Slice-Consumer (akzeptiert):**

| Output | Defined In | Konsumiert Durch | Begruendung |
|--------|------------|------------------|-------------|
| Faktur Pro PDF-Rechnungen | Slice 04 | WooCommerce E-Mail-System | Finales User-facing Output |
| Pinterest Tag Events (page_visit etc.) | Slice 06 | Pinterest Plattform (extern) | Finales Tracking-Output |
| Pinterest CAPI purchase Event | Slice 06 | Pinterest API (extern) | Finales Tracking-Output |
| `_spreadconnect_order_id` Post Meta | Slice 05 | WooCommerce Admin (intern) | Internes Plugin-State |

### Missing Inputs: 0

Kein Input ohne passenden Producer in einem vorherigen APPROVED Slice.

### Deliverable-Consumer Gaps: 0

Alle Deliverable-Consumer-Verbindungen sind gepruft:

| Component | Defined In | Consumer File | In Deliverables? | Status |
|-----------|------------|---------------|------------------|--------|
| `ApolloWrapper` | Slice 01 | `frontend/app/layout.tsx` | Ja - Slice 01 Deliverable | VALID |
| `Header` (mit CartIcon) | Slice 03 | `frontend/components/layout/header.tsx` | Ja - Slice 03 "MODIFIZIERT" Deliverable | VALID |
| `CartProvider` | Slice 03 | `frontend/app/layout.tsx` | Ja - Slice 03 "MODIFIZIERT" Deliverable | VALID |
| `CookieConsentBanner` | Slice 04 | `frontend/app/layout.tsx` | Ja - Slice 04 "MODIFIZIERT" Deliverable | VALID |
| `PinterestTagInit` | Slice 06 | `frontend/app/layout.tsx` | Ja - Slice 06 "MODIFIZIERT" Deliverable | VALID |
| `mobile-menu.tsx` (Mein Konto) | Slice 07 | `frontend/components/layout/mobile-menu.tsx` | Ja - Slice 07 "Modifikation" Deliverable | VALID |

**Hinweis: app/layout.tsx Akkumulation**

`frontend/app/layout.tsx` wird von 4 Slices (01, 03, 04, 06) akkumulativ modifiziert. Jeder spatere Slice erweitert die Datei. Der Orchestrator muss sicherstellen, dass die finale Version alle Modifikationen enthalt:

```
Slice 01: ApolloWrapper (Root Provider)
Slice 03: + CartProvider (innerhalb ApolloWrapper)
Slice 04: + CookieConsentBanner (nach {children} im CartProvider)
Slice 06: + PinterestTagInit (nach CookieConsentBanner, OHNE CookieConsentBanner - separate Layout-Version)
```

**Anmerkung Slice 04 vs. Slice 06 layout.tsx-Konflikt:**
Slice 04 hat `CookieConsentBanner` im layout.tsx, Slice 06 hat eine alternative layout.tsx-Version OHNE `CookieConsentBanner` aber MIT `PinterestTagInit`. Der Orchestrator muss die finale version mit BEIDEN Komponenten erstellen:

```typescript
// Finale app/layout.tsx (nach Slice 06):
// ApolloWrapper > CartProvider > {children} + CookieConsentBanner + PinterestTagInit
```

---

## Discovery Traceability

### UI Components Coverage

| Discovery Element | Type | Covered In | Status |
|-------------------|------|------------|--------|
| Produkt-Card (Default, Hover, Loading/Skeleton, Sold Out) | React Component | Slice 02 | COVERED |
| Varianten-Selector (Grosse - Verfugbar/Ausgewahlt/Nicht verfugbar) | React Component | Slice 02 | COVERED |
| Varianten-Selector (Farbe - Verfugbar/Ausgewahlt/Nicht verfugbar/Ring) | React Component | Slice 02 | COVERED |
| "In den Warenkorb"-Button (Default, Loading, Erfolg, Disabled) | React Component | Slice 02 + Slice 03 | COVERED |
| Warenkorb-Icon Header (Leer, Mit Anzahl-Badge) | React Component | Slice 03 | COVERED |
| Mengen-Steuerung Warenkorb (+/-, Min 1, Preis aktualisiert) | React Component | Slice 03 | COVERED |
| "Entfernen"-Button Warenkorb | React Component | Slice 03 | COVERED |
| "Zur Kasse"-Button (Default, Disabled wenn leer) | React Component | Slice 03 | COVERED |
| Cookie-Banner (Sichtbar erster Besuch, Versteckt nach Entscheidung) | React Component | Slice 04 | COVERED |

**UI Components Coverage: 9/9 (100%)**

### State Machine Coverage

| State | Required UI | Available Actions | Covered In | Status |
|-------|-------------|-------------------|------------|--------|
| Bestellstatus: Pending | WooCommerce Checkout | Zahlung auslosen | Slice 03 (Mollie) | COVERED |
| Bestellstatus: Failed | WooCommerce Error | Retry | Slice 03 (Mollie) | COVERED |
| Bestellstatus: Processing | WooCommerce Bestatigung | Faktur Pro PDF, Spreadconnect | Slice 04, 05 | COVERED |
| Bestellstatus: On Hold | WooCommerce Admin | Warte auf Spreadconnect | Slice 05 | COVERED |
| Bestellstatus: Completed | WooCommerce E-Mail | Tracking-Link | Slice 05, 06 | COVERED |
| Bestellstatus: Refunded | Manuell | Faktur Pro Gutschrift | Slice 04 | PARTIAL (Gutschrift nicht explizit spezifiziert - Faktur Pro built-in) |
| Warenkorb: Leer | "Warenkorb leer" + Link | Weiter shoppen | Slice 03 | COVERED |
| Warenkorb: Mit Produkten (Add to Cart) | Badge, Bestatigung | Menge andern, Entfernen, Zur Kasse | Slice 03 | COVERED |
| Warenkorb: Mit Produkten (Menge andern) | Preis aktualisiert | +/- Buttons | Slice 03 | COVERED |
| Warenkorb: Mit Produkten (Entfernen letztes) | Leer-State | - | Slice 03 | COVERED |
| Warenkorb: Mit Produkten (Zur Kasse) | Redirect | - | Slice 03 | COVERED |

**State Machine Coverage: 10/11 (91%) - 1 Partial (Refunded - Faktur Pro built-in, akzeptiert)**

### Transitions Coverage

| From | Trigger | To | Covered In | Status |
|------|---------|-----|------------|--------|
| Produktdetail → Warenkorb | "In den Warenkorb" Klick | Warenkorb mit Produkt | Slice 03 | COVERED |
| Warenkorb leer → Mit Produkten | addToCart | Badge Update | Slice 03 | COVERED |
| Mit Produkten → Redirect | "Zur Kasse" | WooCommerce Checkout | Slice 03 | COVERED |
| WooCommerce Checkout → Processing | Zahlung erfolgreich (Mollie) | order_status_processing | Slice 03 | COVERED |
| Processing → Spreadconnect | order_status_processing Hook | Bestellweiterleitung | Slice 05 | COVERED |
| Spreadconnect → Completed | Tracking-Nummer empfangen | order_status_completed | Slice 05 | COVERED |
| Completed → Pinterest CAPI | order_status_completed | purchase Event | Slice 06 | COVERED |
| Erster Besuch → Cookie Banner | Kein localStorage Flag | Banner sichtbar | Slice 04 | COVERED |
| Cookie Banner → Consent accepted | "Alle Akzeptieren" Klick | Pinterest Tag aktiv | Slice 04, 06 | COVERED |
| Cookie Banner → Consent rejected | "Nur Notwendige" Klick | Pinterest Tag inaktiv | Slice 04, 06 | COVERED |

**Transitions Coverage: 10/10 (100%)**

### Business Rules Coverage

| Rule | Covered In | Status |
|------|------------|--------|
| Kleinunternehmerregelung: Keine MwSt ausweisen | Slice 01 (WooCommerce Tax deaktiviert) | COVERED |
| §19 UStG Hinweis auf Rechnungen: "Gemaß §19 UStG wird keine Umsatzsteuer berechnet." | Slice 04 (Faktur Pro Template) | COVERED |
| §19 UStG Hinweis im Warenkorb unter Gesamtpreis | Slice 03 (CartSummary exakter Text) | COVERED |
| Versandkosten: separate Zeile uber Gesamtpreis im Warenkorb | Slice 03 (CartSummary) | COVERED |
| Versandkosten: "inkl. Versandkosten" Hinweis auf Produktdetailseite | Slice 02 (Produktdetailseite) | COVERED |
| Versandkosten: Konfiguration via WooCommerce Versandzone DE | Slice 01 (WooCommerce Grundkonfig) | COVERED |
| Widerrufsrecht: 14 Tage, Seite verlinkt im Checkout | Slice 04 (WooCommerce-Integration Widerruf-Seite) | COVERED |
| Cookie Consent: Pinterest Tag erst nach Consent | Slice 04 (localStorage Flag) + Slice 06 (Consent Gate) | COVERED |
| CAPI: serverseitig und consent-unabhangig | Slice 06 (PHP Plugin, kein Consent-Check) | COVERED |
| Produkt-Verfugbarkeit: Print-on-Demand, immer verfugbar | Slice 01 (Lager deaktiviert) | COVERED |
| Bestellweiterleitung: automatisch an Spreadconnect | Slice 05 (woocommerce_order_status_processing Hook) | COVERED |
| Keine manuelle Bearbeitung der Bestellweiterleitung | Slice 05 (automatischer Hook) | COVERED |
| Rechnungsnummern fortlaufend, GoBD-konform | Slice 04 (Faktur Pro Nummernsystem) | COVERED |

**Business Rules Coverage: 13/13 (100%)**

### Data Fields Coverage

| Field | Required | Covered In | Status |
|-------|----------|------------|--------|
| Produkt: Name | Ja | Slice 02 (ProductDetailData.name) | COVERED |
| Produkt: Beschreibung | Ja | Slice 02 (ProductDetailData.description) | COVERED |
| Produkt: Preis | Ja | Slice 02 (ProductDetailData.price) | COVERED |
| Produkt: Bilder | Ja | Slice 02 (ProductDetailData.image + galleryImages) | COVERED |
| Produkt: Kategorien | Ja | Slice 02 (ProductDetailData.productCategories) | COVERED |
| Produkt: Varianten Grosse | Ja | Slice 02 (ProductVariation Attribut pa_size) | COVERED |
| Produkt: Varianten Farbe | Ja | Slice 02 (ProductVariation Attribut pa_color) | COVERED |
| Produkt: Spreadconnect Produkt-ID | Ja | Slice 05 (_spreadconnect_article_id Custom Meta) | COVERED |
| Bestellung: Vorname / Nachname | Ja | Slice 03 (WooCommerce Checkout) | COVERED |
| Bestellung: E-Mail | Ja | Slice 03 (WooCommerce Checkout) | COVERED |
| Bestellung: Strase + Hausnummer | Ja | Slice 03 (WooCommerce Checkout) | COVERED |
| Bestellung: PLZ / Ort / Land | Ja | Slice 03 (WooCommerce Checkout) | COVERED |
| Bestellung: Zahlungsart (Mollie) | Ja | Slice 03 (Mollie Plugin) | COVERED |
| Warenkorb: CartItem.key | Ja | Slice 03 (CartItem Interface) | COVERED |
| Warenkorb: CartItem.productId / variationId / quantity | Ja | Slice 03 (CartItem Interface) | COVERED |
| Warenkorb: CartState.shippingTotal / total / itemCount | Ja | Slice 03 (CartState Interface) | COVERED |

**Data Fields Coverage: 16/16 (100%)**

---

## Discovery Coverage Summary

| Bereich | Abgedeckt | Gesamt | Prozentsatz |
|---------|-----------|--------|-------------|
| UI Components | 9 | 9 | 100% |
| State Machine | 10 | 11 | 91% (1 Partial akzeptiert) |
| Transitions | 10 | 10 | 100% |
| Business Rules | 13 | 13 | 100% |
| Data Fields | 16 | 16 | 100% |
| **Gesamt** | **58** | **59** | **98.3%** |

**Einzige Partial Coverage:** Bestellstatus "Refunded" → Faktur Pro Gutschrift ist in Discovery als Bestellstatus genannt, wird aber im Slice nicht explizit als eigener Gutschrift-Flow implementiert. Da Faktur Pro die Gutschrift-Erstellung built-in unterstutzt und als "manuell" klassifiziert ist, ist dies akzeptiert.

---

## Summary

| Metric | Value |
|--------|-------|
| Total Slices | 7 |
| Approved Slices | 7 |
| Total Connections | 18 |
| Valid Connections | 18 |
| Missing Inputs | 0 |
| Orphaned Outputs | 0 |
| Deliverable-Consumer Gaps | 0 |
| Discovery Coverage | 98.3% |

**Kritischer Hinweis: app/layout.tsx Akkumulations-Reihenfolge**

Der Orchestrator muss sicherstellen, dass `frontend/app/layout.tsx` nach der Implementierung aller Slices folgende endgultige Struktur hat:

```typescript
// Finales layout.tsx (nach Slice 01, 03, 04, 06):
<ApolloWrapper>          {/* Slice 01 */}
  <CartProvider>         {/* Slice 03 */}
    {children}
    <CookieConsentBanner /> {/* Slice 04 */}
    <PinterestTagInit />    {/* Slice 06 */}
  </CartProvider>
</ApolloWrapper>
```

---

## Verdict

**VERDICT: READY FOR ORCHESTRATION**

Alle 7 Slices sind APPROVED. Alle 18 Connections sind valide. Keine Missing Inputs, keine Orphaned Outputs, keine Deliverable-Consumer Gaps. Discovery Coverage: 98.3% (1 akzeptierte Partial Coverage).
