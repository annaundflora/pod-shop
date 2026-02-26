# Orchestrator Configuration: Shop Completeness Phase 1

**Integration Map:** `integration-map.md`
**E2E Checklist:** `e2e-checklist.md`
**Generated:** 2026-02-26
**Feature:** Shop Completeness — Blocks & Pages

---

## Pre-Implementation Gates

```yaml
pre_checks:
  - name: "Gate 1: Architecture Compliance"
    file: "specs/phase-1/2026-02-25-shop-completeness/compliance-architecture.md"
    required: "Verdict == APPROVED"

  - name: "Gate 2: Alle Slices APPROVED"
    files:
      - "specs/phase-1/2026-02-25-shop-completeness/slices/slice-01-cross-page-infrastruktur.md"
      - "specs/phase-1/2026-02-25-shop-completeness/slices/slice-02-produkt-page-enhancements.md"
      - "specs/phase-1/2026-02-25-shop-completeness/slices/slice-03-kategorie-page-enhancements.md"
      - "specs/phase-1/2026-02-25-shop-completeness/slices/slice-04-homepage-enhancements.md"
      - "specs/phase-1/2026-02-25-shop-completeness/slices/slice-05-suchseite.md"
      - "specs/phase-1/2026-02-25-shop-completeness/slices/slice-06-neue-pages.md"
    required: "ALL Dependencies[] vollstaendig aufgeloest und APPROVED"

  - name: "Gate 3: Integration Map Valid"
    file: "specs/phase-1/2026-02-25-shop-completeness/integration-map.md"
    required: "Verdict == READY FOR ORCHESTRATION AND Missing Inputs == 0"
```

---

## Implementation Order (Wave-basiert)

### Wave 1 — Foundation (sequenziell, kein Parallel-Lauf)

| Order | Slice | Name | Abhaengig Von | Parallel? |
|-------|-------|------|---------------|-----------|
| 1 | slice-01 | Cross-Page Infrastruktur | — (keine) | Nein (Foundation) |

**Begruendung:** Slice 01 stellt die 6 Block-Komponenten, 6 TypeScript-Interfaces, `loadGlobalConfig()` und die `registry.ts`-Erweiterungen bereit, von denen alle anderen Slices abhaengen. Kein anderer Slice kann vor Abschluss von Slice 01 starten.

**Blockierend wenn Slice 01 fehlschlaegt:** Alle anderen Slices koennen nicht starten. Gesamt-Rollback erforderlich.

---

### Wave 2 — Feature-Slices (parallel, unabhaengig voneinander)

| Order | Slice | Name | Abhaengig Von | Parallel? |
|-------|-------|------|---------------|-----------|
| 2a | slice-02 | Produkt-Page Enhancements | slice-01 | Ja (parallel mit slice-03) |
| 2b | slice-03 | Kategorie-Page Enhancements | slice-01 | Ja (parallel mit slice-02) |

**Begruendung:** Slice 02 und Slice 03 haben keine gegenseitige Abhaengigkeit. Slice 02 benoetigt nur `TrustBadgesBlock` aus Slice 01. Slice 03 benoetigt `PaginationBlock`, `SortBarBlock`, `BreadcrumbBlock`, `EmptyStateBlock` aus Slice 01.

**Wichtig fuer Shared Files in Wave 2:**

