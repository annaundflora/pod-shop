# Gate 2: Slice 03 Compliance Report

**Gepruefter Slice:** `specs/phase-1/2026-02-27-seed-data/slices/slice-03-motiv-definition.md`
**Pruefdatum:** 2026-02-27
**Architecture:** `specs/phase-1/2026-02-27-seed-data/architecture.md`
**Discovery:** `specs/phase-1/2026-02-27-seed-data/discovery.md`
**Referenz-Prompts:** `seed-data-prompts/prompts.md`
**Vorherige Slices:** keine (Slice 3 hat keine harten Dependencies)

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 31 |
| Warning | 0 |
| Blocking | 0 |

**Verdict:** APPROVED

---

## 0) Inhaltliche Pruefung

### Motiv-Zaehlung: Manuelle Verifikation

Vollstaendige Slug-Zaehlung aus den Tabellen im Slice-Dokument:

| Kategorie | Gezaehlt | Slice-Claim | Uebereinstimmung |
|-----------|----------|-------------|-----------------|
| T-Shirts (tshirt-mediterranean-vibes ... tshirt-croissant-club) | 20 | 20 | Pass |
| Hoodies (hoodie-cat-patting ... hoodie-herzlichen-glückwunsch) | 12 | 12 | Pass |
| Sweatshirts (sweatshirt-happy-cat ... sweatshirt-typewriter-dreams) | 10 | 10 | Pass |
| Tanktops (tanktop-morning-matcha ... tanktop-infinity-bloom) | 8 | 8 | Pass |
| Langarmshirts (langarm-sternzeichen-krebs ... langarm-strick-muster) | 8 | 8 | Pass |
| Taschen (tasche-botanica-garden ... tasche-kindheit-nostalgie) | 10 | 10 | Pass |
| Muetzen & Caps (muetze-sonne-simple ... muetze-biene) | 8 | 8 | Pass |
| Tassen (tasse-monstera-morgen ... tasse-croissant-cafe) | 10 | 10 | Pass |
| Poster & Kunstdrucke (poster-botanical-study ... poster-sonnenblumen-feld) | 10 | 10 | Pass |
| Kissen (kissen-botanica-square ... kissen-rainy-day) | 6 | 6 | Pass |
| Handyhuellen (huelle-celestial-map ... huelle-peach-fuzz) | 8 | 8 | Pass |
| **GESAMT** | **110** | **110** | **Pass** |
| Buttons & Anstecker | 0 | 0 | Pass |

Discovery.md Produktverteilungs-Tabelle listet 11 Kategorien ohne Buttons & Anstecker mit Gesamtsumme 110. Slice korrekt konsistent.

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes | Yes — "GIVEN the slice document" | Yes — "WHEN counting motif entries in the table above" | Yes — "exactly 110 motif entries (20+12+10+8+8+10+8+10+10+6+8 = 110)" mit vollstaendiger Aufschlusselung | Pass |
| AC-2 | Yes | Yes | Yes — "GIVEN any motif description" | Yes — "WHEN reading it" | Yes — drei explizite Pflicht-Bestandteile mit Beispielen: print style identifier, 3+ visual elements, line/fill style descriptor | Pass |
| AC-3 | Yes | Yes | Yes — "GIVEN the motif styles across all products" | Yes — "WHEN reviewing diversity" | Yes — alle 9 Typen explizit namentlich mit Beispiel-Slugs aufgelistet | Pass |
| AC-4 | Yes | Yes | Yes — "GIVEN motifs for Muetzen & Caps (8 products)" | Yes — "WHEN checking complexity" | Yes — drei messbare Teilbedingungen: "embroidery-ready" marker, "max 3 elements", "minimum line width of 2mm" | Pass |
| AC-5 | Yes | Yes | Yes — "GIVEN motifs for Poster & Kunstdrucke (10 products)" | Yes — "WHEN reviewing" | Yes — "all 10 motifs describe standalone artwork ... described as standalone art print suitable for framing" | Pass |

### Muetzen Embroidery Constraint Detail-Check (AC-4)

