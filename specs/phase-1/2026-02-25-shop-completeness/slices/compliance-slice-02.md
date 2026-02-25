# Gate 2: Slice 02 Compliance Report (Re-Check)

**Gepruefter Slice:** `specs/phase-1/2026-02-25-shop-completeness/slices/slice-02-produkt-page-enhancements.md`
**Pruefdatum:** 2026-02-25
**Architecture:** `specs/phase-1/2026-02-25-shop-completeness/architecture.md`
**Wireframes:** `specs/phase-1/2026-02-25-shop-completeness/discovery.md` (UI Layout & Context)
**Vorherige Slices:** `specs/phase-1/2026-02-25-shop-completeness/slices/slice-01-cross-page-infrastruktur.md`
**Iteration:** Re-Check #1 (4 bekannte Fixes geprueft)

---

## Bekannte Fixes — Verifikation

| Fix # | Beschreibung | Fundstelle im Slice | Ergebnis |
|-------|--------------|---------------------|---------|
| Fix 1 | `GET_PRODUCT_CATEGORY` als schlanke Query (nur `databaseId` + `productCategories.nodes.slug`) | Zeilen 251-264: Query exakt so definiert, `GET_PRODUCT` nicht mehr referenziert | PASS |
| Fix 2 | Apollo-Mock: `vi.mock('@/lib/apollo/client', () => ({ apolloClient: { mutate: mockMutate } }))` | Zeilen 607-611: Named-Export `apolloClient` korrekt gemockt | PASS |
| Fix 3 | `it('should show error toast when review mutation fails')` mit `mockRejectedValueOnce` | Zeilen 781-803: Vollstaendiger Test vorhanden, Toast-Text + Form-offen-Pruefung | PASS |
| Fix 4 | AC-7 + AC-8: `it.todo()` durch vollstaendige Tests ersetzt | Zeilen 935-1010: 3 vollstaendige Data-Loader-Tests vorhanden (related→category, both-empty→null, bestsellers-empty→null) | PASS |

Alle 4 bekannten Fixes sind korrekt umgesetzt. Ein neuer Blocking Issue wurde identifiziert.

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 47 |
| Warning | 0 |
| Blocking | 1 |

**Verdict:** FAILED

---

## 0) Inhaltliche Pruefung

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-2 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-3 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-4 | Yes | Yes (konkrete Validierungsregeln + Spinner + Danke-Text) | Yes | Yes | Yes | PASS |
| AC-5 | Yes | Yes (exakter Toast-Text angegeben) | Yes | Yes | Yes | PASS |
| AC-6 | Yes | Yes (4-spaltig Desktop, 2-spaltig Mobile) | Yes | Yes | Yes | PASS |
| AC-7 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-8 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-9 | Yes | Yes (3 konkrete Badge-Texte) | Yes | Yes | Yes | PASS |
| AC-10 | Yes | Yes | Yes | Yes | Yes | PASS |

Alle 10 ACs sind im GIVEN/WHEN/THEN-Format mit konkreten, maschinell pruefbaren Werten formuliert.

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| CE-1: ProductReviewsBlock | Yes | Yes | Yes | Hinweis: `productId` auf `data` aus Query — siehe Blocking Issue 1 | FAIL |
| CE-2: ProductRecommendationsBlock | Yes | Yes (`@/lib/graphql/types` fuer `ProductCardData`) | Yes | Yes | PASS |
| CE-3: validateReviewInput() | Yes | Yes | Yes | Yes | PASS |
| CE-4: WRITE_REVIEW Mutation | Yes (stimmt exakt mit Architecture) | Yes | Yes | Yes | PASS |
| CE-5: ProductReviewsResult Type | Yes (reviews.edges Struktur korrekt) | Yes | Yes | Hinweis: `productId` im Type — ohne `databaseId` in Query ist dieser Wert runtime `undefined` | FAIL |
| CE-6: data-loaders branches | Yes (GET_PRODUCT_CATEGORY korrekt verwendet) | Yes | Yes | FAIL — `product_reviews` Branch liest `product.databaseId`, fehlt aber in `GET_PRODUCT_REVIEWS` Query | FAIL |
| GET_PRODUCT_CATEGORY | Yes (databaseId + categorySlug minimal) | Yes | Yes | Yes | PASS |
| product.yaml | Yes (alle 3 Sections korrekt) | Yes | Yes | Yes | PASS |

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | typescript-nextjs (Next.js 16, Vitest 3, TypeScript 5.7) | PASS |
| Commands vollstaendig | 3 vorhanden (Test, Integration, Acceptance) | 3 (unit, integration, acceptance) | PASS |
| Start-Command | `cd frontend && pnpm dev` | Korrekt fuer Next.js Stack | PASS |
| Health-Endpoint | `http://localhost:3000/api/health` | Korrekt fuer Next.js dev-Server :3000 | PASS |
| Mocking-Strategy | `mock_external` | Definiert; Apollo Client + Server-Client gemockt | PASS |

