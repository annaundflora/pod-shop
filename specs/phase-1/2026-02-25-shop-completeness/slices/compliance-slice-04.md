# Gate 2: Slice 04 Compliance Report (Re-Check)

**Gepruefter Slice:** `specs/phase-1/2026-02-25-shop-completeness/slices/slice-04-homepage-enhancements.md`
**Prufdatum:** 2026-02-26
**Architecture:** `specs/phase-1/2026-02-25-shop-completeness/architecture.md`
**Wireframes:** `specs/phase-1/2026-02-25-shop-completeness/discovery.md` (UI Layout & Context Section)
**Re-Check nach:** Erstem Compliance-Durchlauf

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 41 |
| Warning | 0 |
| Blocking | 0 |

**Verdict:** APPROVED

---

## Bekannte Fixes — Verifikation

| Fix | Beschreibung | Status |
|-----|-------------|--------|
| Fix 1 | `TestimonialsData.location?` — explizite "Architecture-Extension" Note im Code-Comment | Korrekt umgesetzt |
| Fix 2 | AC-3: "kein Flicker" aus THEN entfernt, localStorage-Key explizit in GIVEN genannt | Korrekt umgesetzt |
| Fix 3 | 2 neue `it()`-Tests fuer `AnnouncementBarBlock` (dismissed -> null, nicht-dismissed -> sichtbar) | Korrekt umgesetzt |

---

