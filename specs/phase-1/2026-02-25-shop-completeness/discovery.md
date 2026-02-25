# Feature: Shop Completeness — Blocks & Pages

**Epic:** Phase 1 — Shop verkaufsbereit machen
**Status:** Ready
**Wireframes:** `wireframes.md` (optional, same folder)

---

## Problem & Solution

**Problem:**
- 11 Block-Typen + 5 Page-Routes existieren — aber kritische Elemente fehlen für einen vollständigen, verkaufsreifen Shop
- Keine Pagination → Kategorie-Seiten zeigen immer nur erste 24 Produkte (kein Scale)
- Keine Produktbewertungen → kein Social Proof → niedrigere Conversion Rate
- Keine Suche → User mit konkretem Kaufwunsch verlassen den Shop
- Kein 404 → broken links wirken unprofessionell
- Keine Collections → keine thematische Kuration (Weihnachten, Sale, Neuheiten)
- Kein Cross-sell → niedrigerer durchschnittlicher Bestellwert (AOV)
- Announcement Bar fehlt → Promotions, Versandkonditionen nicht kommunizierbar

**Solution:**
- 15 neue Block-Typen implementieren (Homepage, Produkt, Kategorie, Cross-Page)
- 4 neue Page-Routes ergänzen (Suche, Collections, Danke, 404)
- Alle Blöcke YAML-konfigurierbar nach bestehendem Block-System-Pattern

**Business Value:**
- Launch-Readiness: Shop erfüllt Mindestanforderungen für echte Kunden
- Conversion +15-30% durch Social Proof (Reviews, Trust Badges)
- AOV +20-35% durch Cross-sell / Product Recommendations
- Bounce Rate -20% durch Suche und Collections-Navigation
- SEO: Klassische Pagination indexierbar (vs. infinite scroll)

---

## Scope & Boundaries

| In Scope |
|----------|
| 15 neue Block-Typen (YAML-konfigurierbar, Block-System-konform) |
| 4 neue Page-Routes: `/suche`, `/kollektion/[slug]`, `/danke`, `not-found` |
| Erweiterung bestehender Pages per YAML (keine Code-Änderungen an bestehenden Blocks) |
| Suche via WooCommerce/WPGraphQL (kein Meilisearch) |
| Produktbewertungen via WooCommerce (Sterne-Summary + Liste + Formular) |
| Product Recommendations Block (konfigurierbare Quelle + Überschrift) |
| Klassische Seitenpagination (SEO-kompatibel) |
| Collections via WooCommerce Product Tags oder Kategorie |
| Order-Bestätigungsseite (`/danke`) mit URL-Param-Bestellnummer |

| Out of Scope |
|--------------|
| Meilisearch / externe Suchinfrastruktur |
| Blog / Content-Pages |
| Wishlist / Merkliste |
| Recently Viewed Products (localStorage-basiert) |
| Countdown-Timer / Flash Sales |
| Instagram / Social Feed Block |
| User Accounts (Slice 7, separates Feature) |
| Bewertungs-Import von externen Plattformen (Trustpilot etc.) |
| Facetten-Filter auf Kategorieseite (Farbe, Größe, Preis) |

---

## Current State Reference

> Existierende Funktionalität die wiederverwendet wird — NICHT nochmal dokumentiert.

- Block-System: `lib/blocks/registry.ts`, `lib/blocks/page-config.ts`, `lib/blocks/section-renderer.tsx`
- `BlockComponentProps<T>` Interface mit `content_source` + `params` aus YAML
- Skeleton-Pattern für Suspense: alle async Blocks exportieren `<BlockSkeleton />`
- Theming-Token-System: `bg-primary`, `text-text-primary`, `rounded-card` etc.
- Apollo Server-Client für RSC (`lib/apollo/server-client.ts`)
- Apollo Client für Client-Components (`lib/apollo/client.ts`)
- WooCommerce GraphQL Queries in `lib/graphql/queries.ts`
- `product-grid` Block (existiert, wird in Recommendations wiederverwendet)
- `page-heading` Block (existiert, wird auf Collections-Page wiederverwendet)
- `filter-chips` Block (existiert, wird auf Collections-Page wiederverwendet)

---