---

## A) Architecture Compliance

### Schema Check

Kein eigenes DB-Schema. Alle Daten aus WooCommerce-Tabellen.

| Arch Field | Arch Type | Slice Spec | Status |
|------------|-----------|------------|--------|
| `wp_comments (type: review)` | WooCommerce Reviews | Korrekt referenziert | PASS |
| `wp_commentmeta (rating key)` | Int 1-5 | `rating: number` auf ReviewEdge | PASS |
| `wp_postmeta (averageRating)` | Derived number | `averageRating: number` | PASS |
| `wp_postmeta (reviewCount)` | Derived number | `reviewCount: number` | PASS |
| `ReviewEdge.rating` | number (auf Edge, nicht Node) | `rating: number` auf Edge — WooGraphQL-Quirk korrekt | PASS |
| `ReviewEdge.node.author.node.name` | string (nested) | `author: { node: { name: string } }` | PASS |
| `WriteReviewInput.productId` | number (Int!) | number | PASS |
| `WriteReviewInput.rating` | number (Int, 1-5) | number + Integer-Pruefung | PASS |

### API Check

| Endpoint | Arch Spec (architecture.md) | Slice Spec | Status |
|----------|------------------------------|------------|--------|
| `GET_PRODUCT_REVIEWS` | Variables: `productSlug: ID!`; Response: `averageRating reviewCount reviewsAllowed reviews { edges { rating node { id content date author { node { name } } } } }` | Identisch — Zeilen 183-205 | PASS |
| `GET_RELATED_PRODUCTS` | Variables: `productId: ID!, first: Int`; Response: `product.related.nodes.{...ProductCardFields}` | Identisch — Zeilen 208-220 | PASS |
| `GET_BESTSELLER_PRODUCTS` | Variables: `first: Int`; Response: `products.nodes.{...ProductCardFields}` | Identisch — Zeilen 222-234 | PASS |
| `GET_PRODUCTS_BY_IDS` | Variables: `include: [Int!]!, first: Int`; Response: `products.nodes.{...ProductCardFields}` | Identisch — Zeilen 236-249 | PASS |
| `GET_PRODUCT_CATEGORY` | Nicht in arch.md als eigene Query, abgeleitet aus Loader-Anforderung | Zeilen 252-264: `databaseId` + `productCategories.nodes.slug` — minimal und korrekt; Erklaerung in Zeile 266 | PASS |
| `WRITE_REVIEW` Mutation | Variables: `productId: Int!, content: String!, author: String!, authorEmail: String!, rating: Int!`; Response: `writeReview { rating review { id content date } }` | Identisch — Code Example 4 | PASS |

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| Review read: unauthenticated | RSC Server Client, keine Session | `getClient()` aus `server-client.ts` | PASS |
| Review write: guest (kein Login) | WC guest reviews, status pending | `apolloClient.mutate()`, kein Auth-Token | PASS |
| Review email: nicht im Frontend | Email nicht in Review-List-Query exposed | `reviewer_email` nur in Mutation-Input, nicht in `GET_PRODUCT_REVIEWS` | PASS |
| Validation: author min 2 chars | Arch Zeile 213 | `validateReviewInput()`: `author.trim().length < 2` | PASS |
| Validation: email regex | Arch Zeile 213 | `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` | PASS |
| Validation: rating 1-5 Int | Arch Zeile 215 | `Number.isInteger() + range 1-5` | PASS |
| Validation: content min 10 | Arch Zeile 216 | `content.trim().length < 10` | PASS |