## 0) Inhaltliche Pruefung

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes — `id: "launch-2026"`, Rendering oberhalb Header | Yes — global.yaml konfiguriert | Yes — Seitenaufruf | Yes — DOM-Position pruefbar | Pass |
| AC-2 | Yes | Yes — exakter localStorage-Key `announcement-dismissed-launch-2026` | Yes — Bar ist sichtbar | Yes — Klick auf X-Button | Yes — localStorage gesetzt, Bar weg, kein Reload | Pass |
| AC-3 | Yes | Yes — exakter localStorage-Key in GIVEN, konkrete Vorbedingung | Yes — Key explizit genannt (Fix 2 umgesetzt) | Yes — Seitenaufruf | Yes — Bar wird nicht angezeigt (queryByText -> null) | Pass |
| AC-4 | Yes | Yes — 3 Karten, 1/2/3-Spalten-Grid, konkrete Elemente | Yes — home.yaml konfiguriert | Yes — Homepage laden | Yes — Anzahl Karten und Sterne pruefbar | Pass |
| AC-5 | Yes | Yes — exakt 5 Sterne, aria-label "5 von 5 Sternen" | Yes — items mit rating:5 | Yes — Karte gerendert | Yes — aria-label maschinell pruefbar | Pass |
| AC-6 | Yes | Yes — slug t-shirts, exakt 4 Produkt-Cards | Yes — slug-Konfiguration spezifiziert | Yes — Homepage laden | Yes — Cards zaehlen pruefbar | Pass |
| AC-7 | Yes | Yes — href `/kategorie/t-shirts`, kein onClick | Yes — Block geladen | Yes — Klick auf "Alle ansehen" | Yes — href-Attribut pruefbar | Pass |
| AC-8 | Yes | Yes — 4 konkrete UI-Elemente aufgelistet | Yes — Block konfiguriert | Yes — Homepage laden | Yes — Elemente per Role-Query pruefbar | Pass |
| AC-9 | Yes | Yes — Fehlermeldung exakt angegeben, kein console.log | Yes — idle-State, ungueltige E-Mail angegeben | Yes — einzige Aktion klar | Yes — role=alert pruefbar, console.log spy pruefbar | Pass |
| AC-10 | Yes | Yes — Loading-State Spinner+disabled, dann Erfolgsmeldung | Yes — idle-State, valide E-Mail angegeben | Yes — einzige Aktion klar | Yes — button.disabled, role=status pruefbar | Pass |
| AC-11 | Yes | Yes — ohne Bild, Titel + Cards bleiben | Yes — Kategorie ohne Bild | Yes — Homepage laden | Yes — kein img-Element, Titel pruefbar | Pass |
| AC-12 | Yes | Yes — `{ sections: [] }`, kein Fehler | Yes — global.yaml existiert nicht | Yes — loadGlobalConfig aufgerufen | Yes — sections.length === 0, kein throw | Pass |

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| `TestimonialsData` Interface (Sect. 5) | Yes — Architecture-Extension fuer `location?` explizit begruendet, optional markiert | Yes — lib/blocks/types.ts | Yes — items-Array mit korrekten Feldern | N/A | Pass |
| `NewsletterSignupData` Interface (Sect. 5) | Yes — stimmt exakt mit architecture.md Zeile 350 ueberein | Yes | Yes — headline, text, buttonText, placeholder | N/A | Pass |
| `FeaturedCollectionData` Interface (Sect. 5) | Yes — stimmt mit architecture.md Zeile 352 ueberein | Yes | Yes — category + products.nodes: ProductCardData[] | N/A | Pass |
| `featured_collection` data-loader Branch (Sect. 4) | Yes — GET_CATEGORY_META + GET_PRODUCTS_PAGINATED, Promise.all | Yes — @/lib/apollo/server-client, @/lib/graphql/queries | Yes — returns FeaturedCollectionData oder { data: null } | N/A | Pass |
| `global.yaml` Struktur (Sect. 8) | Yes — id, text, link, dismissible, bgColor alle vorhanden | N/A (YAML) | Yes — struct stimmt mit AnnouncementBarData ueberein | N/A | Pass |
| `layout.tsx` Erweiterung (Sect. 9) | Yes — loadGlobalConfig(theme), SectionRenderer | Yes — @/lib/blocks/page-config, @/lib/blocks/section-renderer | Yes — async RSC, skeletonMap={{}} | N/A | Pass |
| `home.yaml` vollstaendige Datei (Sect. 7) | Yes — alle 7 Sections, kein announcement-bar | N/A (YAML) | Yes — Reihenfolge gemaess Architecture Zeile 590-598 | N/A | Pass |
| Newsletter Submit-Logik (Sect. 3.2) | Yes — console.log + 500ms + setState | Yes | Yes — Sequenz vollstaendig beschrieben | N/A | Pass |
| `app/page.tsx` skeletonMap Erweiterung (Sect. 10) | Yes — testimonials: null, newsletter-signup: null, featured-collection: Skeleton | Yes — @/components/blocks/featured-collection-block | Yes | N/A | Pass |

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | typescript-nextjs | typescript-nextjs (Next.js 16, Vitest 3, TS 5.7 — aus package.json) | Pass |
| Commands vollstaendig | 3 Commands vorhanden (Test, Integration, Acceptance) | 3 (unit, integration, acceptance) | Pass |
| Start-Command | `cd frontend && pnpm dev` | Passend zu Next.js Stack | Pass |
| Health-Endpoint | `http://localhost:3000/api/health` | Passend zu Next.js auf Port 3000 | Pass |
| Mocking-Strategy | `mock_external` — Apollo Server Client via vi.mock(), next/navigation, localStorage via jsdom | Definiert und vollstaendig | Pass |

---

## A) Architecture Compliance

### Schema Check

> Kein DB-Schema in diesem Slice — Daten kommen aus WooCommerce (wp_terms, wp_term_taxonomy). Keine eigenen Tabellen.

| Arch Field | Arch Type | Slice Spec | Status |
|------------|-----------|------------|--------|
| N/A — kein neues DB-Schema | — | — | Pass |

### API Check