## UI Patterns

### Reused Patterns

| Pattern Type | Component | Usage in this Feature |
|---|---|---|
| Block-Pattern | `components/blocks/*.tsx` | Alle neuen Blocks folgen exakt demselben Muster |
| Skeleton-Pattern | `BlockSkeleton` Export | Alle neuen async Blocks exportieren Skeleton |
| YAML-Konfiguration | `themes/default/pages/*.yaml` | Alle neuen Blöcke sind YAML-konfigurierbar |
| `BlockComponentProps<T>` | `lib/blocks/types.ts` | Typen-Interface für alle neuen Blocks |
| Apollo RSC Query | `server-client.ts` | Für alle server-seitigen Blocks |

### New Patterns

| Pattern Type | Description | Rationale |
|---|---|---|
| Konfigurierbare Block-Quelle | Block-Typ ist gleich, Datenquelle (related/category/custom) via YAML-Param | Maximale Flexibilität ohne neue Block-Typen pro Usecase |
| Globaler YAML-Block | `announcement-bar` erscheint in `global.yaml` und wird in alle Pages injiziert | Einmalige Konfiguration, überall wirksam |
| URL-Param-Page | `/danke?order=12345` liest Bestellnummer aus URL-Query-Param | WooCommerce Redirect sendet Bestelldaten als URL-Params |
| Client-Side Search | `search-bar` Block ist Client-Component (Input), `search-results` ist Server-Component | Input-Interaktion Client-seitig, Datenabruf Server-seitig (mit `router.push`) |

---

## User Flow

### Suche

1. User tippt in `search-bar` auf der `/suche`-Page oder in der Navbar-Suche
2. System aktualisiert URL: `/suche?q=shirt`
3. `search-results` Block lädt Produkte via WooGraphQL `search`-Query
4. User sieht Produkt-Grid mit Treffer-Anzahl
5. User klickt auf Produkt → `/produkt/[slug]`
6. **Keine Ergebnisse:** `empty-state` Block wird gezeigt mit Vorschlägen

**Error Paths:**
- GraphQL-Fehler bei Suche → `empty-state` mit Text "Suche momentan nicht verfügbar"
- Leere Query (`?q=`) → Redirect zu Startseite oder Anzeige von Bestsellern

### Produktbewertungen

1. User scrollt auf Produktseite zu Review-Sektion
2. `product-reviews` Block zeigt: Sterne-Durchschnitt, Anzahl Bewertungen, Review-Liste
3. User klickt "Bewertung schreiben"
4. Bewertungsformular erscheint unterhalb (kein Modal): Name, E-Mail, Sterne-Auswahl, Text
5. User klickt "Absenden"
6. WooCommerce speichert Review (Status: pending)
7. Erfolgsmeldung: "Danke für deine Bewertung! Sie wird nach Prüfung angezeigt."

**Error Paths:**
- Pflichtfelder leer → Inline-Validierungsfehler pro Feld
- GraphQL-Fehler → Toast "Bewertung konnte nicht gespeichert werden"

### Product Recommendations

1. User ist auf einer Produktdetail-Seite
2. `product-recommendations` Block lädt basierend auf YAML-Param `source: related | category | custom`
3. User sieht horizontales Grid mit 4 Produkt-Cards
4. User klickt auf Produkt → `/produkt/[slug]`

### Collections

1. User klickt auf Collection-Link (in Navigation, Homepage-Banner, etc.)
2. `/kollektion/[slug]` lädt `collection-header` (Bild + Titel + Beschreibung) + `product-grid` + `pagination`
3. User navigiert durch Seiten via `pagination` Block
4. User klickt auf Produkt → `/produkt/[slug]`

### Order-Bestätigung

1. WooCommerce Checkout abgeschlossen → Redirect zu `/danke?order_id=12345&key=abc`
2. `order-confirmation` Block liest URL-Params
3. User sieht: Bestellnummer, Bestätigungstext, E-Mail-Hinweis, Link zurück zum Shop
4. (Optional) Pinterest CAPI-Event `checkout` wird ausgelöst (Deduplication via event_id)

---

## UI Layout & Context

