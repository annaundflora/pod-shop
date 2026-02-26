# Gate 2: Slice 04 Compliance Report

**Gepruefter Slice:** `specs/phase-1/2026-02-25-shop-completeness/slices/slice-04-homepage-enhancements.md`
**Pruefdatum:** 2026-02-26
**Architecture:** `specs/phase-1/2026-02-25-shop-completeness/architecture.md`
**Wireframes:** `specs/phase-1/2026-02-25-shop-completeness/discovery.md` (UI Layout & Context)
**Discovery:** `specs/phase-1/2026-02-25-shop-completeness/discovery.md`
**Vorherige Slices:** slice-01, slice-02, slice-03

---

## Summary

| Status | Count |
|--------|-------|
| OK | 47 |
| Blocking | 2 |

**Verdict:** FAILED

---

## 0) Inhaltliche Pruefung

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes | No — Konfigurationszustand ist Teil des WHEN, nicht des GIVEN | Yes | Yes | OK (minor) |
| AC-2 | Yes | Yes | Yes | Yes | Yes | OK |
| AC-3 | No | No | Yes | Yes | No — "kein Flicker" ist nicht maschinell pruefbar | BLOCKED |
| AC-4 | Yes | Yes | Yes | Yes | Yes | OK |
| AC-5 | Yes | Yes | Yes | Yes | Yes | OK |
| AC-6 | Yes | Yes | Yes | Yes | Yes | OK |
| AC-7 | Yes | Yes | Yes | Yes | Yes | OK |
| AC-8 | Yes | Yes | Yes | Yes | Yes | OK |
| AC-9 | Yes | Yes | Yes | Yes | Yes | OK |
| AC-10 | Yes | Yes | Yes | Yes | Yes | OK |
| AC-11 | Yes | Yes | Yes | Yes | Yes | OK |
| AC-12 | Yes | Yes | Yes | Yes | Yes | OK |

**Detail zu AC-3:**

> GIVEN der User hat die Announcement Bar bereits dismissed (localStorage gesetzt)
> WHEN die Homepage neu geladen wird
> THEN wird die Announcement Bar nicht angezeigt (kein Flicker)

Problem 1: "kein Flicker" ist kein maschinell messbarer Zustand. Ein automatisierter Test kann pruefe ob die Bar nicht angezeigt wird (DOM-Assertion), aber ob ein kurzes visuelles Aufflackern (Flicker) auftritt, ist nicht testbar via Vitest/Testing Library.

Problem 2: Im Test-Spec (`tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts`) gibt es keinen Test der den localStorage-Dismissed-State der AnnouncementBar prueft. Die `loadGlobalConfig`-Tests pruefen nur das Laden der Konfiguration, nicht das Rendern oder Verstecken der Bar basierend auf localStorage.

**Konsequenz:** AC-3 hat kein zugeordnetes, ausfuehrbares Acceptance-Test. Das THEN enthaelt eine nicht-testbare Bedingung.

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| `TestimonialsData` Interface (Sec. 5) | No — `location?: string` nicht in architecture.md Zeile 349 | Yes | n/a | n/a | BLOCKED |
| `NewsletterSignupData` Interface (Sec. 5) | Yes | Yes | Yes | Yes | OK |
| `FeaturedCollectionData` Interface (Sec. 5) | Yes | Yes | Yes | Yes | OK |
| `featured_collection` data-loader Branch (Sec. 4) | Yes | Yes — `GET_CATEGORY_META`, `GET_PRODUCTS_PAGINATED` aus queries.ts | Yes | Yes | OK |
| `global.yaml` Struktur (Sec. 8) | Yes | Yes | n/a | n/a | OK |
| `layout.tsx` Erweiterung (Sec. 9) | Yes | Yes — `@/lib/blocks/page-config`, `@/lib/blocks/section-renderer` | Yes | n/a | OK |
| `home.yaml` vollstaendige Datei (Sec. 7) | Yes | n/a | n/a | n/a | OK |
| Newsletter Submit-Logik (Sec. 3.2) | Yes | n/a | n/a | n/a | OK |
| `app/page.tsx` skeletonMap (Sec. 10) | Yes | Yes | n/a | n/a | OK |
| `registry.ts` Ergaenzungen (Sec. 6) | Yes | Yes — `@/components/blocks/testimonials-block` etc. | n/a | n/a | OK |

**Detail zum `TestimonialsData`-Feld `location?`:**