```
Modifikationen an shared Files durch Slice 02 und Slice 03 muessen merge-sicher sein:

lib/blocks/types.ts:
  Slice 02 fuegt hinzu: ProductReviewsResult, ReviewEdge, WriteReviewInput
  Slice 03 fuegt hinzu: PaginatedProductsResult, PaginationMeta,
                         WooCommerceLoaderParams-Erweiterung (page, perPage, sort, search, source)
  -> Kein Konflikt (verschiedene Interfaces, verschiedene Abschnitte)

lib/graphql/queries.ts:
  Slice 02 fuegt hinzu: GET_PRODUCT_REVIEWS, GET_RELATED_PRODUCTS,
                         GET_BESTSELLER_PRODUCTS, GET_PRODUCTS_BY_IDS, GET_PRODUCT_CATEGORY
  Slice 03 fuegt hinzu: GET_PRODUCTS_PAGINATED, GET_CATEGORY_META
  -> Kein Konflikt (verschiedene Export-Namen)

lib/blocks/data-loaders.ts:
  Slice 02 fuegt hinzu: product_reviews Branch, product_recommendations Branch
  Slice 03 fuegt hinzu: products_by_category Branch (paginated), category_meta Branch,
                         buildOrderby() Funktion
  -> Kein Konflikt (verschiedene else-if Branches)

lib/blocks/registry.ts:
  Slice 02 fuegt hinzu: product-reviews, product-recommendations
  Slice 03 fuegt hinzu: (keine neuen Block-Registrierungen in Slice 03)
  -> Kein Konflikt

lib/graphql/mutations.ts:
  Slice 02 erstellt neue Datei (existiert noch nicht)
  Slice 03: keine Mutations
  -> Kein Konflikt
```

**Blockierend wenn Slice 02 fehlschlaegt:** Nur Slice 02-Deliverables betroffen. Slice 03, 04, 05, 06 koennen normal fortfahren (Slice 02 hat keine Downstream-Consumers).

**Blockierend wenn Slice 03 fehlschlaegt:** Slices 04, 05, 06 koennen nicht starten (alle drei benoetigen Slice 03 Outputs).

---

### Wave 3 — Integration-Slices (parallel, alle abhaengig von Wave 1+2)

| Order | Slice | Name | Abhaengig Von | Parallel? |
|-------|-------|------|---------------|-----------|
| 3a | slice-04 | Homepage Enhancements | slice-01, slice-03 | Ja (parallel mit 05, 06) |
| 3b | slice-05 | Suchseite | slice-01, slice-03 | Ja (parallel mit 04, 06) |
| 3c | slice-06 | Neue Pages | slice-01, slice-03 | Ja (parallel mit 04, 05) |