### Screen: Announcement Bar (Global)
**Position:** Oberhalb der Navbar, auf allen Pages (wenn konfiguriert)
**When:** Wenn `global.yaml` → `announcement-bar` Block konfiguriert ist

**Layout:**
- Schmaler Balken (volle Breite), Hintergrund: `bg-primary` oder YAML-konfigurierbares Theme-Token
- Text zentriert: z.B. "Kostenloser Versand ab 100€ | Code: SOMMER10"
- Optional: Link (gesamte Bar klickbar) oder X-Button (schliessen, localStorage)

### Screen: Produktdetail-Page (erweitert)
**Position:** Unterhalb bestehender Blocks (`product-gallery`, `product-purchase`, `product-description`)
**When:** Immer

**Layout (neu hinzugefügte Sections):**
- Section: `trust-badges` — horizontale Icons-Leiste: [Truck Icon] Versand in 3-5 Tagen | [Shield] 30 Tage Rückgabe | [Lock] Sichere Zahlung
- Section: `product-reviews` — Sterne-Zusammenfassung oben (4.2★ · 47 Bewertungen) + Liste + Formular
- Section: `product-recommendations` — Überschrift (YAML) + horizontal scrollbares Produkt-Grid (4 Cards Desktop, 2 Mobile)

### Screen: Kategorie-Page (erweitert)
**Position:** Neue Blocks vor und nach bestehendem Grid

**Layout (neu):**
- Oberhalb page-heading: `breadcrumb` — Home > Kategorie-Name
- Zwischen product-count und product-grid: `sort-bar` — "Sortieren nach: [Dropdown]"
- Unterhalb product-grid: `pagination` — ‹ 1 2 3 … 8 ›
- Wenn 0 Produkte: `empty-state` ersetzt product-grid

### Screen: Suchseite `/suche`
**Position:** Eigenständige Page

**Layout:**
- Section 1: `search-bar` — Großes Suchfeld mit Lupe + Placeholder "Was suchst du?"
- Section 2: `product-count` (wiederverwendet) — "47 Ergebnisse für 'shirt'"
- Section 3: `sort-bar` — Sortierung
- Section 4: `search-results` (= product-grid mit search-Quelle)
- Section 5: `pagination`
- Wenn keine Ergebnisse: Section 2-5 ersetzt durch `empty-state`

### Screen: Collections-Page `/kollektion/[slug]`
**Position:** Eigenständige Page

**Layout:**
- Section 1: `collection-header` — Fullwidth-Bild (optional) + Titel + Beschreibung (aus WooCommerce Kategorie-Beschreibung)
- Section 2: `product-count`
- Section 3: `product-grid`
- Section 4: `pagination`

### Screen: Danke-Page `/danke`
**Position:** Eigenständige Page, noindex

**Layout:**
- Zentrierte Card: Checkmark-Icon, "Vielen Dank für deine Bestellung!"
- Bestellnummer: #12345
- Text: "Deine Bestellbestätigung wurde an deine E-Mail-Adresse gesendet."
- CTA-Button: "Weiter einkaufen" → `/`

### Screen: 404 Page
**Position:** Eigenständige Page

**Layout:**
- Großes "404" oder Illustration
- Headline: "Diese Seite wurde nicht gefunden"
- Text: "Der Link ist möglicherweise abgelaufen oder die Seite wurde verschoben."
- CTA: "Zurück zur Startseite" + "Zum Shop"

### Screen: Homepage (erweitert)
**Position:** Neue optionale Sections

**Neue Blocks (YAML-konfigurierbar, Reihenfolge frei):**
- `announcement-bar` — Oben (global)
- `testimonials` — Kundenzitate mit Sterne
- `newsletter-signup` — E-Mail-Eingabe mit CTA
- `featured-collection` — Bild + Titel + 4 Produkte einer Kategorie

---

## UI Components & States