Architecture-Spec Zeile 349 definiert:
```
TestimonialsData | items: { name: string, text: string, rating: number }[]
```

Slice Sec. 5 definiert:
```typescript
export interface TestimonialsItem {
  name: string
  text: string
  rating: number
  location?: string   // <-- NICHT in Architecture-Tabelle
}
```

Das Feld `location` ist optional und nicht brechend, aber es weicht von der authority-gebenden Architecture-Definition ab.

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | typescript-nextjs (Next.js 16, Vitest 3.0) | OK |
| Commands vollstaendig | 3 (Test, Integration, Acceptance) | 3 | OK |
| Start-Command | `cd frontend && pnpm dev` | Passend zu Next.js Stack | OK |
| Health-Endpoint | `http://localhost:3000/api/health` | Passend zu Next.js auf Port 3000 | OK |
| Mocking-Strategy | `mock_external` — Apollo Server Client via vi.mock(), next/navigation gemockt | Definiert und spezifisch | OK |

---

## A) Architecture Compliance

### Schema Check

| Arch Field (Zeile 349-350) | Arch Type | Slice Spec (Sec. 5) | Status | Issue |
|------------|-----------|------------|--------|-------|
| `TestimonialsData.items[].name` | `string` | `string` | OK | — |
| `TestimonialsData.items[].text` | `string` | `string` | OK | — |
| `TestimonialsData.items[].rating` | `number` | `number` | OK | — |
| `TestimonialsData.items[].location` | NOT IN ARCH | `string?` (optional) | BLOCKED | Feld nicht in Architecture-Definition |
| `NewsletterSignupData.headline` | `string` | `string` | OK | — |
| `NewsletterSignupData.text` | `string` | `string` | OK | — |
| `NewsletterSignupData.buttonText` | `string` | `string` | OK | — |
| `NewsletterSignupData.placeholder` | `string` | `string` | OK | — |
| `FeaturedCollectionData.category.name` | `string` | `string` | OK | — |
| `FeaturedCollectionData.category.description` | `string` | `string` | OK | — |
| `FeaturedCollectionData.category.slug` | `string` | `string` | OK | — |
| `FeaturedCollectionData.category.image` | `{ sourceUrl, altText }?` | `{ sourceUrl: string; altText: string } \| null` optional | OK | — |
| `FeaturedCollectionData.products.nodes` | `ProductCardData[]` | `ProductCardData[]` | OK | — |
| `AnnouncementBarData.id` | `string` | `id: "launch-2026"` (YAML) | OK | — |
| `AnnouncementBarData.bgColor` | `string?` | `bgColor: "primary"` (YAML) | OK | Arch uses `bgColor`, discovery uses `bg_color` — slice consistent with architecture |

### API Check

| Endpoint/Query | Arch Spec | Slice Spec | Status | Issue |
|----------|-------------|--------------|--------|-------|
| `GET_CATEGORY_META` | Arch Zeile 78 — `query GetCategoryMeta($slug: ID!)` | Dependency auf Slice 3 (korrekt implementiert in Slice 3 Sec. 11) | OK | — |
| `GET_PRODUCTS_PAGINATED` | Arch Zeile 73 — `$first: Int!, $categorySlug: String, ...` | Dependency auf Slice 3 | OK | — |
| `featured_collection` data-loader | Arch Zeile 369 — `slug, first` → `GET_CATEGORY_META + GET_PRODUCTS_PAGINATED` | Slice Sec. 4 — `Promise.all([GET_CATEGORY_META, GET_PRODUCTS_PAGINATED])` | OK | — |
| `woocommerceLoader` query union `'featured_collection'` | Arch Zeile 369 | Slice Sec. 5 — `WooCommerceLoaderParams.query` um `'featured_collection'` erweitert | OK | — |

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| Newsletter-Formular: kein Backend-Call | Arch Out of Scope (newsletter backend) | Slice Sec. 3.2: nur `console.log`, kein API-Call, kein E-Mail gespeichert | OK |
| Unauthenticated Read (featured-collection) | Arch Zeile 229 — Product queries unauthenticated | Slice: Server Component, Apollo Server Client (kein Session-Token) | OK |
| Announcement Bar localStorage | Keine Sicherheitsanforderung (client-side only) | Slice: localStorage key per ID — kein sicherheitskritischer Wert | OK |

---

## B) Wireframe Compliance

### UI Elements