**Begruendung:** Slice 04, 05, 06 benoetigen alle Outputs von Slice 01 und Slice 03. Sie haben untereinander keine direkten Abhaengigkeiten (Slice 05 liefert ggf. `SearchBarBlock` fuer Slice 06, aber Slice 06's Dependency-Deklaration umfasst das nicht als Pflicht).

**Wichtig fuer Shared Files in Wave 3:**

```
lib/blocks/registry.ts:
  Slice 04 fuegt hinzu: testimonials, newsletter-signup, featured-collection
  Slice 05 fuegt hinzu: search-bar, search-results
  Slice 06 fuegt hinzu: collection-header, order-confirmation
  -> Alle additiv, kein Konflikt wenn Agent-Commits sequenziell gemergt werden

lib/blocks/types.ts:
  Slice 04 fuegt hinzu: TestimonialsData, NewsletterSignupData, FeaturedCollectionData
  Slice 05 fuegt hinzu: SearchBarData, SortBarData-Erweiterung (currentQuery)
  Slice 06 fuegt hinzu: CollectionHeaderData, OrderConfirmationData
  -> Additiv; SortBarData-Erweiterung von Slice 05 muss nach Slice 01 erfolgen

lib/blocks/data-loaders.ts:
  Slice 04 fuegt hinzu: featured_collection Branch
  Slice 05 fuegt hinzu: search_products Branch
  Slice 06 prueft/ergaenzt: category_meta Branch (wenn Slice 03 ihn nicht vollstaendig implementiert hat)
  -> Alle additiv, kein Konflikt

app/layout.tsx:
  Slice 04 modifiziert: loadGlobalConfig() + global sections render
  Slices 05, 06: Kein Zugriff auf layout.tsx
  -> Kein Konflikt

app/page.tsx:
  Slice 04 modifiziert: skeletonMap Erweiterung
  Slices 05, 06: Kein Zugriff auf app/page.tsx
  -> Kein Konflikt

components/layout/header.tsx:
  Slice 05 modifiziert: Suchicon hinzufuegen
  Slices 04, 06: Kein Zugriff auf header.tsx
  -> Kein Konflikt

components/blocks/sort-bar-block.tsx:
  Slice 05 modifiziert: currentQuery unterstuetzen (ruckwaertskompatibel)
  -> Nur Slice 05 modifiziert diese Datei in Wave 3
```

**Blockierend wenn Slice 04 fehlschlaegt:** Nur Homepage-Features betroffen. Slices 05, 06 koennen weiterhin abgeschlossen werden.

**Blockierend wenn Slice 05 fehlschlaegt:** Nur Suchseite betroffen. Slices 04, 06 koennen weiterhin abgeschlossen werden.

**Blockierend wenn Slice 06 fehlschlaegt:** Nur Neue Pages betroffen. Slices 04, 05 koennen weiterhin abgeschlossen werden.

---

## Test-Befehl pro Slice

| Slice | Exakter Test-Befehl | Acceptance-Befehl (Verbose) |
|-------|---------------------|-----------------------------|
| slice-01 | `cd frontend && pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts` | `cd frontend && pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts --reporter=verbose` |
| slice-02 | `cd frontend && pnpm test tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts` | `cd frontend && pnpm test tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts --reporter=verbose` |
| slice-03 | `cd frontend && pnpm test tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts` | `cd frontend && pnpm test tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts --reporter=verbose` |
| slice-04 | `cd frontend && pnpm test tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts` | `cd frontend && pnpm test tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts --reporter=verbose` |
| slice-05 | `cd frontend && pnpm test tests/slices/shop-completeness/slice-05-suchseite.test.ts` | `cd frontend && pnpm test tests/slices/shop-completeness/slice-05-suchseite.test.ts --reporter=verbose` |
| slice-06 | `cd frontend && pnpm test tests/slices/shop-completeness/slice-06-neue-pages.test.ts` | `cd frontend && pnpm test tests/slices/shop-completeness/slice-06-neue-pages.test.ts --reporter=verbose` |
| Alle | `cd frontend && pnpm test tests/slices/shop-completeness/` | `cd frontend && pnpm test tests/slices/shop-completeness/ --reporter=verbose` |

---

## Post-Slice Validation

Fuer jeden abgeschlossenen Slice:

```yaml
validation_steps:

  - step: "1. Deliverables Check"
    description: "Pruefe ob alle Dateien zwischen DELIVERABLES_START und DELIVERABLES_END existieren"
    action: |
      FOR each file in DELIVERABLES_START ... DELIVERABLES_END of slice-NN.md:
        assert file_exists(file)
        IF NOT exists: BLOCK — Agent muss fehlende Datei nachliefern
    note: "Stop-Hook prueft automatisch"

  - step: "2. Unit Tests"
    description: "Slice-spezifische Tests ausfuehren"
    action: "Exakten Test-Befehl aus Tabelle 'Test-Befehl pro Slice' ausfuehren"
    failure_action: "Agent muss Tests gruenschalten bevor naechster Slice startet"

  - step: "3. TypeScript Build"
    description: "Keine neuen TypeScript-Fehler eingefuehrt"
    action: "cd frontend && pnpm build 2>&1 | grep 'error TS'"
    failure_action: "TypeScript-Fehler muessen vor naechstem Slice behoben werden"

  - step: "4. Integration Points"
    description: "Ausgaben dieses Slices sind fuer abhangige Slices zugaenglich"
    action: |
      Pruefe per grep ob alle Outputs aus 'Provides To Other Slices' exportiert sind:
        - Komponenten: grep -r "export function {ComponentName}" frontend/components/
        - Interfaces: grep -r "export interface {InterfaceName}" frontend/lib/blocks/types.ts
        - Queries: grep -r "export const {QUERY_NAME}" frontend/lib/graphql/queries.ts
        - Funktionen: grep -r "export function {functionName}" frontend/lib/blocks/
    reference: "integration-map.md → Connections"

  - step: "5. Slice-01 spezifisch: Registry-Check"
    only_for: "slice-01"
    action: |
      node -e "
        const { resolveBlock } = require('./frontend/lib/blocks/registry');
        ['announcement-bar','breadcrumb','trust-badges','pagination','sort-bar','empty-state']
          .forEach(t => { const c = resolveBlock(t); if (!c) throw new Error(t + ' not in registry'); });
        console.log('All 6 blocks registered');
      "

  - step: "6. Slice-03 spezifisch: PaginatedProductsResult Export-Check"
    only_for: "slice-03"
    action: |
      grep -n "export.*PaginatedProductsResult\|export.*PaginationMeta\|export.*GET_PRODUCTS_PAGINATED\|export.*GET_CATEGORY_META" \
        frontend/lib/blocks/types.ts frontend/lib/graphql/queries.ts
    expected: "Alle 4 Exporte gefunden"
```

---

## Shared-File Merge-Reihenfolge

Die folgende Reihenfolge gilt fuer Slices die in der gleichen Wave parallel laufen und shared Files modifizieren. Der Orchestrator muss sicherstellen, dass Commits an shared Files sequenziell erfolgen (kein git-Konflikt).

### `lib/blocks/registry.ts`

```
Reihenfolge der Erweiterungen:
  1. Slice 01: +6 Block-Eintraege (announcement-bar, breadcrumb, trust-badges, pagination, sort-bar, empty-state)
  2. Slice 02: +2 Block-Eintraege (product-reviews, product-recommendations)
     [Wave 2, parallel, aber Registry-Append ist atomar]
  3. Slice 04: +3 Block-Eintraege (testimonials, newsletter-signup, featured-collection)
     [Wave 3]
  4. Slice 05: +2 Block-Eintraege (search-bar, search-results)
     [Wave 3]
  5. Slice 06: +2 Block-Eintraege (collection-header, order-confirmation)
     [Wave 3]

Gesamt nach allen Waves: +15 neue Block-Typen (alle additiv, kein Ueberschreiben)

Merge-Strategie: Jeder Slice-Agent nutzt einen eigenen Git-Branch und oeffnet einen PR.
  Merge-Reihenfolge: slice-01 → slice-02/03 (einer nach dem anderen) → slice-04/05/06 (einer nach dem anderen)
  Bei Auto-Merge: Squash-Commits verwenden; kein Rebase auf unfertigen Branches.
```

### `lib/blocks/types.ts`

```
Reihenfolge der Erweiterungen:
  Wave 1 (Slice 01):
    + AnnouncementBarData, BreadcrumbData, TrustBadgeData, PaginationData,
      SortBarData, EmptyStateData, SortOption
    + WooCommerceLoaderParams: products_paginated query type

  Wave 2 (Slice 02 || Slice 03 — merge nach Wave 2):
    Slice 02: + ProductReviewsResult, ReviewEdge, WriteReviewInput,
                ProductRecommendationsParams
    Slice 03: + PaginatedProductsResult, PaginationMeta
              + WooCommerceLoaderParams: page?, perPage?, sort?, search?, source?, productSlug?, customIds?

  Wave 3 (Slice 04 || Slice 05 || Slice 06 — merge nach Wave 3):
    Slice 04: + TestimonialsData, TestimonialsItem, NewsletterSignupData, FeaturedCollectionData
              + WooCommerceLoaderParams.query: 'featured_collection'
    Slice 05: + SearchBarData
              + SortBarData.currentQuery?: string (EXTENSION der Slice-01-Definition!)
              + WooCommerceLoaderParams.query: 'search_products'
    Slice 06: + CollectionHeaderData, OrderConfirmationData
              + WooCommerceLoaderParams.query: 'category_meta' (falls noch nicht in Slice 01)

KRITISCH: Slice 05 modifiziert SortBarData (von Slice 01 definiert).
  Diese Modifikation muss NACH dem Merge von Slice 01 erfolgen.
  SortBarData.currentQuery ist optional (kein Breaking Change).
```

### `lib/blocks/data-loaders.ts`

```
Reihenfolge der Branch-Hinzufuegungen (jeder Branch ist ein else-if Block):
  1. Bestehend (vor diesem Feature): featured_products, product_categories,
                                      product_by_slug (unveraendert!)
  2. Slice 02 (Wave 2): + product_reviews Branch
                         + product_recommendations Branch
  3. Slice 03 (Wave 2): + products_by_category Branch (ERSETZEN: war ungepaginiert)
                         + category_meta Branch (NEU)
                         + buildOrderby() Hilfsfunktion (intern, am Anfang der Datei)

  KRITISCH Wave 2: Slice 03 ERSETZT den bestehenden products_by_category Branch
  (der bestehende Branch ist ungepaginiert und wird durch den paginierten ersetzt).
  Slice 02 und Slice 03 modifizieren VERSCHIEDENE Branches — kein Konflikt.

  4. Slice 04 (Wave 3): + featured_collection Branch
  5. Slice 05 (Wave 3): + search_products Branch
  6. Slice 06 (Wave 3): + category_meta Branch pruefen/ergaenzen
                           (wenn Slice 03 ihn nicht vollstaendig implementiert hat)

buildOrderby() in Wave 2 von Slice 03 implementiert — Slice 05 NUTZT diese Funktion.
buildOrderby() darf NICHT noch einmal von Slice 05 implementiert werden.
Slice 05-Agent muss prufen ob buildOrderby() bereits exportiert/zugaenglich ist.
```

### `lib/graphql/queries.ts`

```
Reihenfolge der Query-Exports:
  1. Bestehend: GET_PRODUCT, GET_PRODUCTS, GET_CATEGORIES, etc. (unveraendert)
  2. Slice 02 (Wave 2): + GET_PRODUCT_REVIEWS, GET_RELATED_PRODUCTS,
                           GET_BESTSELLER_PRODUCTS, GET_PRODUCTS_BY_IDS, GET_PRODUCT_CATEGORY
  3. Slice 03 (Wave 2): + GET_PRODUCTS_PAGINATED, GET_CATEGORY_META
  (Slices 04-06: keine neuen Queries in queries.ts)

Merge-Strategie: Beide Slices (02, 03) koennen parallel schreiben — unterschiedliche
Export-Namen, kein Konflikt. Merge-Order: 02 dann 03 (oder umgekehrt, beide funktionieren).
```

---

## E2E Validation

NACH Abschluss aller 6 Slices:

```yaml
e2e_validation:

  - step: "1. Vollstaendige Unit-Test-Suite"
    action: "cd frontend && pnpm test tests/slices/shop-completeness/"
    expected: "Alle Tests GRUEN, 0 Failures"
    failure_action: |
      Identifiziere fehlgeschlagene Test-Datei → verantwortlichen Slice aus integration-map.md
      Erstelle Fix-Task mit Slice-Referenz
      Re-run: pnpm test tests/slices/shop-completeness/slice-NN-*.test.ts

  - step: "2. TypeScript Build"
    action: "cd frontend && pnpm build"
    expected: "Build erfolgreich, keine TypeScript-Fehler"
    failure_action: "TypeScript-Fehler im Error-Log auf Datei/Zeile zurueckfuehren → Slice identifizieren"

  - step: "3. E2E Checklist manuell"
    action: "Alle Checkboxen in e2e-checklist.md abarbeiten"
    expected: "Alle Journeys PASS"
    failure_action: |
      Jede fehlgeschlagene Check → integration-map.md Connections pruefen
      Verantwortlichen Slice identifizieren → Fix-Task erstellen

  - step: "4. Final Approval"
    condition: "ALLE Checks in e2e-checklist.md PASS UND pnpm test GRUEN UND pnpm build GRUEN"
    output: "Feature READY for Merge"
```

---

## Rollback-Strategie

### Wenn Wave 1 (Slice 01) fehlschlaegt

```yaml
rollback:
  trigger: "Slice 01 Tests schlagen fehl ODER Deliverables fehlen"
  action:
    - "Revert alle Slice-01-Commits (git revert oder git reset)"
    - "Alle anderen Slices koennen NICHT starten (blockiert durch Gate)"
    - "Root-Cause-Analyse: Welche Deliverable fehlt?"
    - "Slice-01-Spec erneut pruefen: specs/phase-1/.../slices/slice-01-cross-page-infrastruktur.md"
  note: "Kein Partial-Rollback noetig — Slice 01 hat keine Abhaengigkeiten auf andere Slices"
```

### Wenn Wave 2 Slice 02 fehlschlaegt

```yaml
rollback:
  trigger: "Slice 02 Tests schlagen fehl"
  action:
    - "Revert Slice-02-spezifische Commits"
    - "product.yaml zuruecksetzen auf Stand vor Slice 02"
    - "Neue Dateien entfernen: product-reviews-block.tsx, product-recommendations-block.tsx,
       review-form.tsx, star-rating-*.tsx, mutations.ts"
    - "Slice 03 ist NICHT betroffen — kann parallel abgeschlossen werden"
    - "Slices 04, 05, 06 koennen starten (haben keine Abhaengigkeit auf Slice 02)"
  note: "Slice 02 hat keine Downstream-Consumers — Rollback ist isoliert"
```

### Wenn Wave 2 Slice 03 fehlschlaegt

```yaml
rollback:
  trigger: "Slice 03 Tests schlagen fehl"
  action:
    - "Revert Slice-03-spezifische Commits"
    - "category.yaml auf ungepaginierten Stand zuruecksetzen"
    - "Entfernte Branches in data-loaders.ts rueckgaengig machen"
    - "GET_PRODUCTS_PAGINATED und GET_CATEGORY_META aus queries.ts entfernen"
    - "PaginatedProductsResult, PaginationMeta aus types.ts entfernen"
    - "BLOCKIERT: Slices 04, 05, 06 koennen NICHT starten"
    - "Slice 02 ist NICHT betroffen"
  note: "Slice 03 ist kritischer Path fuer Wave 3. Fix hat hohe Prioritaet."
```

### Wenn Wave 3 einzelner Slice fehlschlaegt

```yaml
rollback:
  trigger: "Slice 04 ODER Slice 05 ODER Slice 06 schlaegt fehl"
  action:
    - "Revert NUR den fehlgeschlagenen Slice (isolierter Branch)"
    - "Andere Wave-3-Slices sind NICHT betroffen"
    - "Partial-Feature-Launch moeglich: vollstaendige Slices koennen gemergt werden"
    - "Fehlgeschlagener Slice wird nachimplementiert und in naechster Iteration geliefert"
  examples:
    - "Slice 04 fails: Homepage ohne Testimonials/Featured-Collection/Newsletter — akzeptabel fuer Launch"
    - "Slice 05 fails: Keine Suchseite — kritischer, Nutzer koennen nicht suchen"
    - "Slice 06 fails: Keine Collections/Danke/404 — 404 besonders kritisch fuer Launch"
```

### Bei Integration-Fehler (nach allen Slices)

```yaml
rollback:
  trigger: "E2E-Checklist hat FAIL-Eintraege"
  action:
    - "Identifiziere betroffenen Integration Point aus e2e-checklist.md Cross-Slice-Tabelle"
    - "Pruefe integration-map.md → Connections fuer verantwortliche Slices"
    - "Fix-Task erstellen mit genauem Fehlerbild"
    - "Betroffene Slice-Tests re-ausfuehren nach Fix"
    - "Kein vollstaendiger Rollback noetig — nur betroffene Dateien"
```

---

## Monitoring waehrend Implementation

| Metrik | Alert-Schwelle | Massnahme |
|--------|---------------|-----------|
| Slice-Completion-Zeit | > 3x Schaetzung | Scope-Check: Ist der Slice zu gross? Deliverables pruefen |
| Test-Failures | > 0 blockierende Fehler | Sofortiger Stop — kein naechster Slice bis Fix |
| Deliverable fehlend | Jedes fehlende File | Stop-Hook blockiert — nachliefern erforderlich |
| TypeScript-Fehler | Jeder neue TS-Fehler | Build schlaegt fehl — naechster Slice startet nicht |
| Shared-File Merge-Konflikt | Bei jedem Konflikt | Manuell resolven vor naechstem Wave-Start |

---

## Implementierungs-Hinweise fuer Agent

### Hinweis 1: Slice 03 ersetzt bestehenden Branch

```
WICHTIG: products_by_category in data-loaders.ts
Der bestehende (ungepaginierte) products_by_category Branch wird von Slice 03 ERSETZT.
Der Agent muss den bestehenden Branch finden und durch die paginierte Version ersetzen.
Ruckwaertskompatibilitaet: wenn page/sort nicht uebergeben → Defaults (page=1, keine Sortierung).
```

### Hinweis 2: global.yaml vs. home.yaml

```
WICHTIG: announcement-bar Block DARF NICHT in home.yaml erscheinen.
global.yaml: enthalt announcement-bar (via loadGlobalConfig → layout.tsx)
home.yaml: enthalt testimonials, featured-collection, newsletter-signup (KEIN announcement-bar)
Wenn ein Agent versehentlich announcement-bar in home.yaml eintraegt → doppelte Anzeige!
```

### Hinweis 3: SortBarData Erweiterung in Slice 05

```
WICHTIG: Slice 05 erweitert SortBarData (von Slice 01 definiert) um currentQuery?: string.
Der Slice 05 Agent muss:
  1. lib/blocks/types.ts: SortBarData um currentQuery?: string erganzen
  2. components/blocks/sort-bar-block.tsx: currentQuery aus Props lesen und bei URL-Bau beruecksichtigen
Dies ist eine rueckwaertskompatible Erweiterung (optionales Feld).
Slice 01 muss VOLLSTAENDIG abgeschlossen sein bevor Slice 05 diese Aenderung vornimmt.
```

### Hinweis 4: category_meta Branch und Slice 06

```
WICHTIG: category_meta Branch wird in Slice 03 implementiert.
Slice 06 benoetigt diesen Branch fuer den CollectionHeaderBlock.
Wenn Slice 03 den category_meta Branch noch nicht implementiert hat, muss Slice 06:
  1. Den Branch in data-loaders.ts nachimplementieren
  2. GET_CATEGORY_META aus queries.ts importieren
Normalfall: Branch ist aus Slice 03 vorhanden → Slice 06 prueft nur ob er korrekt funktioniert.
```

### Hinweis 5: thanks.yaml und orderId

```
WICHTIG: architecture.md Zeile 567 spezifiziert orderId als YAML-Prop in thanks.yaml.
Diese Spezifikation ist FALSCH und wird in Slice 06 korrigiert:
thanks.yaml enthaelt KEIN orderId-Feld.
Begruendung: window.location.search ist nur client-seitig verfuegbar (SSR kennt window nicht).
orderId wird via useEffect in OrderConfirmationBlock client-seitig gelesen.
Wenn der Agent die architecture.md-Version implementiert → TypeScript-Fehler oder Hydration-Mismatch!
```

### Hinweis 6: Slice 02 liefert keine Downstream-Exports fuer andere Slices

```
Slice 02 hat keine Downstream-Consumer in diesem Feature.
ProductReviewsBlock, ProductRecommendationsBlock, WRITE_REVIEW, etc. werden NUR von
app/produkt/[slug]/page.tsx (via product.yaml) genutzt.
Slices 04, 05, 06 benoetigen NICHTS von Slice 02.
Slice 02 kann daher auch NACH Wave 3 nachimplementiert werden ohne den Launch zu blockieren.
```

---

## Datei-Checkliste (alle Deliverables)

### Neue Dateien (alle Slices zusammen)

```
frontend/components/blocks/announcement-bar-block.tsx        (Slice 01)
frontend/components/blocks/breadcrumb-block.tsx              (Slice 01)
frontend/components/blocks/trust-badges-block.tsx            (Slice 01)
frontend/components/blocks/pagination-block.tsx              (Slice 01)
frontend/components/blocks/sort-bar-block.tsx                (Slice 01)
frontend/components/blocks/empty-state-block.tsx             (Slice 01)
frontend/components/blocks/product-reviews-block.tsx         (Slice 02)
frontend/components/blocks/product-recommendations-block.tsx (Slice 02)
frontend/components/reviews/star-rating-display.tsx          (Slice 02)
frontend/components/reviews/star-rating-input.tsx            (Slice 02)
frontend/components/reviews/review-card.tsx                  (Slice 02)
frontend/components/reviews/review-form.tsx                  (Slice 02)
frontend/lib/graphql/mutations.ts                            (Slice 02)
frontend/components/blocks/testimonials-block.tsx            (Slice 04)
frontend/components/blocks/newsletter-signup-block.tsx       (Slice 04)
frontend/components/blocks/featured-collection-block.tsx     (Slice 04)
frontend/themes/default/pages/global.yaml                    (Slice 04)
frontend/app/suche/page.tsx                                  (Slice 05)
frontend/components/blocks/search-bar-block.tsx              (Slice 05)
frontend/components/blocks/search-results-block.tsx          (Slice 05)
frontend/themes/default/pages/search.yaml                    (Slice 05)
frontend/app/kollektion/[slug]/page.tsx                      (Slice 06)
frontend/app/danke/page.tsx                                  (Slice 06)
frontend/app/not-found.tsx                                   (Slice 06)
frontend/components/blocks/collection-header-block.tsx       (Slice 06)
frontend/components/blocks/order-confirmation-block.tsx      (Slice 06)
frontend/themes/default/pages/collection.yaml                (Slice 06)
frontend/themes/default/pages/thanks.yaml                    (Slice 06)
tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts  (Slice 01)
tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts (Slice 02)
tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts (Slice 03)
tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts     (Slice 04)
tests/slices/shop-completeness/slice-05-suchseite.test.ts                 (Slice 05)
tests/slices/shop-completeness/slice-06-neue-pages.test.ts                (Slice 06)
```

### Modifizierte Dateien (bestehend, wird erweitert)

```
frontend/lib/blocks/registry.ts                              (Slice 01, 02, 04, 05, 06)
frontend/lib/blocks/types.ts                                 (Slice 01, 02, 03, 04, 05, 06)
frontend/lib/blocks/page-config.ts                           (Slice 01: loadGlobalConfig)
frontend/lib/blocks/data-loaders.ts                          (Slice 02, 03, 04, 05, 06)
frontend/lib/graphql/queries.ts                              (Slice 02, 03)
frontend/app/layout.tsx                                      (Slice 04)
frontend/app/page.tsx                                        (Slice 04: skeletonMap)
frontend/app/kategorie/[slug]/page.tsx                       (Slice 03: searchParams)
frontend/themes/default/pages/product.yaml                   (Slice 02: neue Sections)
frontend/themes/default/pages/category.yaml                  (Slice 03: vollstaendiger Ersatz)
frontend/themes/default/pages/home.yaml                      (Slice 04: 3 neue Sections)
frontend/components/layout/header.tsx                        (Slice 05: Suchicon)
frontend/components/blocks/sort-bar-block.tsx                (Slice 05: currentQuery)
```