| Element | Type | Location | States | Behavior |
|---|---|---|---|---|
| `announcement-bar` | Bar | Global/Top | `visible`, `dismissed` | Klick auf X → dismissed (localStorage), Link klickbar |
| `sort-dropdown` | Select | sort-bar | `closed`, `open` | Auswahl → URL-Param `?sort=price_asc` → Page re-render |
| `pagination-prev` | Button | pagination | `enabled`, `disabled` | Disabled auf Seite 1; Klick → `?page=N-1` |
| `pagination-next` | Button | pagination | `enabled`, `disabled` | Disabled auf letzter Seite; Klick → `?page=N+1` |
| `pagination-number` | Button | pagination | `default`, `active` | Active = aktuelle Seite (nicht klickbar); Klick → `?page=N` |
| `review-stars-input` | Rating | review-form | `1-5 stars selected` | Klick auf Stern → Wert setzen |
| `review-submit` | Button | review-form | `idle`, `loading`, `success`, `error` | Loading während Mutation; Success → Formular versteckt, Danke-Text |
| `search-input` | Input | search-bar | `empty`, `typing`, `searching` | Enter/Button-Klick → `router.push('/suche?q=...')` |
| `search-clear` | Button | search-bar | `hidden` (empty), `visible` (typing) | Klick → Input leeren + URL zurücksetzen |
| `empty-state-suggestions` | Link-List | empty-state | `static` | Links zu Top-Kategorien (YAML-konfigurierbar) |
| `trust-badge-item` | Icon+Text | trust-badges | `static` | Keine Interaktion, nur Display |
| `recommendations-card` | ProductCard | product-recommendations | `default`, `hover` | Hover → Shadow; Klick → `/produkt/[slug]` |
| `newsletter-email` | Input | newsletter-signup | `empty`, `valid`, `invalid`, `submitted` | Submit → WC Customer API oder Mailchimp-Webhook (Out of Scope: nur UI) |

---

## Feature State Machine

### Sort-Bar State Machine

| State | UI | Available Actions |
|---|---|---|
| `no_sort` | Dropdown: "Empfohlen" | Sortierung wählen |
| `sorted_price_asc` | Dropdown: "Preis: aufsteigend" | Andere Sortierung, Zurücksetzen |
| `sorted_price_desc` | Dropdown: "Preis: absteigend" | Andere Sortierung, Zurücksetzen |
| `sorted_newest` | Dropdown: "Neueste zuerst" | Andere Sortierung, Zurücksetzen |

| Current State | Trigger | UI Feedback | Next State | Business Rules |
|---|---|---|---|---|
| `no_sort` | Dropdown-Auswahl | Seite lädt neu | `sorted_*` | URL-Param `?sort=price_asc\|price_desc\|newest` |
| `sorted_*` | Andere Auswahl | Seite lädt neu | anderer `sorted_*` | URL-Param wird ersetzt |

### Review Form State Machine

| State | UI | Available Actions |
|---|---|---|
| `hidden` | Formular nicht sichtbar; Button "Bewertung schreiben" | Formular öffnen |
| `open` | Formular sichtbar: Name, E-Mail, Sterne, Text | Abschicken, Abbrechen |
| `loading` | Button disabled, Spinner | — |
| `success` | Danke-Text "Deine Bewertung wird geprüft" | Formular schließt sich |
| `error` | Toast-Fehler, Formular bleibt offen | Erneut versuchen |

| Current State | Trigger | UI Feedback | Next State | Business Rules |
|---|---|---|---|---|
| `hidden` | Click "Bewertung schreiben" | Formular slide-in | `open` | — |
| `open` | Click "Abschicken" (valide) | Button → Spinner | `loading` | Name, E-Mail, min. 1 Stern, min. 10 Zeichen Text |
| `open` | Click "Abschicken" (invalide) | Inline-Fehler pro Feld | `open` | Felder rot + Fehlermeldungen |
| `open` | Click "Abbrechen" | Formular slide-out | `hidden` | Eingaben verworfen |
| `loading` | Mutation erfolgreich | Formular slide-out + Danke-Text | `success` | — |
| `loading` | Mutation fehler | Toast "Fehler" | `error` | — |
| `error` | Click "Erneut versuchen" | Button → Spinner | `loading` | — |

### Announcement Bar State Machine

| State | UI | Available Actions |
|---|---|---|
| `visible` | Bar am Top | Schließen, Link klicken |
| `dismissed` | Bar ausgeblendet | — |