AC-4 fordert in THEN: "all 8 motifs have the 'embroidery-ready' marker AND explicitly limit to max 3 elements with minimum line width of 2mm"

| Slug | "embroidery-ready" | Min. Linienbreite | Max-Elemente | AC-4 erfuellt? |
|------|--------------------|------------------|--------------|----------------|
| muetze-sonne-simple | "embroidery-ready design" | "minimum 2mm line width" | "three elements max" | Pass |
| muetze-blume-minimal | "embroidery-ready design" | "minimum 2mm line width" | "max three elements" | Pass |
| muetze-good-day | "embroidery-ready design" | "minimum 2mm stroke width" | "max 1 element (text block)" | Pass |
| muetze-crescent-star | "embroidery-ready design" | "minimum 2mm line width" | "max 3 elements" | Pass |
| muetze-wave | "embroidery-ready design" | "minimum 3mm" (> 2mm) | "max 1 element" | Pass |
| muetze-mountain | "embroidery-ready design" | "minimum 2mm line width" | "three elements" | Pass |
| muetze-herz | "embroidery-ready design" | "minimum 2mm line width" | "max 2 elements" | Pass |
| muetze-biene | "embroidery-ready design" | "minimum 2mm line width" | "max 3 elements" | Pass |

Alle 8 Muetzen-Motive erfuellen vollstaendig alle drei Teilbedingungen aus AC-4. Pass.

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| Beispiel 1: product-catalog.json Produkt-Objekt mit motif Feld | Yes — `slug`, `name`, `category`, `price`, `motif` alle als strings, konsistent mit architecture.md Dateistruktur | N/A (JSON, keine Imports) | N/A (Daten, keine Funktion) | Yes — `product.motif: string` stimmt mit architecture.md "catalog[i].motif" Interface ueberein | Pass |
| Beispiel 2: generate-images.mjs CATEGORY_TEMPLATES Snippet | Yes — `product.motif` Zugriff, `imageIndex` Steuerung, cropVariants Array korrekt | N/A (Snippet ohne Modul-Imports, als Vorschau fuer Slice 4 deklariert) | Yes — `(product, imageIndex) => string` konsistent mit architecture.md Datenfluss | Yes — zeigt `{product.motif}` Einbettungs-Muster wie in architecture.md beschrieben | Pass |
| Beispiel 3: Vier Motiv-Strings (T-Shirt, Hoodie, Muetze, Poster) | Yes — T-Shirt/Line-Art, Hoodie/Back-Print, Muetze/Embroidery, Poster/Standalone-Art repraesentieren alle Constraint-Varianten | N/A (plain text) | N/A | Yes — demonstriert "embroidery-ready" Token und "standalone art print" Token, die generate-images.mjs in Slice 4 als Signal-Words verwendet | Pass |

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | Korrekt — Repo nutzt Next.js + Vitest (frontend/package.json bestaetigt) | Pass |
| Commands vollstaendig | 3 definiert: Test, Integration, Acceptance — alle identisch: `pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts` | 3 Commands erwartet | Pass |
| Start-Command | `pnpm dev` | Passt zum erkannten Next.js-Stack | Pass |
| Health-Endpoint | `http://localhost:3000/api/health` | Passt zu Next.js auf Port 3000 | Pass |
| Mocking-Strategy | `no_mocks` | Korrekt — Tests validieren statische EXPECTED_COUNTS Konstanten und Slug-Listen, keine externen Abhaengigkeiten | Pass |

---

## A) Architecture Compliance

### Schema Check