| Endpoint / Query | Arch Spec | Slice Spec | Status |
|-----------------|-----------|------------|--------|
| `GET_CATEGORY_META` | Arch: `slug: ID!` -> `productCategory { name description slug count image { sourceUrl altText } }` | Slice Sect. 4: `GET_CATEGORY_META(slug)` -> `cat.name, cat.description, cat.slug, cat.image` | Pass |
| `GET_PRODUCTS_PAGINATED` | Arch: `first: Int!, categorySlug: String, search: String, orderby` -> `products { nodes { ...ProductCardFields } pageInfo { ... } }` | Slice Sect. 4: `GET_PRODUCTS_PAGINATED(categorySlug: slug, first, orderby: undefined)` -> `products.nodes` | Pass |
| `featured_collection` data-loader | Arch Zeile 369: `slug, first` -> GET_CATEGORY_META + GET_PRODUCTS_PAGINATED parallel | Slice: Promise.all, korrekte Parameter | Pass |

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| Reads unauthenticated | RSC Server Client, no session token | Slice verwendet getClient() (RSC Server Client) — korrekt | Pass |
| Keine Review-Mutation | Slice 4 hat keine Mutations | Korrekt — Newsletter nur console.log, kein API-Call | Pass |
| Newsletter UI-only | Architecture Out of Scope: kein Backend | Slice: console.log Platzhalter, kein API-Call | Pass |

---

## B) Wireframe Compliance

### UI Elements (aus discovery.md "Screen: Homepage (erweitert)")

| Wireframe Element | Discovery Annotation | Slice Component | Status |
|-------------------|---------------------|-----------------|--------|
| Announcement Bar (global) | Global/Top, visible/dismissed States | AnnouncementBarBlock (Slice 1), aktiviert via global.yaml | Pass |
| Testimonials Grid | 3 Karten, Sterne, Name, Zitat | TestimonialsBlock, Grid 1/2/3 Spalten | Pass |
| Featured Collection | Kategorie-Bild fullwidth, Titel, 4 Produkt-Cards, "Alle ansehen" | FeaturedCollectionBlock mit Skeleton | Pass |
| Newsletter Signup | Headline, Text, E-Mail-Input, Button | NewsletterSignupBlock mit idle/loading/success/error States | Pass |

### State Variations

| State | Discovery/Wireframe | Slice | Status |
|-------|---------------------|-------|--------|
| Announcement Bar: visible | Definiert | AnnouncementBarBlock rendert wenn kein dismissed-Key | Pass |
| Announcement Bar: dismissed | Definiert (localStorage) | AC-3 + Test: queryByText returns null | Pass |
| Newsletter: idle | Definiert | useState('idle'), Formular sichtbar | Pass |
| Newsletter: loading | Definiert | Button disabled, Spinner | Pass |
| Newsletter: success | Definiert | role=status, Formular ausgeblendet | Pass |
| Newsletter: error | Definiert | role=alert, Inline-Fehler | Pass |
| FeaturedCollection: Loading | FeaturedCollectionBlockSkeleton | animate-pulse Fullwidth-Bar + 4 Karten-Placeholder | Pass |
| FeaturedCollection: null data | Block rendert null | null return wenn kein slug oder keine Produkte | Pass |
| Testimonials: empty items | Block rendert null | items.length === 0 -> null | Pass |

### Visual Specs