---

## B) Wireframe Compliance

### UI Elements

| Discovery Element (discovery.md §UI Layout) | Annotation | Slice Component | Status |
|---------------------------------------------|------------|-----------------|--------|
| trust-badges (Truck, Shield, Lock) | Section nach product-description | product.yaml Section 3 mit 3 Inline-Items | PASS |
| Sterne-Zusammenfassung (Durchschnitt + Anzahl) | "4.2 von 5, 47 Bewertungen" | `StarRatingDisplay` + `averageRating` + `reviewCount` | PASS |
| Review-Liste (Autor, Datum, Text) | ReviewCard | `ReviewCard` mit `edge.node.author.node.name`, `date`, `content` | PASS |
| "Bewertung schreiben" Button | Toggle-Button | Button mit `aria-expanded={false}` wenn hidden | PASS |
| Review-Formular (Name, E-Mail, Sterne, Text, Abbrechen, Absenden) | ReviewForm 6 Felder/Buttons | `ReviewForm` mit allen Feldern und `onCancel` | PASS |
| Recommendations-Ueberschrift (YAML konfigurierbar) | `heading` aus YAML params | `data.heading` dynamisch | PASS |
| Recommendations Grid 4-spaltig Desktop | 4 Cards | `lg:grid-cols-4` in Code Example 2 | PASS |
| Recommendations Grid 2-spaltig Mobile | 2 Cards | `grid-cols-2` in Code Example 2 | PASS |
| ProductCard (Name + Preis + Bild) | bestehende Komponente | `ProductCard` aus `@/components/product/product-card` wiederverwendet | PASS |

### State Variations

| State | Discovery/Wireframe Spec | Slice Spec | Status |
|-------|--------------------------|------------|--------|
| ReviewForm: hidden | Button "Bewertung schreiben" sichtbar | `formState === 'hidden'` → Button | PASS |
| ReviewForm: open | Formular sichtbar | `formState !== 'hidden'` → `ReviewForm` | PASS |
| ReviewForm: loading | Button disabled + Spinner | `formState === 'loading'` (an ReviewForm weitergegeben) | PASS |
| ReviewForm: success | Danke-Text, Form schliesst | `formState === 'success'` → Danke-Text, kein Form | PASS |
| ReviewForm: error | Toast, Form bleibt offen | Test Zeile 799: Toast-Text + `getByLabelText(/Name/i)` noch sichtbar | PASS |
| Reviews leer (allowed=true) | Nur Button, kein Negativ-Text | `reviews.edges.length > 0 ? list : null` (kein "Keine Bewertungen") | PASS |
| reviewsAllowed=false | Block vollstaendig unsichtbar | `if (!data || !data.reviewsAllowed) return null` Zeile 1215 | PASS |
| Recommendations: no data | Block unsichtbar, kein leerer Container | `if (!data || products.length === 0) return null` | PASS |
| Skeleton: ProductReviewsBlock | Grau-Bloecke fuer Summary + 2 Cards | `ProductReviewsBlockSkeleton` Export Zeilen 1279-1292 | PASS |
| Skeleton: ProductRecommendationsBlock | ProductGridBlockSkeleton (reuse) | Re-export als `ProductRecommendationsBlockSkeleton` Zeile 1331 | PASS |

### Visual Specs