| Wireframe Element (discovery.md) | Annotation | Slice Component | Status |
|-------------------|------------|-----------------|--------|
| Announcement Bar (Global, ueber Header) | `announcement-bar` Block | `global.yaml` + `layout.tsx` Integration — Slice Sec. 3.4 + 9 | OK |
| Testimonials — 3-Spalten-Grid mit Sterne, Zitat, Name | `testimonials` Block | `testimonials-block.tsx` Sec. 3.1 | OK |
| Featured Collection — Fullwidth Bild + Titel + 4 Karten | `featured-collection` Block | `featured-collection-block.tsx` Sec. 3.3 | OK |
| Newsletter Signup — E-Mail Input + Button, zentriert | `newsletter-signup` Block | `newsletter-signup-block.tsx` Sec. 3.2 | OK |
| X-Button (dismiss) auf Announcement Bar | Dismissible | Slice 1 Deliverable, hier aktiviert via global.yaml `dismissible: true` | OK |
| "Alle ansehen" Link in Featured Collection | `<a href>` zu `/kategorie/{slug}` | Slice Sec. 3.3 — semantischer Link, kein onClick | OK |

### State Variations

| State | Discovery/Wireframe | Slice | Status |
|-------|-----------|-------|--------|
| `announcement-bar` visible / dismissed | Discovery State Machine Sec. | Slice Sec. 3.4 — `dismissible: true` in global.yaml; Slice-1-Block-Implementierung | OK |
| `newsletter-signup` idle / loading / success / error | Discovery UI Components | Slice Sec. 3.2 — alle 4 States definiert | OK |
| `featured-collection` loading state (Skeleton) | Implied by async block | `FeaturedCollectionBlockSkeleton` definiert | OK |
| `testimonials` empty state (0 items) | Implied | Slice Sec. 3.1: "wenn `items.length === 0` → Block rendert null" | OK |

### Visual Specs

| Spec | Wireframe/Discovery Value | Slice Value | Status |
|------|-----------------|-------------|--------|
| Testimonials Grid | 1 col Mobile / 2 col Tablet / 3 col Desktop | `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3` | OK |
| Featured Collection Products | 1 col Mobile / 2 col Tablet / 4 col Desktop | `grid-cols-1 sm:grid-cols-2 lg:grid-cols-4` | OK |
| Featured Collection Bild | Fullwidth, `object-cover` | `next/image width=1200 height=400 object-cover` | OK |
| Newsletter max-width | Zentriert, begrenzt | `max-w-lg mx-auto` | OK |
| Testimonials-Karte | `rounded-card, shadow-card, bg-surface, p-6` | Identisch in Slice Sec. 3.1 | OK |
| Newsletter Background | Abgesetzt | `bg-surface-secondary` | OK |

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| `loadGlobalConfig(theme)` | slice-01 (Sec. 11) | Slice Sec. 3.4 — "loadGlobalConfig() ist in Slice 1 als neue Funktion definiert" | OK |
| `AnnouncementBarBlock` | slice-01 | Slice Sec. 3.4 — "Client Component aus Slice 1" | OK |
| `lib/blocks/registry.ts` Pattern | slice-01 | Slice Sec. 6 — "Ergaenzungen nach Slice 1-3 Registrierungen" | OK |
| `GET_CATEGORY_META` Query | slice-03 (Sec. 11) | Slice Integration Contract Sec. | OK |
| `GET_PRODUCTS_PAGINATED` Query | slice-03 (Sec. 3) | Slice Integration Contract Sec. | OK |
| `PaginatedProductsResult` Interface | slice-03 (Sec. 6) | Slice Integration Contract Sec. | OK |
| `WooCommerceLoaderParams` mit `query`, `slug`, `first` | slice-03 (Sec. 7) | Slice Sec. 5 — um `'featured_collection'` erweitert | OK |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| `TestimonialsBlock` | `app/page.tsx` via SectionRenderer + registry | Slice Integration Contract Sec. | OK |
| `NewsletterSignupBlock` | `app/page.tsx` via SectionRenderer + registry | Slice Integration Contract Sec. | OK |
| `FeaturedCollectionBlock` + `FeaturedCollectionBlockSkeleton` | `app/page.tsx` via SectionRenderer + registry | Slice Integration Contract Sec. | OK |
| `TestimonialsData`, `NewsletterSignupData`, `FeaturedCollectionData` | `lib/blocks/types.ts` | Slice Integration Contract Sec. | OK |
| `themes/default/pages/global.yaml` | `app/layout.tsx` via `loadGlobalConfig()` | Slice Integration Contract Sec. | OK |
| `featured_collection` query branch | `featured-collection-block.tsx` | Slice Integration Contract Sec. | OK |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-------------------|--------------|--------|
| `TestimonialsBlock` | `app/page.tsx` | Yes (Geaenderte Dateien) | Slice 04 | OK |
| `NewsletterSignupBlock` | `app/page.tsx` | Yes (Geaenderte Dateien) | Slice 04 | OK |
| `FeaturedCollectionBlock` | `app/page.tsx` | Yes (Geaenderte Dateien) | Slice 04 | OK |
| `global.yaml` | `app/layout.tsx` | Yes (Geaenderte Dateien) | Slice 04 | OK |