| Arch Field | Arch Type | Slice Spec | Status | Issue |
|------------|-----------|------------|--------|-------|
| `product.motif` (product-catalog.json) | `string` — architecture.md: "Build prompts (catalog + category template + motif)" | `string` — Motiv-Beschreibungs-Text in Anfuehrungszeichen, alle 110 Eintraege konsistent | Pass | — |
| Replicate PredictionInput.prompt | `prompt <= 2000 Zeichen` (architecture.md DTO-Tabelle) | Motiv-Strings <= 400 Zeichen (Constraints-Section), Kategorie-Template ~1200-1600 Zeichen — Summe bleibt unter 2000 | Pass | — |
| Produktanzahl gesamt | 110 (architecture.md Scope: "110 handkuratierte POD-Produkte") | 110 Motiv-Eintraege in 11 Kategorien | Pass | — |
| Produkt-Slug Format | Lowercase, alphanumerisch + Bindestriche (architecture.md: Dateipfad `{product-slug}`) | Alle Slugs lowercase mit Bindestrichen, Umlaute korrekt substituiert (muetze-, langarm-, huelle-, tasse-) | Pass | — |

### API Check

N/A — Slice 3 macht keine API-Aufrufe. Motiv-Strings sind Daten-Input fuer Slice 4 (generate-images.mjs). Korrekt abgegrenzt.

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| Kein API-Key im Dokument | REPLICATE_API_KEY nur in `.env`, nie committet | Slice 3 enthaelt ausschliesslich Motiv-Text-Strings, kein Key | Pass |
| Input handkuratiert (keine User-Eingabe) | WP sanitizes on save | Alle 110 Motiv-Strings sind statischer Spec-Text | Pass |

---

## B) Wireframe Compliance

N/A — Discovery.md: "Wireframes: -- (kein UI-Feature)". Slice 3 ist reine Daten-Spezifikation.

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| Keine harten Dependencies | — | Korrekt deklariert: "Requires From Other Slices: Keine harten Dependencies" | Pass |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| 110 Motiv-Beschreibungen (Spec-Dokument) | Slice 1 (Produktkatalog-Definition) | Interface `product.motif: string` vollstaendig dokumentiert | Pass |
| `"motif"` Feld in product-catalog.json (via Slice 1) | Slice 4 (Bild-Generierung Script) | Interface `catalog[i].motif` → Replicate-Prompt-Einbettung vollstaendig dokumentiert | Pass |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|------------------|--------------|--------|
| 110 Motiv-Strings (Spec-Dokument) | `specs/phase-1/2026-02-27-seed-data/slices/slice-03-motiv-definition.md` | Yes — dieses Dokument selbst ist Deliverable in DELIVERABLES_START/END | Slice 3 | Pass |
| `"motif"` Feld → `scripts/product-catalog.json` | `scripts/product-catalog.json` | Korrekt an Slice 1 delegiert — DELIVERABLES_START: "Zu integrieren (durch Slice 1): scripts/product-catalog.json" | Slice 1 | Pass |
| Prompt-Kompositions-Muster (Code Example 2) | `scripts/generate-images.mjs` | Korrekt als Vorschau fuer Slice 4 deklariert — actual deliverable liegt in Slice 4 | Slice 4 | Pass |

### AC-Deliverable-Konsistenz

Keine Pages als Deliverables. Slice 3 referenziert ausschliesslich eigene Spec-Dokument-Inhalte in ACs.

| AC # | Referenced Artefact | In Deliverables? | Status |
|------|---------------------|-------------------|--------|
| AC-1 bis AC-5 | Motiv-Beschreibungen in diesem Dokument | Yes — Dokument selbst ist Deliverable | Pass |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| product-catalog.json Produkt-Objekt mit `"motif"` Feld | Beispiel 1 | Yes — alle wesentlichen Felder (`slug`, `name`, `category`, `price`, `motif`) vorhanden | Yes — konsistent mit architecture.md product-catalog.json Dateistruktur | Pass |
| generate-images.mjs `CATEGORY_TEMPLATES` Snippet | Beispiel 2 | Partial (T-Shirts Template als Beispiel) — ausdruecklich als Vorschau fuer Slice 4 deklariert, nicht als abgeschlossene Implementierung | Yes — `product.motif` Zugriff konsistent mit architecture.md Datenfluss | Pass |
| Vier Motiv-Strings (T-Shirt, Hoodie, Muetze, Poster) | Beispiel 3 | Yes — vier vollstaendige Motiv-Strings, repraesentieren alle Spezial-Kategorien | Yes — demonstriert Print-Stil, Sujet, Linienstil fuer alle Constraint-Varianten | Pass |