| Spec | Discovery Value | Slice Value | Status |
|------|----------------|-------------|--------|
| Grid Desktop | 4 Spalten | `lg:grid-cols-4` | PASS |
| Grid Mobile | 2 Spalten | `grid-cols-2` | PASS |
| Review-Form max-width Desktop | max-w-lg | Skill Verification Zeile 1118: `max-w-lg` | PASS |
| Theme-Tokens statt Hardcode | Nur `bg-primary`, `text-text-primary` etc. | Skill Verification Zeile 1113-1114 | PASS |
| Touch-Target Sterne | min 44x44px | Skill Verification Zeile 1107: `touch-action: manipulation` + 44px | PASS |
| `prefers-reduced-motion` | Animation disabled | Skill Verification Zeile 1100 | PASS |

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| `TrustBadgesBlock` Component | `slice-01-cross-page-infrastruktur` | Integration Contract Zeile 1147: "In `lib/blocks/registry.ts` unter Key `'trust-badges'` registriert" | PASS |
| `TrustBadgeData` Interface | `slice-01-cross-page-infrastruktur` | Integration Contract Zeile 1148: "In `lib/blocks/types.ts` definiert" | PASS |
| `registry.ts` Pattern (stabile Basis) | `slice-01-cross-page-infrastruktur` | Integration Contract Zeile 1149 | PASS |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| `ProductReviewsBlock` | `lib/blocks/registry.ts` | Interface: `BlockComponentProps<ProductReviewsResult \| null>` | PASS |
| `ProductRecommendationsBlock` | `lib/blocks/registry.ts` | Interface: `BlockComponentProps<ProductRecommendationsData \| null>` | PASS |
| `ProductReviewsResult` Type | `data-loaders.ts` | Code Example 5 vollstaendig | PASS |
| `ReviewEdge` Type | `ProductReviewsBlock` | Code Example 5 vollstaendig | PASS |
| `WriteReviewInput` Type | `review-form.tsx` | Code Example 5 vollstaendig | PASS |
| `validateReviewInput()` | Tests + `review-form.tsx` | Code Example 3 vollstaendig exportiert | PASS |
| `WRITE_REVIEW` Mutation | `lib/graphql/mutations.ts` | Code Example 4 vollstaendig | PASS |
| `GET_PRODUCT_REVIEWS` Query | `lib/graphql/queries.ts` | Technische Umsetzung §4 | PASS |
| `GET_RELATED_PRODUCTS` Query | `lib/graphql/queries.ts` | Technische Umsetzung §4 | PASS |
| `GET_BESTSELLER_PRODUCTS` Query | `lib/graphql/queries.ts` | Technische Umsetzung §4 | PASS |
| `GET_PRODUCTS_BY_IDS` Query | `lib/graphql/queries.ts` | Technische Umsetzung §4 | PASS |
| `GET_PRODUCT_CATEGORY` Query | `lib/graphql/queries.ts` | Technische Umsetzung §4 | PASS |
| `product.yaml` (erweitert) | `app/produkt/[slug]/page.tsx` via loadPageConfig | Technische Umsetzung §6 vollstaendig | PASS |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-------------------|--------------|--------|
| `ProductReviewsBlock` | `lib/blocks/registry.ts` (modifiziert) | Yes — "Geaenderte Dateien" in Slice-02 | slice-02 | PASS |
| `ProductRecommendationsBlock` | `lib/blocks/registry.ts` (modifiziert) | Yes — "Geaenderte Dateien" in Slice-02 | slice-02 | PASS |
| `product.yaml` erweitert | `app/produkt/[slug]/page.tsx` (bestehendes File) | Bestehende Seite — kein neues Deliverable noetig | pre-existing | PASS |

### AC-Deliverable-Konsistenz