| Spec | Discovery/Wireframe Value | Slice Value | Status |
|------|--------------------------|-------------|--------|
| Testimonials Grid-Layout | 3 Karten nebeneinander Desktop | grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 | Pass |
| Featured Collection Bild | Fullwidth | width=1200, height=400, object-cover, w-full | Pass |
| Featured Collection Produkt-Cards | 4 Spalten Desktop, 1 Mobile | grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 | Pass |
| Newsletter max-width | Zentriert, begrenzt | max-w-lg mx-auto | Pass |
| Karten-Styling | Karten mit Shadow | rounded-card, shadow-card, bg-surface, p-6 | Pass |
| Sterne aria-label | "N von 5 Sternen" | aria-label="N von 5 Sternen" | Pass |

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| `loadGlobalConfig(theme)` | slice-01-cross-page-infrastruktur | Integration Contract — korrekt referenziert mit Typ-Signatur | Pass |
| `AnnouncementBarBlock` | slice-01-cross-page-infrastruktur | Referenziert, Content-Source und Type angegeben | Pass |
| `lib/blocks/registry.ts` Pattern | slice-01-cross-page-infrastruktur | Erweiterungs-Pattern dokumentiert | Pass |
| `GET_CATEGORY_META` | slice-03-kategorie-page-enhancements | Exportiert aus lib/graphql/queries.ts, Signatur angegeben | Pass |
| `GET_PRODUCTS_PAGINATED` | slice-03-kategorie-page-enhancements | Exportiert aus lib/graphql/queries.ts, nullable $categorySlug | Pass |
| `PaginatedProductsResult` | slice-03-kategorie-page-enhancements | Exportiert aus lib/blocks/types.ts | Pass |
| `WooCommerceLoaderParams` | slice-03-kategorie-page-enhancements | Wird um 'featured_collection' erweitert | Pass |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| `TestimonialsBlock` | app/page.tsx via SectionRenderer + registry | BlockComponentProps<TestimonialsData> Interface angegeben | Pass |
| `NewsletterSignupBlock` | app/page.tsx via SectionRenderer + registry | BlockComponentProps<NewsletterSignupData> Interface angegeben | Pass |
| `FeaturedCollectionBlock` | app/page.tsx via SectionRenderer + registry | BlockComponentProps<FeaturedCollectionData> Interface angegeben | Pass |
| `FeaturedCollectionBlockSkeleton` | app/page.tsx skeletonMap | () => React.ReactElement angegeben | Pass |
| `TestimonialsData`, `NewsletterSignupData`, `FeaturedCollectionData` | lib/blocks/types.ts | Felder vollstaendig spezifiziert | Pass |
| `themes/default/pages/global.yaml` | app/layout.tsx via loadGlobalConfig() | sections[0].blocks[0].type === 'announcement-bar' | Pass |
| `featured_collection` query branch | featured-collection-block.tsx | woocommerceLoader({ query: 'featured_collection', slug, first }) | Pass |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-----------------|--------------|--------|
| TestimonialsBlock | app/page.tsx (via registry) | Yes — app/page.tsx ist geaenderte Datei in Deliverables (skeletonMap-Erweiterung) | Slice 04 | Pass |
| NewsletterSignupBlock | app/page.tsx (via registry) | Yes — app/page.tsx in Deliverables | Slice 04 | Pass |
| FeaturedCollectionBlock | app/page.tsx (via registry) | Yes — app/page.tsx in Deliverables | Slice 04 | Pass |
| global.yaml | app/layout.tsx | Yes — app/layout.tsx in Deliverables dieses Slices | Slice 04 | Pass |

**Hinweis:** Blocks werden via SectionRenderer + Registry gemountet, nicht direkt importiert. app/page.tsx ist trotzdem als geaenderte Datei im Deliverable-Scope (skeletonMap-Erweiterung), was den Mount-Point sicherstellt.

### AC-Deliverable-Konsistenz