Hinweis: Der Slice benennt in der Code-Beispiel-Tabelle "3 vollstaendige Beispiel-Motiv-Strings" (Tabellenzeile), liefert faktisch vier Strings (T-Shirt, Hoodie, Muetze, Poster). Mehrlieferung ist kein Problem.

---

## E) Build Config Sanity Check

N/A — Slice 3 hat keine Build-Config-Deliverables. Nur Spec-Dokument und Vitest-Testdatei.

---

## F) Test Coverage

| Acceptance Criteria | Test Definiert | Test-Typ | Status |
|--------------------|----------------|----------|--------|
| AC-1: exakt 110 Produkte mit korrekter Kategorie-Verteilung | Yes — `expect(TOTAL_EXPECTED).toBe(110)` + alle 11 Kategorien einzeln via `expect(EXPECTED_COUNTS['X']).toBe(N)` | Vitest Unit (Spec-Validierung via EXPECTED_COUNTS Summe) | Pass |
| AC-1 (Buttons & Anstecker ausgeschlossen) | Yes — `expect(EXPECTED_COUNTS['Buttons & Anstecker']).toBeUndefined()` | Vitest Unit | Pass |
| AC-2: Motiv-String Mindest-Bestandteile | `it.todo` — aufgeschoben bis `product-catalog.json` aus Slice 1 existiert | Pending Integration-Test | Pass (korrekt modelliert: Spec-only Phase, todo-Tests explizit benannt) |
| AC-3: Alle 9 Motiv-Typen vertreten | Yes — `expect(REQUIRED_MOTIF_TYPES).toHaveLength(9)` + je `requiredSet.has(X)` Check fuer alle 9 Typen | Vitest Unit | Pass |
| AC-4: Stickerei-Constraints Muetzen (Zaehlung) | Yes — `expect(muetzenSlugs).toHaveLength(8)` + `expect(EXPECTED_COUNTS['Muetzen & Caps']).toBe(8)` | Vitest Unit | Pass |
| AC-4: Stickerei-Constraints Muetzen (Motiv-Inhalt) | `it.todo` — aufgeschoben bis product-catalog.json existiert | Pending Integration-Test | Pass (korrekt aufgeschoben) |
| AC-5: Poster Standalone Art (Zaehlung) | Yes — `expect(posterSlugs).toHaveLength(10)` + `expect(EXPECTED_COUNTS['Poster & Kunstdrucke']).toBe(10)` | Vitest Unit | Pass |
| AC-5: Poster Standalone Art (Inhalt) | `it.todo` — aufgeschoben bis product-catalog.json existiert | Pending Integration-Test | Pass (korrekt aufgeschoben) |