### AC-Deliverable-Konsistenz

| AC # | Referenced Page/File | In Deliverables? | Status |
|------|-----------------|-------------------|--------|
| AC-1 | `layout.tsx`, `global.yaml` | Yes | OK |
| AC-2 | `announcement-bar` (Slice 1) | OK via Slice 1 dependency | OK |
| AC-3 | `announcement-bar` localStorage (Slice 1) | OK via Slice 1 dependency | OK |
| AC-4 | `home.yaml`, `testimonials-block.tsx` | Yes | OK |
| AC-5 | `testimonials-block.tsx` | Yes | OK |
| AC-6 | `featured-collection-block.tsx` | Yes | OK |
| AC-7 | `featured-collection-block.tsx` | Yes | OK |
| AC-8 | `newsletter-signup-block.tsx` | Yes | OK |
| AC-9 | `newsletter-signup-block.tsx` | Yes | OK |
| AC-10 | `newsletter-signup-block.tsx` | Yes | OK |
| AC-11 | `featured-collection-block.tsx` | Yes | OK |
| AC-12 | `lib/blocks/page-config.ts` (Slice 1) | Yes via Slice 1 dependency | OK |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| `TestimonialsData` + `TestimonialsItem` Interfaces | Sec. 5 | Yes | No — `location?` fehlt in Arch-Tabelle | BLOCKED |
| `NewsletterSignupData` Interface | Sec. 5 | Yes | Yes | OK |
| `FeaturedCollectionData` Interface | Sec. 5 | Yes | Yes | OK |
| `featured_collection` data-loader Branch | Sec. 4 | Yes — Promise.all, null-Guard, satisfies | Yes | OK |
| `global.yaml` Struktur | Sec. 8 | Yes | Yes | OK |
| `layout.tsx` Erweiterung | Sec. 9 | Yes | Yes | OK |
| `home.yaml` vollstaendige Datei | Sec. 7 | Yes — alle 7 Sections, kein announcement-bar | Yes | OK |
| Newsletter Submit-Logik | Sec. 3.2 | Yes — Sequenz vollstaendig: Validation -> loading -> console.log -> setTimeout(500ms) -> success | Yes | OK |
| `app/page.tsx` skeletonMap Erweiterung | Sec. 10 | Yes | Yes | OK |
| `registry.ts` Ergaenzungen | Sec. 6 | Yes | Yes | OK |

---

## E) Build Config Sanity Check

N/A — Dieser Slice hat keine Build-Config-Deliverables (vite.config, webpack.config, tsconfig etc.).

---

## F) Test Coverage