| AC # | Referenced Page | In Deliverables? | Status |
|------|-----------------|-----------------|--------|
| AC-1 | layout.tsx (Rendering oberhalb Header) | layout.tsx ist Deliverable dieses Slices | Pass |
| AC-2 | AnnouncementBarBlock (Slice 1 Dependency) | Slice 1 Dependency korrekt deklariert | Pass |
| AC-3 | AnnouncementBarBlock (Slice 1 Dependency), Tests direkt auf Komponente | Slice 1 Dependency vorhanden | Pass |
| AC-4 bis AC-12 | home.yaml und app/page.tsx | Beide in Deliverables dieses Slices | Pass |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| TestimonialsData Interface | Sect. 5 + Sect. 3.1 | Yes | Yes — Abweichung (location?) explizit begruendet | Pass |
| TestimonialsItem Sub-Interface | Sect. 5 | Yes | Yes | Pass |
| NewsletterSignupData Interface | Sect. 5 + Sect. 3.2 | Yes | Yes — exakt gemaess Architecture Zeile 350 | Pass |
| FeaturedCollectionData Interface | Sect. 5 + Sect. 3.3 | Yes | Yes — gemaess Architecture Zeile 352 | Pass |
| featured_collection data-loader Branch | Sect. 4 | Yes — Promise.all, null-Returns, satisfies FeaturedCollectionData | Yes | Pass |
| global.yaml | Sect. 8 | Yes | Yes — AnnouncementBarData-konform | Pass |
| layout.tsx Erweiterung | Sect. 9 | Yes | Yes — loadGlobalConfig(theme), SectionRenderer vor Header | Pass |
| home.yaml vollstaendige Datei | Sect. 7 | Yes — 7 Sections in korrekter Reihenfolge | Yes — gemaess architecture.md Zeile 590-598 | Pass |
| Newsletter submit-Logik | Sect. 3.2 | Yes — Sequenz vollstaendig, 500ms delay, console.log | Yes | Pass |
| app/page.tsx skeletonMap | Sect. 10 | Yes — alle 3 neuen Eintraege | Yes | Pass |

---

## E) Build Config Sanity Check

N/A — Slice 04 hat keine Build-Config-Deliverables. Keine neuen npm-Pakete werden benoetigt.

---

## F) Test Coverage

| Acceptance Criteria | Test Defined | Test Type | Status |
|--------------------|--------------|-----------|--------|
| AC-1 (Announcement Bar rendered in layout) | loadGlobalConfig-Test: sections mit announcement-bar vorhanden | Vitest Unit | Pass |
| AC-2 (X-Button setzt localStorage) | AnnouncementBarBlock aus Slice 1 — Slice 1 Dependency | Vitest Unit (Slice 1) | Pass |
| AC-3 (dismissed -> nicht angezeigt) | NEU: it('should not render when localStorage dismissed key is set') | Vitest Unit | Pass |
| AC-3 (nicht-dismissed -> sichtbar) | NEU: it('should render when localStorage dismissed key is NOT set') | Vitest Unit | Pass |
| AC-4 (3 Testimonial-Karten) | it('should render the correct number of testimonial cards') — expects 3 figure roles | Vitest Unit | Pass |
| AC-5 (5 Sterne, aria-label) | it('should render the correct number of filled stars for rating 4') + location test | Vitest Unit | Pass |
| AC-6 (Featured Collection 4 Cards) | it('should render category name and products') — expects >= 4 links | Vitest Unit | Pass |
| AC-7 ("Alle ansehen" Link href) | it('should render "Alle ansehen" link pointing to /kategorie/t-shirts') | Vitest Unit | Pass |
| AC-8 (Newsletter Elemente sichtbar) | it('should render headline, input and submit button in idle state') | Vitest Unit | Pass |
| AC-9 (Inline-Fehler ungueltige E-Mail) | it('should show inline error for invalid email format on submit') | Vitest Unit | Pass |
| AC-10 (Loading+Success) | it('should disable submit button in loading state') + it('should show success message') | Vitest Unit | Pass |
| AC-11 (Kategorie ohne Bild) | it('should render category name and products') — image: null in test data | Vitest Unit | Pass |
| AC-12 (global.yaml fehlt -> sections:[]) | it('should return empty sections when global.yaml does not exist') | Vitest Unit | Pass |

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| UI Components | announcement-bar (visible/dismissed) | Yes | Yes — global.yaml + AnnouncementBarBlock + AC-2/3 + Tests | Pass |
| UI Components | newsletter-email (empty/valid/invalid/submitted) | Yes | Yes — idle/loading/success/error States + AC-8/9/10 | Pass |
| State Machine | Announcement Bar: visible -> dismissed (localStorage) | Yes | Yes — GIVEN/WHEN/THEN in AC-2/3, Tests | Pass |
| State Machine | Announcement Bar: page load -> localStorage check | Yes | Yes — AC-3 + neue Tests (Fix 3) | Pass |
| Business Rules | Announcement Bar ID — gleiche ID = nicht erneut zeigen | Yes | Yes — localStorage-Key `announcement-dismissed-{id}` | Pass |
| Business Rules | Newsletter Signup — nur UI, kein Backend | Yes | Yes — console.log Platzhalter, explizit Out of Scope | Pass |
| Data | Announcement Bar: id (required), text (required), link (optional), dismissible (optional), bg_color (optional) | Yes | Yes — global.yaml und AnnouncementBarData gemaess Architecture | Pass |
| UI Layout | Homepage: testimonials, newsletter-signup, featured-collection | Yes | Yes — alle 3 Blocks implementiert | Pass |