| Current State | Trigger | UI Feedback | Next State | Business Rules |
|---|---|---|---|---|
| `visible` | Click X | Bar fade-out | `dismissed` | `localStorage['announcement-dismissed'] = id` |
| (page load) | `localStorage` hat passende ID | Bar nie gezeigt | `dismissed` | ID = YAML-konfigurierbar (bei Textänderung neue ID) |

---

## Business Rules

- **Pagination:** URL-Param `?page=N` (1-indexed); ungültige Seiten → Redirect zu Seite 1
- **Sort:** URL-Param `?sort=price_asc|price_desc|newest`; kein Param = Default-Sortierung (WooCommerce)
- **Suche:** Min. 2 Zeichen für Suche; kürzere Queries → kein Request, Placeholder sichtbar
- **Reviews:** Bewertungen aller Produkte sind öffentlich lesbar (kein Login nötig); Review-Formular sichtbar auch ohne Login; WooCommerce sendet Review in Status "pending" (muss manuell freigegeben werden)
- **Reviews-Bewertungsskala:** 1-5 Sterne, Pflichtfeld
- **Announcement Bar ID:** Jede Bar-Instanz hat eine YAML-konfigurierbare `id`; gleiche ID = nicht erneut zeigen wenn dismissed
- **Collections:** `/kollektion/[slug]` nutzt WooCommerce Kategorie-Slug (oder Tag-Slug — Entscheidung bei Architecture)
- **Danke-Page:** `order_id` URL-Param ist optional; wenn fehlend → generischer Danke-Text ohne Bestellnummer
- **404:** Next.js `not-found.tsx` — automatisch für alle ungültigen Routes
- **Product Recommendations:** Wenn Quelle `related` keine Produkte zurückgibt → Fallback auf `category`; wenn auch leer → Block unsichtbar (kein leerer Container)
- **Newsletter Signup:** In diesem Feature: nur UI (Formular + Validierung); Backend-Integration (Mailchimp/WC-Kundenliste) ist Out of Scope

---

## Data

### Product Reviews

| Field | Required | Validation | Notes |
|---|---|---|---|
| `reviewer_name` | Yes | Min. 2 Zeichen | Freitext |
| `reviewer_email` | Yes | Valides E-Mail-Format | Wird nicht angezeigt |
| `rating` | Yes | 1–5 (Integer) | Sterne-Auswahl |
| `content` | Yes | Min. 10 Zeichen | Review-Text |
| `product_id` | Yes (auto) | WooCommerce Produkt-ID | Aus Route-Kontext |

### Search

| Field | Required | Validation | Notes |
|---|---|---|---|
| `q` (URL-Param) | No | Min. 2 Zeichen für Request | Leer = leere Ergebnisse oder Featured |
| `page` (URL-Param) | No | Integer ≥ 1 | Default: 1 |
| `sort` (URL-Param) | No | `price_asc\|price_desc\|newest` | Default: WC-Relevanzsortierung |

### Announcement Bar (YAML)

| Field | Required | Notes |
|---|---|---|
| `id` | Yes | Eindeutige ID für dismissed-State |
| `text` | Yes | Anzeigetext |
| `link` | No | URL wenn klickbar |
| `dismissible` | No | Boolean (default: true) |
| `bg_color` | No | Theme-Token (default: `primary`) |

### Product Recommendations (YAML)

| Field | Required | Notes |
|---|---|---|
| `heading` | Yes | Überschrift (z.B. "Das könnte dir gefallen") |
| `source` | Yes | `related` \| `category` \| `bestsellers` \| `custom` |
| `custom_ids` | When source=custom | Komma-getrennte WC-Produkt-IDs |
| `first` | No | Anzahl Produkte (default: 4) |

---

## Implementation Slices

### Dependencies

```
Slice 1 (Infrastruktur) → Slice 2 (Produkt-Enhancements)
                        → Slice 3 (Kategorie-Enhancements)
                        → Slice 4 (Homepage-Enhancements)
Slice 3 → Slice 5 (Suchseite)   [Pagination + sort-bar wiederverwendet]
Slice 2 → Slice 5               [search-results = product-grid Muster]
Slice 1 → Slice 6 (Neue Pages)
```

### Slices