Das Test-File `tests/slices/seed-data/slice-03-motiv-definition.test.ts` existiert noch nicht im Repository — es ist als Deliverable dieses Slices deklariert und wird vom Implementor angelegt. Dies ist das erwartete Verhalten fuer einen unimplementierten Slice.

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| Motiv-Typen Tabelle | Line-Art Illustration | Yes | Yes — vertreten in T-Shirts (tshirt-mediterranean-vibes, tshirt-yoga-sun, tshirt-paris-skyline), Tanktops (tanktop-sun-salute, tanktop-pacific-waves), Taschen (tasche-line-art-paris), Handyhuellen (huelle-wave-art), Muetzen (muetze-wave) | Pass |
| Motiv-Typen Tabelle | Cute Characters | Yes | Yes — tshirt-otter-coffee, tshirt-frog-mushroom, tshirt-fox-nap, hoodie-cat-patting, hoodie-sleepy-bear, hoodie-bee-garden, hoodie-duck-umbrella, tasse-otter-morning, tasse-frog-tea-party, poster-cat-illustration, kissen-color-block-cats, muetze-biene | Pass |
| Motiv-Typen Tabelle | Retro-Schriftzug + Illustration | Yes | Yes — tshirt-good-vibes-retro, hoodie-stay-cozy, sweatshirt-wanderlust, sweatshirt-grosse-energie, tasche-kindheit-nostalgie | Pass |
| Motiv-Typen Tabelle | Flat Vector Illustration | Yes | Yes — tshirt-bücherregal-cozy, tshirt-picknick-scene, sweatshirt-cat-vase, tasche-bookworm, tasche-market-day, kissen-geometric-floral, muetze-mountain, poster-sonnenblumen-feld, huelle-peach-fuzz | Pass |
| Motiv-Typen Tabelle | Botanische/Florale Motive | Yes | Yes — groesste Gruppe; vertreten in allen 11 Kategorien | Pass |
| Motiv-Typen Tabelle | Typografie | Yes | Yes — tshirt-stay-weird, tshirt-espresso-yourself, tshirt-wildflower-free, sweatshirt-brot-und-liebe, tanktop-bloom-where-planted, tasse-kaffee-erst, tasse-guten-morgen, poster-typografie-zitat, muetze-good-day, muetze-herz, hoodie-herzlichen-glückwunsch | Pass |
| Motiv-Typen Tabelle | Celestial/Mystisch | Yes | Yes — tshirt-celestial-moon, tshirt-crystal-energy, hoodie-nordic-snowflake, hoodie-midnight-forest, langarm-sternzeichen-krebs, langarm-constellation, langarm-mondphasen-kreis, langarm-saturn-dreams, muetze-sonne-simple, muetze-crescent-star, tasse-sternzeichen-waage, tasse-boho-sun, poster-moon-phases, poster-mondschein-see, poster-crystal-grid, kissen-moonlit-forest, huelle-celestial-map, huelle-moon-gradient, huelle-tiny-stars | Pass |
| Motiv-Typen Tabelle | Food & Drinks | Yes | Yes — tshirt-matcha-morning, tshirt-croissant-club, tanktop-morning-matcha, tanktop-lemon-fresh, tanktop-strawberry-fields, tasse-matcha-illustration, tasse-croissant-cafe | Pass |
| Motiv-Typen Tabelle | Cottage/Cozy | Yes | Yes — hoodie-mushroom-forest, hoodie-rain-window, hoodie-cottage-reading, langarm-pine-forest, langarm-strick-muster, kissen-cottage-blooms, kissen-rainy-day | Pass |
| Produktverteilung | Buttons & Anstecker (0 Produkte) | Yes | Yes — korrekt nicht im Slice enthalten; Test explizit: `toBeUndefined()` | Pass |
| Business Rules | Muetzen = Stickerei (max 3 Elemente, min 2mm Linien) | Yes | Yes — alle 8 Muetzen-Motive mit "embroidery-ready" + Element-Limit + "minimum 2mm line width" (oder staerker) | Pass |
| Business Rules | Poster = standalone art, kein Lifestyle-Template | Yes | Yes — alle 10 Poster als "standalone art print" markiert, kein Person/Produkt-Kontext | Pass |
| Business Rules | Kleidung = bedruckbar, motivgeeignet | Yes | Yes — T-Shirts, Hoodies, Sweatshirts, Tanktops, Langarmshirts haben jeweils fuer Textildruck geeignete Motivbeschreibungen | Pass |
| Prompt-Strategie (architecture.md) | Kategorie-Template zeigt Crop-Varianten pro Bild-Index | Yes | Yes — Beispiel 2 zeigt `cropVariants[imageIndex]` Pattern fuer T-Shirts | Pass |
| Prompt-Strategie | Muetzen "Stickerei/Print sichtbar" | Yes | Yes — "embroidery-ready" Token signalisiert generate-images.mjs Kategorie-Template-Wechsel (beschrieben in Constraints-Section) | Pass |
| Prompt-Strategie | Poster "Gerahmt an Wand" | Yes | Yes — "standalone art print" Token triggert Art-Print-Template ohne Lifestyle-Person (beschrieben in Constraints-Section) | Pass |

---

## Spezifische Pruefpunkte (gemaess Aufgabe)

### 1. Exakt 110 Motiv-Eintraege (keine Buttons & Anstecker)