| Acceptance Criteria | Test definiert | Test Type | Status |
|--------------------|--------------|-----------|--------|
| AC-1: layout.tsx rendert announcement-bar via global.yaml | Teilweise — `loadGlobalConfig` Test prueft Konfiguration; kein End-to-End-Rendertest fuer layout.tsx | Unit | Akzeptabel (layout.tsx Integration ist durch loadGlobalConfig Test abgedeckt) |
| AC-2: X-Button setzt localStorage + Bar verschwindet | FEHLT — Kein Test in der Testdatei prueft AnnouncementBar Dismiss-Verhalten. AnnouncementBarBlock ist Slice 1 Concern, aber AC-2 ist in diesem Slice definiert. | — | Akzeptabel — AnnouncementBarBlock-Tests sind in Slice 1; AC-2 referenziert Slice-1-Behaviour |
| AC-3: Bar nicht angezeigt wenn localStorage gesetzt (kein Flicker) | FEHLT — kein Test im Spec | Unit | BLOCKED |
| AC-4: 3 Testimonial-Karten im Grid mit Sterne | `TestimonialsBlock: should render the correct number of testimonial cards` | Unit | OK |
| AC-5: 5 gefuellte Sterne + aria-label "5 von 5 Sternen" | `should render the correct number of filled stars for rating 4` (prueft rating 4, nicht 5) + `should render testimonial text and author name` | Unit | OK — `getByLabelText('4 von 5 Sternen')` demonstriert Pattern; AC-5 prueft rating 5 (abgedeckt durch das Pattern) |
| AC-6: Kategorie-Bild, Titel, Beschreibung, 4 Karten | `FeaturedCollectionBlock: should render category name and products` | Unit | OK |
| AC-7: "Alle ansehen" href = `/kategorie/t-shirts` | `should render "Alle ansehen" link pointing to /kategorie/t-shirts` | Unit | OK |
| AC-8: Headline, Input, Button sichtbar | `NewsletterSignupBlock: should render headline, input and submit button in idle state` | Unit | OK |
| AC-9: Inline-Fehler bei ungueltiger E-Mail | `should show inline error for invalid email format on submit` | Unit | OK |
| AC-10: Loading-State dann Erfolgsmeldung | `should show success message after valid submit` + `should disable submit button in loading state` | Unit | OK |
| AC-11: Block ohne Bild rendert Titel + Karten | Implizit in `should render category name and products` (`image: null`) | Unit | OK |
| AC-12: `loadGlobalConfig` gibt `{ sections: [] }` bei fehlendem global.yaml | `loadGlobalConfig: should return empty sections when global.yaml does not exist` | Unit | OK |

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| UI Components | `announcement-bar` (visible/dismissed) | Yes | Yes — global.yaml + layout.tsx Integration | OK |
| UI Components | `newsletter-email` (empty/valid/invalid/submitted) | Yes | Yes — idle/error/loading/success States in NewsletterSignupBlock | OK |
| UI Components | `recommendations-card` (default/hover) | No — Slice 2 Concern | n/a | OK (nicht in Scope) |
| State Machine | Announcement Bar visible/dismissed | Yes | Yes — activiert via global.yaml `dismissible: true` | OK |
| State Machine | Newsletter idle/loading/success/error | Yes | Yes — Sec. 3.2 vollstaendig | OK |
| Transitions | `visible` → Klick X → `dismissed` | Yes | Yes — via Slice 1 AnnouncementBarBlock | OK |
| Business Rules | Announcement Bar ID: gleiche ID = nicht erneut zeigen | Yes | Yes — `id: "launch-2026"`, localStorage-Key `announcement-dismissed-launch-2026` | OK |
| Business Rules | Newsletter Signup: nur UI (kein Backend) | Yes | Yes — nur console.log | OK |
| Data | `id` (required) fuer Announcement Bar | Yes | Yes — `id: "launch-2026"` in global.yaml | OK |
| Data | `text` (required) | Yes | Yes | OK |
| Data | `link` (optional) | Yes | Yes — `link: "/kollektion/sale"` | OK |
| Data | `dismissible` (optional, default true) | Yes | Yes | OK |
| Data | `bg_color` (optional) | Yes | Yes — als `bgColor` (Architecture-konform) | OK |
| UI Layout | Homepage neue Blocks — Reihenfolge: nach category-showcase: testimonials, dann featured-collection, dann newsletter-signup vor usp-bar | Yes | Yes — home.yaml Sec. 7 | OK |
| UI Layout | Featured Collection: "Alle ansehen" Link | Yes | Yes — `/kategorie/{slug}` | OK |

---

## Blocking Issues Summary

### Issue 1: `TestimonialsData` — Feld `location?` nicht in Architecture definiert

**Category:** Schema
**Severity:** BLOCKED

**Slice sagt (Sec. 5, Zeile 362-365):**
```typescript
export interface TestimonialsItem {
  name: string
  text: string
  rating: number
  location?: string   // nicht in Architecture
}
```

**Architecture sagt (architecture.md Zeile 349):**
```
TestimonialsData | items: { name: string, text: string, rating: number }[]
```

**Problem:**
Die Architecture-Tabelle definiert `TestimonialsData.items` ohne das Feld `location`. Der Slice fuegt `location?: string` hinzu, das weder in der Architecture-Typdefinition noch in der Block-Data-Type-Tabelle vorkommt. Per Compliance-Regel muss das Schema mit der Architecture uebereinstimmen.

Das Feld ist inhaltlich sinnvoll (Discovery-Wireframe zeigt "— Maria K., München") und optional, aber die Architecture als authoritative Quelle schreibt es nicht vor.