---

## Blocking Issues Summary

Keine Blocking Issues. Alle Findings aus dem ersten Durchlauf sind behoben.

---

## Fix-Verifikation Detail

### Fix 1: TestimonialsData.location? Architecture-Extension Note

**Geprueft:** Slice Zeile 161-170

Die Abweichung von architecture.md Zeile 349 (`TestimonialsData` ohne `location`) ist klar dokumentiert:
- Code-Comment benennt explizit die Referenzzeile ("architecture.md Zeile 349")
- Begruendung ist UX-relevant ("Maria K., Muenchen")
- Feld ist optional (`location?: string`) und daher nicht-brechend
- Types in lib/blocks/types.ts enthalten das Feld ebenfalls (Sect. 5)

**Status:** Pass

### Fix 2: AC-3 ohne "kein Flicker", localStorage-Key in GIVEN

**Geprueft:** Slice Zeile 710-712

- GIVEN enthaelt exakten Key `localStorage['announcement-dismissed-launch-2026']`
- WHEN ist einzige Aktion (Homepage laden)
- THEN ist maschinell pruefbar: "wird die Announcement Bar nicht angezeigt" (queryByText -> null)
- "kein Flicker" ist vollstaendig entfernt

**Status:** Pass

### Fix 3: 2 neue it()-Tests fuer AnnouncementBarBlock

**Geprueft:** Slice Zeile 1076-1097 (`describe('AnnouncementBarBlock localStorage dismissed (AC-3)')`)

Test 1 (Zeile 1079-1088):
- Setzt `window.localStorage.setItem('announcement-dismissed-launch-2026', '1')`
- Prueft `screen.queryByText('Kostenloser Versand ab 50€')` ist `null`
- Raumt auf: `window.localStorage.clear()`

Test 2 (Zeile 1090-1096):
- Raumt localStorage auf: `window.localStorage.clear()`
- Prueft `screen.getByText('Kostenloser Versand ab 50€')` ist truthy

Beide Tests decken die AC-3 Szenarien vollstaendig ab.

**Status:** Pass

---

## Recommendations

Keine Nachbesserungen erforderlich. Alle drei geforderten Fixes sind korrekt und vollstaendig umgesetzt.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 0

Alle drei geforderten Fixes sind korrekt umgesetzt:

1. `TestimonialsData.location?` — Architecture-Extension Note ist praezise, klar begruendet und im Code-Comment korrekt verortet.
2. AC-3 — "kein Flicker" ist entfernt. `localStorage['announcement-dismissed-launch-2026']` ist explizit im GIVEN-Satz verankert. THEN ist rein maschinell pruefbar.
3. Zwei neue `it()`-Tests im describe-Block `AnnouncementBarBlock localStorage dismissed (AC-3)` decken beide Szenarien vollstaendig ab.

Die uebrigen Compliance-Checks (Architecture, Wireframe, Integration Contract, Code Examples, Test Coverage, Discovery) sind unveraendert korrekt.

VERDICT: APPROVED