| # | Name | Scope | Testability | Dependencies |
|---|---|---|---|---|
| 1 | **Cross-Page Infrastruktur** | `breadcrumb`, `trust-badges`, `announcement-bar`, `pagination`, `sort-bar`, `empty-state` | Alle 6 Blocks auf einer Test-Page rendern; Pagination URL-Params; Announcement Dismiss in localStorage | — |
| 2 | **Produkt-Page Enhancements** | `product-reviews` (Lesen + Schreiben), `product-recommendations` (konfigurierbar: related/category/bestsellers), YAML-Erweiterung `product.yaml` | Review lesen (WC-Daten), Review absenden (Mutation), Recommendations mit 3 verschiedenen Quellen | Slice 1 (trust-badges) |
| 3 | **Kategorie-Page Enhancements** | `breadcrumb`, `sort-bar`, `pagination` (paginated GraphQL), `empty-state` in Kategorie-YAML | Navigation durch 3 Seiten; Sortierung ändert Reihenfolge; Empty-State wenn Kategorie leer | Slice 1 |
| 4 | **Homepage Enhancements** | `testimonials`, `newsletter-signup`, `featured-collection`, `announcement-bar` in `home.yaml` | Alle 4 neuen Blöcke auf Homepage sichtbar; Newsletter-Formular Validierung | Slice 1 |
| 5 | **Suchseite** | `/suche` Route, `search-bar`, `search-results` (= GraphQL-Suche), `pagination`, `empty-state`, `sort-bar` in YAML | Suche nach "shirt" liefert Ergebnisse; Leere Suche zeigt empty-state; Pagination navigiert | Slice 1, Slice 3 (Pagination/sort-bar) |
| 6 | **Neue Pages** | `/kollektion/[slug]` + `collection-header` Block, `/danke` Order-Confirmation, `not-found.tsx` 404 | Collections-Page mit WC-Kategorie; Danke-Page mit order_id Param; 404 auf ungültiger URL | Slice 1 |

### Recommended Order

1. **Slice 1: Cross-Page Infrastruktur** — Alle anderen Slices brauchen Pagination und Breadcrumb
2. **Slice 2: Produkt-Page Enhancements** — Höchster Conversion-Impact (Reviews + Cross-sell)
3. **Slice 3: Kategorie-Page Enhancements** — Zweit-wichtigste Page für Browse-Experience
4. **Slice 6: Neue Pages** — 404 ist Launch-blocker; Collections erschließt neue Einstiegspunkte
5. **Slice 5: Suchseite** — Wichtig aber komplex; wiederverwendet Slice 1+3 Komponenten
6. **Slice 4: Homepage Enhancements** — Trust + Newsletter; letzter Schliff für Launch

---

## Context & Research

### Similar Patterns in Codebase

| Feature | Location | Relevant because |
|---|---|---|
| Block-Registration | `lib/blocks/registry.ts` | Alle neuen Blocks hier eintragen |
| Suspense-Skeleton | `components/blocks/product-grid-block.tsx` | Muster für neue async Blocks |
| YAML $route.slug | `themes/default/pages/legal.yaml` | Gleiches Pattern für Collections + Suche |
| URL-Param Lesen | `app/kategorie/[slug]/page.tsx` | Muster für `?page=` + `?sort=` + `?q=` URL-Params |
| WC GraphQL Mutation | `lib/apollo/client.ts` | Review-Submit via Client-Component Mutation |
| Pinterest event_id | `app/warenkorb/page.tsx` | Danke-Page: Deduplication für CAPI checkout-Event |

### Web Research