| AC # | Referenced Page/File | In Deliverables? | Status |
|------|----------------------|------------------|--------|
| AC-1 bis AC-5 | `product-reviews-block.tsx` + `review-form.tsx` + `product.yaml` | Alle in Deliverables-Liste | PASS |
| AC-6 bis AC-8 | `product-recommendations-block.tsx` + `data-loaders.ts` | Alle in Deliverables-Liste | PASS |
| AC-9 | `product.yaml` (trust-badges Section) | In Deliverables-Liste | PASS |
| AC-10 | `product-reviews-block.tsx` | In Deliverables-Liste | PASS |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| CE-1: ProductReviewsBlock | Zeilen 1196-1292 | Yes | Teilweise — `data.productId` wird verwendet, aber `GET_PRODUCT_REVIEWS` liefert kein `databaseId` | FAIL |
| CE-2: ProductRecommendationsBlock | Zeilen 1297-1332 | Yes | Yes | PASS |
| CE-3: validateReviewInput() | Zeilen 1337-1370 | Yes (alle 4 Validierungsregeln vollstaendig) | Yes | PASS |
| CE-4: WRITE_REVIEW Mutation | Zeilen 1374-1404 | Yes (alle Variablen + Response-Fields) | Yes | PASS |
| CE-5: ProductReviewsResult Type | Zeilen 1407-1452 | Yes | Teilweise — `productId: number` im Type korrekt, aber Quelle fehlt in Query | FAIL |
| CE-6: product_reviews Branch | Zeilen 1459-1478 | Yes | FAIL — `product.databaseId` referenziert, nicht in `GET_PRODUCT_REVIEWS` selektiert | FAIL |
| CE-6: product_recommendations Branch | Zeilen 1480-1541 | Yes (GET_PRODUCT_CATEGORY korrekt) | Yes | PASS |
| product.yaml | Zeilen 302-362 | Yes (5 Sections: gallery+purchase, description, trust-badges, reviews, recommendations) | Yes | PASS |

---

## E) Build Config Sanity Check

N/A — Slice 02 hat keine Build-Config-Deliverables (kein vite.config, webpack.config, tsconfig).

---

## F) Test Coverage

| Acceptance Criteria | Test Definiert | Test Typ | Status |
|--------------------|----------------|----------|--------|
| AC-1: Sterne-Summary mit avg + count | `it('should render star summary with average rating and review count')` Zeile 629 | Component (Vitest) | PASS |
| AC-1: Review-Liste mit Autoren + Inhalt | `it('should render review list with author names and content')` Zeile 637 | Component (Vitest) | PASS |
| AC-2: Formular oeffnet bei Klick | `it('should show review form when "Bewertung schreiben" is clicked')` Zeile 651 | Component (Vitest) | PASS |
| AC-3: Inline-Fehler bei leerem Submit | `it('should show inline validation errors when submitting empty form')` Zeile 664 | Component (Vitest) | PASS |
| AC-3: Name min 2 Zeichen | `it('should validate minimum name length of 2 characters')` Zeile 681 | Component (Vitest) | PASS |
| AC-3: Content min 10 Zeichen | `it('should validate minimum content length of 10 characters')` Zeile 699 | Component (Vitest) | PASS |
| AC-4: Mutation mit korrekten Variablen | `it('should call WRITE_REVIEW mutation with correct variables...')` Zeile 718 | Component (Vitest) | PASS |
| AC-4: Success-State nach Mutation | `it('should show success message and hide form after successful mutation')` Zeile 754 | Component (Vitest) | PASS |
| AC-5: Error-Toast bei Mutation-Fehler | `it('should show error toast and keep form open when WRITE_REVIEW mutation fails')` Zeile 781 | Component (Vitest) | PASS |
| AC-6: Recommendations Heading + 4 Cards | `it('should render heading from params and 4 product cards')` Zeile 839 | Component (Vitest) | PASS |
| AC-6: aria-labelledby | `it('should render heading with aria-labelledby on section')` Zeile 869 | Component (Vitest) | PASS |
| AC-7: Fallback related → category | `it('should return category products as fallback when related is empty')` Zeile 935 | Unit/data-loader (Vitest) | PASS |
| AC-8: Fallback exhausted → null | `it('should return null when related and category both return empty')` Zeile 980 | Unit/data-loader (Vitest) | PASS |
| AC-9: product.yaml enthaelt alle 3 Sections | `it('should contain trust-badges, product-reviews, and product-recommendations sections')` Zeile 1041 | YAML-Integration | PASS |
| AC-10: reviewsAllowed=false → null | `it('should return null when reviewsAllowed is false')` Zeile 805 | Component (Vitest) | PASS |
| Zusatz: data=null → null | `it('should return null when data is null')` Zeile 811 | Component (Vitest) | PASS |
| Zusatz: Recommendations empty → null | `it('should return null when products array is empty')` Zeile 863 | Component (Vitest) | PASS |
| Zusatz: bestsellers empty → null | `it('should return null when source=bestsellers and query returns empty')` Zeile 1012 | Unit/data-loader | PASS |