**Resolution:**
Entweder:
1. Architecture-Tabelle Zeile 349 um `location?: string` erweitern: `items: { name: string, text: string, rating: number, location?: string }[]`
2. Oder `location?` aus dem `TestimonialsItem`-Interface entfernen und die YAML-Beispiele anpassen (kein Location-Feld)

Empfehlung: Option 1 — das Feld ist im Wireframe sichtbar und macht semantisch Sinn. Architecture-Tabelle ist unvollstaendig.

---

### Issue 2: AC-3 — THEN enthaelt nicht-testbare Bedingung; kein entsprechender Test im Spec

**Category:** Test
**Severity:** BLOCKED

**Slice sagt (AC-3):**
```
GIVEN der User hat die Announcement Bar bereits dismissed (localStorage gesetzt)
WHEN die Homepage neu geladen wird
THEN wird die Announcement Bar nicht angezeigt (kein Flicker)
```

**Problem Teil A — THEN nicht messbar:**
"kein Flicker" ist kein maschinell pruefbarer Zustand. Vitest/Testing Library kann pruefen ob ein DOM-Element sichtbar/nicht-sichtbar ist, aber nicht ob es kurzzeitig aufgeflammt ist (Hydration-Flicker). Das macht das AC partiell nicht-testbar.

**Problem Teil B — Kein Test im Spec:**
Im Test-Spec `tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts` gibt es keine Test-Suite die prueft:
- localStorage `announcement-dismissed-launch-2026` gesetzt → AnnouncementBarBlock rendert null
Dies ist ein Slice-1-Verhalten, aber da das AC in Slice 4 definiert ist und Slice 4 die `global.yaml`-Integration liefert, fehlt der Nachweis.

**Resolution:**
1. AC-3 THEN anpassen: "wird die Announcement Bar nicht angezeigt" — das Wort "kein Flicker" entfernen, da nicht testbar.
2. Einen Test in der Testdatei ergaenzen der verifiziert: wenn `localStorage['announcement-dismissed-launch-2026']` gesetzt ist, rendert `AnnouncementBarBlock` (mit der Konfiguration aus `global.yaml`) kein sichtbares Element.

Beispiel-Test:
```typescript
it('should not render announcement bar when already dismissed in localStorage', async () => {
  localStorage.setItem('announcement-dismissed-launch-2026', 'true')
  const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
  const data = { id: 'launch-2026', text: 'Test', dismissible: true }
  const { container } = render(React.createElement(AnnouncementBarBlock, { data }))
  expect(container.firstChild).toBeNull()
  localStorage.clear()
})
```

---

## Recommendations

1. **Issue 1 beheben (bevorzugt):** `architecture.md` Zeile 349 um `location?: string` in `TestimonialsData` erweitern. Das Feld ist in den Wireframes und YAML-Beispielen klar gewuenscht; die Architecture-Tabelle ist verkuerzt und unvollstaendig.

2. **Issue 2 beheben:** AC-3 THEN von "kein Flicker" bereinigen und einen expliziten Test fuer das localStorage-Dismissed-Verhalten in der Testdatei ergaenzen (oder per Kommentar auf den entsprechenden Slice-1-Test referenzieren und AC-3 als "durch Slice 1 abgedeckt" kennzeichnen).

3. **AC-1 GIVEN/WHEN Klarheit:** Die Konfigurationsbedingung `global.yaml konfiguriert` in den GIVEN-Teil verschieben. Aktuell steht sie im WHEN. Nicht blocking, aber verbessert Lesbarkeit.

4. **Positiv:** Alle anderen Aspekte sind exzellent umgesetzt — vollstaendige Code-Examples, klarer Datenfluss, konsistente YAML-Konfiguration, gute Testabdeckung fuer die uebrigen ACs, korrekte Integration mit Slice 1 und Slice 3 Dependencies.

---

## Verdict

**Status:** FAILED

**Blocking Issues:** 2
**Warnings:** 0

**Next Steps:**
- [ ] Issue 1 beheben: `TestimonialsData` — `location?: string` in architecture.md Zeile 349 erganzen ODER aus Slice-Spec entfernen
- [ ] Issue 2 beheben: AC-3 THEN um "kein Flicker" bereinigen + fehlenden Test fuer localStorage-Dismissed-Behavior ergaenzen
- [ ] Re-Run Gate 2 Compliance Check nach Korrekturen