Manuelle Zaehlung abgeschlossen. Ergebnis: 110 Eintraege in 11 Kategorien. Discovery Produktverteilung = 110 ohne Buttons & Anstecker. Konsistent. Pass.

### 2. AC-1 THEN exakt "110 motif entries" ohne Widerspruch

AC-1 THEN: "the catalog contains exactly 110 motif entries (20+12+10+8+8+10+8+10+10+6+8 = 110)"
Arithmetikpruefung: 20+12=32, +10=42, +8=50, +8=58, +10=68, +8=76, +10=86, +10=96, +6=102, +8=110. Korrekt.
Definition of Done (Zeile 535): "20+12+10+8+8+10+8+10+10+6+8 = 110, 11 Kategorien" — konsistent.
Kein Widerspruch. Pass.

### 3. TOTAL_EXPECTED im Test = 110

```typescript
const TOTAL_EXPECTED = Object.values(EXPECTED_COUNTS).reduce((a, b) => a + b, 0)
// = 20+12+10+8+8+10+8+10+10+6+8 = 110
expect(TOTAL_EXPECTED).toBe(110)  // Zeile 351 im Test
```

TOTAL_EXPECTED wird dynamisch berechnet. Wert = 110. `expect(...).toBe(110)` korrekt. Pass.

### 4. Alle 8 Muetzen-Motive: embroidery-ready + max X elements + minimum 2mm line width

Bereits detailliert geprueft in AC-Qualitaets-Check (Abschnitt 0). Alle 8 von 8 erfuellen alle drei Constraints. Pass.

### 5. Integration Command zeigt auf existierendes Test-File

Integration Command: `pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts`

Das Test-File ist als Deliverable dieses Slices deklariert (DELIVERABLES_START: "tests/slices/seed-data/slice-03-motiv-definition.test.ts"). Der Command ist korrekt — er zeigt auf das Deliverable dieses Slices, das vom Implementor als Teil der Implementierung angelegt wird. Kein nicht-existierendes externes Script referenziert. Pass.

### 6. Mindestens 7 Motiv-Typen aus discovery.md

Discovery.md definiert 9 Motiv-Typen. Alle 9 sind im Slice vertreten (siehe Discovery Compliance Tabelle oben). Die Anforderung "mindestens 7" aus dem Kontext-Abschnitt ist uebertroffen. AC-3 fordert alle 9 und ist erfuellt. Pass.

---

## Blocking Issues Summary

Keine Blocking Issues.

---

## Recommendations

1. Die Formulierung im "Kontext & Ziel" Abschnitt ("mindestens 7 der 9 Motiv-Typen") und AC-3 ("all 9 motif types") sind leicht divergent. Da de facto alle 9 vertreten sind und AC-3 als verbindlicher THEN-Check gilt, entsteht kein Problem. Eine Angleichung des Kontext-Satzes auf "alle 9 Motiv-Typen" wuerde Konsistenz herstellen — non-blocking.

2. Die `it.todo` Bloecke fuer AC-2, AC-4 Inhalt und AC-5 Inhalt sind korrekt aufgeschoben. Nach Lieferung von Slice 1 (product-catalog.json) sollten diese konkretisiert werden: String-Checks auf "embroidery-ready", "standalone art print" und Motiv-Mindestlaenge.

3. Code Example 2 (CATEGORY_TEMPLATES) ist als Vorschau fuer Slice 4 deklariert und nicht als fertige Implementierung. Slice 4 sollte diesen Snippet als Ausgangspunkt nutzen und um alle Kategorien (Hoodies, Taschen, Poster etc.) erweitern.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 0

**Naechste Schritte:**
- Slice 3 ist bereit fuer Implementierung
- Implementor legt `frontend/tests/slices/seed-data/slice-03-motiv-definition.test.ts` an (Deliverable)
- Slice 1 traegt alle 110 Motiv-Strings als `"motif"` Feld in `scripts/product-catalog.json` ein
- Slice 4 verwendet `catalog[i].motif` als Kern-Input fuer Replicate-Prompt-Komposition

---

VERDICT: APPROVED