Alle 10 ACs haben mindestens einen ausfuehrbaren Test. Kein `it.todo()` mehr vorhanden.

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| UI Components | `review-stars-input` (1-5 stars selected) | Yes | Yes — `star-rating-input.tsx` in Deliverables | PASS |
| UI Components | `review-submit` (idle/loading/success/error) | Yes | Yes — formState Machine | PASS |
| UI Components | `recommendations-card` (default/hover) | Yes | Yes — ProductCard Wiederverwendung | PASS |
| UI Components | `trust-badge-item` (static) | Yes | Yes — TrustBadgesBlock aus Slice 1 via product.yaml | PASS |
| State Machine: Review Form | hidden/open/loading/success/error (alle 5 States) | Yes | Yes — `ReviewFormState` Typ + alle Transitionen | PASS |
| Transitions | hidden→open (Click "Bewertung schreiben") | Yes | Yes | PASS |
| Transitions | open→loading (Click "Absenden" valide) | Yes | Yes | PASS |
| Transitions | open→open (Click "Absenden" invalide + Inline-Fehler) | Yes | Yes | PASS |
| Transitions | open→hidden (Click "Abbrechen") | Yes | Yes — `onCancel` Prop | PASS |
| Transitions | loading→success | Yes | Yes | PASS |
| Transitions | loading→error (Toast) | Yes | Yes | PASS |
| Transitions | error→loading ("Erneut versuchen") | Yes | Yes — im State Machine Code | PASS |
| Business Rules | Reviews pending-Status | Yes | Yes — Definition of Done + Architecture referenziert | PASS |
| Business Rules | Recommendations Fallback chain (related→category→null) | Yes | Yes — Code Example 6 + 3 Tests | PASS |
| Business Rules | reviewsAllowed=false → Block unsichtbar | Yes | Yes — Guard in CE-1 + AC-10 Test | PASS |
| Data | `reviewer_name` min 2 chars | Yes | Yes — validateReviewInput() | PASS |
| Data | `reviewer_email` valides Format | Yes | Yes — Regex-Check | PASS |
| Data | `rating` 1-5 Int | Yes | Yes — Integer + Range-Check | PASS |
| Data | `content` min 10 chars | Yes | Yes — Trim + Length | PASS |
| Data | `heading` (YAML, required) | Yes | Yes — YAML param, CE-2 | PASS |
| Data | `source` (required: related/category/bestsellers/custom) | Yes | Yes — alle 4 Branches in CE-6 | PASS |
| Data | `custom_ids` (when source=custom) | Yes | Yes — `split(',').map(Number)` in CE-6 | PASS |
| Data | `first` (optional, default 4) | Yes | Yes — `first ?? 4` in CE-6 | PASS |

---

## Blocking Issues

### Issue 1: `GET_PRODUCT_REVIEWS` Query fehlt Feld `databaseId` — product_reviews Loader kann `productId` nicht besetzen

**Category:** API / Code Example
**Severity:** BLOCKING

**Fundstellen im Slice:**

`GET_PRODUCT_REVIEWS` Query (Zeilen 183-205) — vollstaendige Felder:
```graphql
query GetProductReviews($productSlug: ID!) {
  product(id: $productSlug, idType: SLUG) {
    averageRating
    reviewCount
    reviewsAllowed
    reviews {
      edges {
        rating
        node { id content date author { node { name } } }
      }
    }
    # databaseId fehlt vollstaendig
  }
}
```

`product_reviews` Loader Branch (Code Example 6, Zeile 1475):
```typescript
productId: product.databaseId,  // benoetigt fuer WRITE_REVIEW Mutation
```

`ProductReviewsResult` Type (Code Example 5, Zeile 1432):
```typescript
productId: number  // WooCommerce Database ID (fuer writeReview commentOn: Int!)
```