| Source | Finding |
|---|---|
| E-Commerce CRO Research 2025 | Reviews erhöhen Conversion um 15-30%; Social Proof ist wichtigster Trust-Faktor |
| Mobile Commerce Stats 2025 | 73% Traffic + 58% Käufe via Mobile → Touch-Targets min. 44px (bereits konfiguriert) |
| SEO Best Practices | Klassische Pagination (Seitenzahlen) ist SEO-besser als Infinite Scroll; jede Seite indexierbar |
| WooCommerce GraphQL Search | Limit: SQL LIKE-Suche, kein Typo-Handling → ausreichend für MVP, migrationsfähig zu Meilisearch |
| POD-Shop Analyse | Trust Badges (Versand, Rückgabe) und Size Guide sind Haupt-Kaufhemmungen bei POD-Shops |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|---|---|---|---|
| 1 | Collections: WooCommerce Kategorien oder Product Tags? | A) Kategorie (existiert bereits, hat Bild+Beschreibung) B) Product Tags (flacher, kein Bild) | A) Kategorien | Offen — Architecture entscheidet |
| 2 | Newsletter-Backend: Nur UI oder Integration? | A) Nur UI (diese Discovery) B) Mailchimp/WC | A) Nur UI | **Entschieden: Nur UI** |
| 3 | `featured-collection` auf Homepage: Bild aus WC-Kategorie oder WordPress Custom Field? | A) WC-Kategorie-Bild B) WordPress Custom Field | A) WC-Kategorie | Offen — Architecture entscheidet |
| 4 | Announcement Bar global: Eigenes `global.yaml` oder in jeder Page-YAML? | A) Neues `global.yaml` B) Jede Page-YAML | A) global.yaml | Offen — Architecture entscheidet |

---

## Q&A Log

| # | Frage | Antwort |
|---|---|---|
| 1 | Wie sollen wir vorgehen? | Recherche zuerst: Codebase-Analyse + Best Practices E-Commerce, dann Scope definieren |
| 2 | Welcher Bereich hat für dich die höchste Priorität? | Alle Pages + alle Blocks (User hatte "alle" gewählt) |
| 3 | Was ist das primäre Ziel des nächsten Entwicklungsschritts? | Shop verkaufsbereit machen (Launch-Readiness) |
| 4 | Welche Bewertungs-Lösung soll genutzt werden? | WooCommerce-eigene Reviews (kein externes Tool) |
| 5 | Wie soll die Suche funktionieren — GraphQL oder Meilisearch? | GraphQL-MVP: WooCommerce/WPGraphQL Suche für jetzt; Meilisearch wäre besser (Typo-Toleranz, Facetten), aber für MVP nicht nötig. Migration später möglich. |
| 6 | Welche Cross-sell / Upsell Strategie? | Konfigurierbar: Ein Block (`product-recommendations`), verschiedene Quellen (related/category/bestsellers/custom) und Überschrift über YAML konfigurierbar |
| 7 | Soll es eine Collections-Page geben? | Ja, als eigene Page `/kollektion/[slug]` + `collection-header` Block |
| 8 | Announcement Bar: Wo soll sie erscheinen? | Konfigurierbar per YAML — global (neues global.yaml) oder pro Page konfigurierbar |
| 9 | Bewertungen auf der Produktseite: Welches Minimal-Feature? | Alle drei: Sterne-Summary + Review-Liste (lesbar) + Review-Formular (schreiben) |
| 10 | Pagination auf der Kategorie-Seite: Welcher Typ? | Klassische Seitenzahlen (SEO-kompatibel, ?page=N URL-Params) |

---

## Research Log

| Date | Area | Finding |
|---|---|---|
| 2026-02-25 | Codebase | 11 Blocks registriert: hero, product-grid, category-showcase, usp-bar, page-heading, filter-chips, product-count, product-gallery, product-purchase, product-description, legal-content |
| 2026-02-25 | Codebase | 5 Page-Routes: /, /kategorie/[slug], /produkt/[slug], /warenkorb, /agb+/datenschutz+/impressum+/widerruf |
| 2026-02-25 | Codebase | Kein 404 custom page; keine Suche; keine Collections; kein Order-Confirmation |
| 2026-02-25 | Codebase | Kategorie-Page: keine Sortierung, keine Pagination (fix: first: 24) |
| 2026-02-25 | Web | 73% Mobile Traffic → Touch-Targets bereits konfiguriert (min. 44px) |
| 2026-02-25 | Web | Reviews erhöhen Conversion Rate um 15-30% (Social Proof ist stärkster Trust-Faktor) |
| 2026-02-25 | Web | Klassische Pagination SEO-vorteilhafter als Infinite Scroll |
| 2026-02-25 | Web | Cross-sell / Product Recommendations erhöhen AOV um 20-35% |
| 2026-02-25 | Web | WooCommerce GraphQL Suche ausreichend für MVP (<200 Produkte); Meilisearch für Scale |