**Problem:**
Der `product_reviews`-Loader-Branch liest `product.databaseId` aus der GraphQL-Antwort des `GET_PRODUCT_REVIEWS`-Aufrufs. Das Feld `databaseId` ist jedoch nicht im `GET_PRODUCT_REVIEWS`-Query selektiert. Der Apollo-Client gibt nur explizit selektierte Felder zurueck — `product.databaseId` wird zur Laufzeit `undefined` sein.

Konsequenzen:
1. `ProductReviewsResult.productId` ist `undefined` statt einer `number`.
2. TypeScript: `satisfies ProductReviewsResult` schlaegt fehl (da `productId: number` erwartet, `undefined` erhalten), sofern strict types nicht umgangen werden.
3. Laufzeit: `WRITE_REVIEW`-Mutation wird mit `commentOn: undefined` statt der korrekten Produkt-ID aufgerufen — WooCommerce lehnt die Mutation ab oder schreibt die Review dem falschen Produkt zu.
4. AC-4 ("wechselt der Button zu Lade-Spinner, Mutation wird abgeschickt, Danke-Text erscheint") schlaegt bei echter Implementierung fehl.

**Architecture-Referenz:**
Architecture.md Zeile 74: `GET_PRODUCT_REVIEWS` Response Fields werden ohne `databaseId` aufgefuehrt. Architecture.md Zeile 91: `ProductReviewsResult` DTO enthaelt kein `productId`-Feld (der Slice hat dieses Feld korrekt hinzugefuegt, aber die Datenquelle fehlt in der Query).

**Resolution:**

`databaseId` zum `GET_PRODUCT_REVIEWS` Query hinzufuegen (Zeilen 183-205):

```graphql
query GetProductReviews($productSlug: ID!) {
  product(id: $productSlug, idType: SLUG) {
    databaseId                         # <- HINZUFUEGEN
    averageRating
    reviewCount
    reviewsAllowed
    reviews {
      edges {
        rating
        node {
          id
          content
          date
          author { node { name } }
        }
      }
    }
  }
}
```

`databaseId` ist ein Standard-WPGraphQL-Feld das auf jedem WordPress Post-Typ verfuegbar ist und die numerische WordPress-Post-ID zurueckgibt — identisch mit dem `commentOn: Int!`-Input der `WRITE_REVIEW`-Mutation.

---

## Recommendations

1. Einzige Pflichtaenderung: `databaseId` zum `GET_PRODUCT_REVIEWS` Query hinzufuegen (eine Zeile in Technische Umsetzung §4).
2. (Empfohlen, nicht-blocking) Architecture.md `GET_PRODUCT_REVIEWS` Response-Fields um `databaseId` ergaenzen und `ProductReviewsResult` DTO um `productId: number` ergaenzen — verhindert zukuenftige Verwirrung.

---

## Verdict

**Status:** FAILED

**Blocking Issues:** 1
**Warnings:** 0

**Ursache:** `GET_PRODUCT_REVIEWS` Query selektiert `databaseId` nicht, obwohl der `product_reviews`-Loader `product.databaseId` zur Befuellung von `ProductReviewsResult.productId` liest. Zur Laufzeit ist `productId` stets `undefined`, was die `WRITE_REVIEW`-Mutation bricht (AC-4 nicht erfuellbar).

**Alle 4 bekannten Fixes korrekt umgesetzt.**

**Next Steps:**
- [ ] `databaseId` zu `GET_PRODUCT_REVIEWS` Query (Technische Umsetzung §4, Zeile 184 ff.) hinzufuegen
- [ ] Gate 2 Re-Check ausfuehren (dies waere dann Re-Check #2 — letzter erlaubter Versuch)

> **Hinweis Retry-Regel:** Laut Gate-2-Prozess ist maximal 1 Re-Check erlaubt. Dieser Report ist Re-Check #1. Bei weiteren Blocking Issues nach dem naechsten Fix-Versuch wird der Planner HARD-STOPPED.
