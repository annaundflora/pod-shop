# Gate 2: Slice 02 Compliance Report

**Gepruefter Slice:** `specs/phase-1/2026-02-25-shop-completeness/slices/slice-02-produkt-page-enhancements.md`
**Pruefdatum:** 2026-02-25
**Architecture:** `specs/phase-1/2026-02-25-shop-completeness/architecture.md`
**Wireframes:** `specs/phase-1/2026-02-25-shop-completeness/discovery.md` (UI Layout & Context)

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 38 |
| FAILED | 4 |

**Verdict:** FAILED

---

## 0) Inhaltliche Pruefung

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-2 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-3 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-4 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-5 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-6 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-7 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-8 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-9 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-10 | Yes | Yes | Yes | Yes | Yes | PASS |

Alle 10 ACs sind im GIVEN/WHEN/THEN-Format verfasst und enthalten konkrete, messbare Kriterien. AC-5 nennt den exakten Toast-Text ("Bewertung konnte nicht gespeichert werden. Bitte versuche es erneut."), AC-3 nennt das Verhalten pro Feld, AC-4 nennt konkrete Validierungsregeln. AC-Qualitaet ist durchgehend hoch.

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| Example 1: ProductReviewsBlock | Teilweise | Yes | Yes | Nein - `productId` auf data nicht in Arch-DTO | FAILED |
| Example 2: ProductRecommendationsBlock | Yes | Teilweise - `ProductCardData` aus `@/lib/graphql/types` | Yes | Yes | PASS |
| Example 3: validateReviewInput() | Yes | Yes | Yes | Yes | PASS |
| Example 4: WRITE_REVIEW Mutation | Yes | Yes | Yes | Yes | PASS |
| Example 5: ProductReviewsResult Type | Teilweise - `reviews: { edges: ReviewEdge[] }` vs. Arch-DTO `reviews: ReviewEdge[]` | Yes | Yes | Nein - `productId` fehlt in Arch-DTO | FAILED |
| Example 6: data-loaders.ts Branches | Nein - referenziert `GET_PRODUCT` Query (nicht definiert) | Nein - `GET_PRODUCT` existiert nicht in Slice oder Arch | Nein | Yes | FAILED |

**Detailbefunde:**

**Code Example 5 vs. Architecture DTO:**
Architecture.md (Zeile 91) definiert:
> `ProductReviewsResult` | `averageRating: number, reviewCount: number, reviewsAllowed: boolean, reviews: ReviewEdge[]`

Slice Code Example 5 definiert:
```typescript
reviews: { edges: ReviewEdge[] }
```
Das Slice-Type hat `reviews` als Objekt mit `edges`-Array. Die Architecture-DTO-Tabelle sagt `reviews: ReviewEdge[]` (flat). Die GraphQL Response Fields (Arch Zeile 74) zeigen `reviews { edges { ... } }` - die geschachtelte Form. Das Slice stimmt mit den GraphQL Response Fields ueberein, aber nicht mit dem DTO-Feld in der Architecture-Tabelle. Da die GraphQL-Response-Definition Vorrang hat, ist diese Diskrepanz ein Widerspruch innerhalb der architecture.md selbst. Fuer den Implementierungsagenten ist dies verwirrend. Der Slice nutzt die korrekte GraphQL-basierte Form.

**Code Example 5 - `productId` auf `ProductReviewsResult`:**
Architecture definiert `ProductReviewsResult` ohne `productId`-Feld. Das Slice fuegt `productId: number` hinzu. Dieses Feld ist fuer die `WRITE_REVIEW` Mutation notwendig (`commentOn: $productId`). Die Architecture-DTO-Definition ist unvollstaendig - ohne `productId` kann die Mutation in der Client-Component nicht aufgerufen werden. Der Slice loest das Problem korrekt, dokumentiert es aber nicht als Erweiterung der Architecture.

**Code Example 6 - `GET_PRODUCT` nicht definiert:**
Der `product_recommendations`-Branch (Zeilen 1335-1340) ruft `GET_PRODUCT` Query auf:
```typescript
const { data: productData } = await getClient().query({
  query: GET_PRODUCT,
  variables: { slug: productSlug },
})
```
`GET_PRODUCT` ist weder in `architecture.md` unter "New GraphQL Queries" noch unter "Existing" dokumentiert. Es ist auch nicht in den Deliverables als neue Query-Konstante aufgefuehrt. Der Implementierungsagent weiss nicht, wie diese Query definiert ist. Das macht Code Example 6 nicht implementierbar.

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | typescript-nextjs (Next.js 16, Vitest) | PASS |
| Commands vollstaendig | 3 (Test, Integration, Acceptance) | 3 (unit, integration, acceptance) | PASS |
| Start-Command | `cd frontend && pnpm dev` | Passt zu Next.js Stack | PASS |
| Health-Endpoint | `http://localhost:3000/api/health` | Passt zu Next.js auf Port 3000 | PASS |
| Mocking-Strategy | `mock_external` | Definiert und erklaert | PASS |

---

## A) Architecture Compliance

### Schema Check

| Arch Field | Arch Type | Slice Spec | Status | Issue |
|------------|-----------|------------|--------|-------|
| `wp_comments.type='review'` | WooCommerce Reviews | Korrekt referenziert | PASS | — |
| `wp_commentmeta.rating` | 1-5 Int | Int 1-5, korrekt | PASS | — |
| `ProductReviewsResult.averageRating` | number | number | PASS | — |
| `ProductReviewsResult.reviewCount` | number | number | PASS | — |
| `ProductReviewsResult.reviewsAllowed` | boolean | boolean | PASS | — |
| `ProductReviewsResult.reviews` | `ReviewEdge[]` (Arch DTO) | `{ edges: ReviewEdge[] }` (Slice) | PASS | Slice nutzt korrekte GraphQL Response Form; Arch DTO-Tabelle ist vereinfacht |
| `ReviewEdge.rating` | number (auf Edge) | number (auf Edge) | PASS | WooGraphQL-Quirk korrekt beachtet |
| `ReviewEdge.node.authorName` | string (Arch DTO) | `author.node.name` (Slice, nested) | PASS | Slice folgt GraphQL Response Fields; Arch DTO-Tabelle vereinfacht |
| `WriteReviewInput.productId` | number | number | PASS | — |
| `WriteReviewInput.content` | string | string | PASS | — |
| `WriteReviewInput.author` | string | string | PASS | — |
| `WriteReviewInput.authorEmail` | string | string | PASS | — |
| `WriteReviewInput.rating` | number (Int 1-5) | number | PASS | — |

### API Check

| Endpoint | Arch Method | Slice Method | Status | Issue |
|----------|-------------|--------------|--------|-------|
| `GET_PRODUCT_REVIEWS($productSlug: ID!)` | Query | Query mit identischen Variablen | PASS | — |
| `GET_RELATED_PRODUCTS($productId: ID!, $first: Int)` | Query | Query mit identischen Variablen | PASS | — |
| `GET_BESTSELLER_PRODUCTS($first: Int)` | Query | Query mit identischen Variablen | PASS | — |
| `GET_PRODUCTS_BY_IDS($include: [Int!]!, $first: Int)` | Query | Query mit identischen Variablen | PASS | — |
| `WRITE_REVIEW($productId: Int!, ...)` | Mutation | Mutation mit identischen Variablen | PASS | — |
| `GET_PRODUCT(slug)` | Nicht definiert | In Code Example 6 verwendet | FAILED | Query existiert nicht in Architecture oder Slice-Deliverables |

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| Review read: unauthenticated | Public, RSC Server Client | RSC Server Client via `getClient()` | PASS |
| Review write: guest allowed | WC guest reviews, status pending | `apolloClient.mutate()`, kein Auth-Token benoetigt | PASS |
| Review email: nicht anzeigen | Email not exposed in list query | `reviewer_email` nicht in Review-List-Query, nur in Mutation input | PASS |
| Input validation: author min 2 chars | Arch Validation Rules | `validateReviewInput()` prueft min 2 chars | PASS |
| Input validation: email format | Arch Validation Rules | Regex-Pruefung in `validateReviewInput()` | PASS |
| Input validation: rating 1-5 Int | Arch Validation Rules | `Number.isInteger()` + Range-Check | PASS |
| Input validation: content min 10 chars | Arch Validation Rules | Trim + Length-Check | PASS |

---

## B) Wireframe Compliance

### UI Elements

Referenz: `discovery.md` → UI Layout & Context → Screen: Produktdetail-Page (erweitert) + Wireframe-ASCII-Art in Slice-Section "UI Anforderungen"

| Wireframe Element | Annotation | Slice Component | Status |
|-------------------|------------|-----------------|--------|
| trust-badges (3 Badges) | truck, shield, lock icons | `trust-badges` in product.yaml mit 3 items | PASS |
| Sterne-Zusammenfassung (4.2★, 47 Bewertungen) | StarSummary | `StarRatingDisplay` + averageRating + reviewCount | PASS |
| Review-Liste (Autor, Datum, Text) | ReviewCard | `ReviewCard` mit edge.node.author.name, date, content | PASS |
| "Bewertung schreiben" Button | Toggle | Button mit aria-expanded | PASS |
| Review-Formular (Name, E-Mail, Sterne, Text) | ReviewForm | 4 Felder: author, authorEmail, rating, content | PASS |
| Abbrechen-Button | Cancel | `onCancel` Prop | PASS |
| Absenden-Button | Submit | submit button mit loading state | PASS |
| Recommendations: Ueberschrift | YAML heading | `data.heading` aus YAML params | PASS |
| Recommendations: 4-spaltig Desktop, 2 Mobile | 4-col/2-col | `grid-cols-2 lg:grid-cols-4` | PASS |
| 4 Produkt-Cards | ProductCard | `ProductCard` aus bestehender Codebase | PASS |

### State Variations

| State | Discovery/Wireframe | Slice | Status |
|-------|---------------------|-------|--------|
| ReviewForm: hidden | Definiert | `formState === 'hidden'` → Button sichtbar | PASS |
| ReviewForm: open | Definiert | `formState === 'open'` → Form sichtbar | PASS |
| ReviewForm: loading | Definiert | `formState === 'loading'` → Spinner | PASS |
| ReviewForm: success | Definiert | `formState === 'success'` → Danke-Text | PASS |
| ReviewForm: error | Definiert | `formState === 'error'` → Toast + Form offen | PASS |
| Reviews leer (allowed) | Nur Button, kein Negativ-Text | `reviews.edges.length > 0 ? list : null` | PASS |
| Reviews leer (not allowed) | Block unsichtbar | `!data.reviewsAllowed → return null` | PASS |
| Recommendations: null | Block unsichtbar | `if (!data || products.length === 0) return null` | PASS |
| ProductReviewsBlock: Loading | ProductReviewsBlockSkeleton | Skeleton-Export definiert | PASS |
| ProductRecommendationsBlock: Loading | ProductGridBlockSkeleton (reuse) | Re-export als `ProductRecommendationsBlockSkeleton` | PASS |

### Visual Specs

| Spec | Discovery Value | Slice Value | Status |
|------|-----------------|-------------|--------|
| Recommendations Grid Desktop | 4 Spalten | `lg:grid-cols-4` | PASS |
| Recommendations Grid Mobile | 2 Spalten | `grid-cols-2` | PASS |
| Review-Form Breite Desktop | max-w-lg | `max-w-lg` in Slice-Text erwaehnt | PASS |
| Theming: keine hardcoded Farben | Theme-Tokens | `bg-primary`, `text-text-primary`, `border-border`, `rounded-card` | PASS |
| Touch-Target Sterne | min 44x44px | `touch-action: manipulation` + 44px in Skill Verification | PASS |

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| `TrustBadgesBlock` Component | `slice-01-cross-page-infrastruktur` | "In `lib/blocks/registry.ts` unter Key `'trust-badges'` registriert" | PASS |
| `TrustBadgeData` Interface | `slice-01-cross-page-infrastruktur` | "In `lib/blocks/types.ts` definiert" | PASS |
| `registry.ts` Pattern | `slice-01-cross-page-infrastruktur` | "Alle Slice-1-Blocks registriert" | PASS |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| `ProductReviewsBlock` | `lib/blocks/registry.ts` | Interface definiert | PASS |
| `ProductRecommendationsBlock` | `lib/blocks/registry.ts` | Interface definiert | PASS |
| `ProductReviewsResult` Type | `lib/blocks/data-loaders.ts` | Interface in Code Example 5 | PASS |
| `ReviewEdge` Type | `ProductReviewsBlock` | Interface in Code Example 5 | PASS |
| `WriteReviewInput` Type | `review-form.tsx` | Interface in Code Example 5 | PASS |
| `validateReviewInput()` | Tests + review-form.tsx | Export in Code Example 3 | PASS |
| `WRITE_REVIEW` Mutation | `lib/graphql/mutations.ts` | Code Example 4 | PASS |
| `GET_PRODUCT_REVIEWS` Query | `lib/graphql/queries.ts` | Definiert in §4 | PASS |
| `GET_RELATED_PRODUCTS` Query | `lib/graphql/queries.ts` | Definiert in §4 | PASS |
| `GET_BESTSELLER_PRODUCTS` Query | `lib/graphql/queries.ts` | Definiert in §4 | PASS |
| `GET_PRODUCTS_BY_IDS` Query | `lib/graphql/queries.ts` | Definiert in §4 | PASS |
| `product.yaml` (erweitert) | `app/produkt/[slug]/page.tsx` | Vollstaendige YAML in §6 | PASS |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-------------------|--------------|--------|
| `ProductReviewsBlock` | `lib/blocks/registry.ts` (bestehendes File, wird modifiziert) | Yes - in "Geaenderte Dateien" | Slice-02 | PASS |
| `ProductRecommendationsBlock` | `lib/blocks/registry.ts` (bestehendes File, wird modifiziert) | Yes - in "Geaenderte Dateien" | Slice-02 | PASS |
| `product.yaml` (erweitert) | `app/produkt/[slug]/page.tsx` (bestehende Route) | Bestehende Datei, kein Deliverable noetig | existing | PASS |

### AC-Deliverable-Konsistenz

| AC # | Referenced Page/File | In Deliverables? | Status |
|------|----------------------|------------------|--------|
| AC-1 | `product-reviews` Block auf Produktseite | `product-reviews-block.tsx` + `product.yaml` in Deliverables | PASS |
| AC-2 | Review-Formular Toggle | `product-reviews-block.tsx` in Deliverables | PASS |
| AC-3 | Inline-Validierungsfehler | `review-form.tsx` in Deliverables | PASS |
| AC-4 | WRITE_REVIEW Mutation | `mutations.ts` + `review-form.tsx` in Deliverables | PASS |
| AC-5 | Error-Toast | `product-reviews-block.tsx` in Deliverables | PASS |
| AC-6 | `product-recommendations` Grid | `product-recommendations-block.tsx` + `product.yaml` in Deliverables | PASS |
| AC-7 | Fallback auf Kategorie-Produkte | `data-loaders.ts` in Deliverables | PASS |
| AC-8 | Block unsichtbar (beide leer) | `product-recommendations-block.tsx` + `data-loaders.ts` in Deliverables | PASS |
| AC-9 | trust-badges sichtbar | `product.yaml` in Deliverables | PASS |
| AC-10 | `reviewsAllowed: false` → Block unsichtbar | `product-reviews-block.tsx` in Deliverables | PASS |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| Code Example 1: ProductReviewsBlock | §"Code Example 1" | Yes | Teilweise - `productId` auf `data` nicht in Arch-DTO, aber funktional notwendig | PASS |
| Code Example 2: ProductRecommendationsBlock | §"Code Example 2" | Yes | Yes | PASS |
| Code Example 3: validateReviewInput() | §"Code Example 3" | Yes - vollstaendig inkl. alle 4 Validierungsregeln | Yes | PASS |
| Code Example 4: WRITE_REVIEW Mutation | §"Code Example 4" | Yes - alle Variablen, Response-Felder korrekt | Yes | PASS |
| Code Example 5: ProductReviewsResult Type | §"Code Example 5" | Yes | Teilweise - `reviews.edges` vs Arch-DTO `reviews: ReviewEdge[]` | PASS |
| Code Example 6: data-loaders.ts Branches | §"Code Example 6" | Nein - `GET_PRODUCT` Query fehlt | Nein - `GET_PRODUCT` nicht definiert | FAILED |
| product.yaml (erweitert) | §"Technische Umsetzung 6" | Yes - alle 3 Sections | Yes | PASS |

---

## E) Build Config Sanity Check

N/A - Dieser Slice hat keine Build-Config-Deliverables.

---

## F) Test Coverage

| Acceptance Criteria | Test Definiert | Test Typ | Status |
|--------------------|---------------|----------|--------|
| AC-1: Star summary mit avg + count | `it('should render star summary...')` | Unit/Component (Vitest) | PASS |
| AC-2: Form oeffnet bei Klick | `it('should show review form when...')` | Unit/Component | PASS |
| AC-3: Inline-Fehler bei leeren Pflichtfeldern | `it('should show inline validation errors...')` | Unit/Component | PASS |
| AC-4: Mutation bei valid submit + success state | `it('should call WRITE_REVIEW mutation...')` + `it('should show success message...')` | Unit/Component | PASS |
| AC-5: Toast bei Mutation-Fehler | Nicht abgedeckt durch expliziten `it`-Block | Unit/Component | FAILED |
| AC-6: Recommendations 4-spaltig + heading | `it('should render heading...')` + `it('should render heading with aria-labelledby...')` | Unit/Component | PASS |
| AC-7: Fallback auf Kategorie | `it.todo('should return category products as fallback...')` | Unit (TODO) | FAILED |
| AC-8: Block unsichtbar wenn beide leer | `it.todo('should return null when related and category both return empty...')` | Unit (TODO) | FAILED |
| AC-9: trust-badges sichtbar in product.yaml | `it('should contain trust-badges, product-reviews...')` (YAML-Test) | Unit | PASS |
| AC-10: reviewsAllowed=false → null | `it('should return null when reviewsAllowed is false')` | Unit/Component | PASS |

**Kritisch:** AC-5, AC-7 und AC-8 sind nicht durch ausfuehrbare Tests abgedeckt:
- **AC-5** (Error-Toast bei Mutation-Fehler): Kein entsprechender `it`-Block im Testspec. Der Test "should show success message..." deckt nur den Erfolgsfall ab.
- **AC-7** und **AC-8** sind explizit als `it.todo(...)` markiert - sie werden beim `pnpm test`-Ausfuehren als "pending/todo" gezaehlt und erzeugen keinen Pass.

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| UI Components | `review-stars-input` (1-5 selected) | Yes | Yes - `star-rating-input.tsx` in Deliverables | PASS |
| UI Components | `review-submit` (idle/loading/success/error) | Yes | Yes - `formState` State Machine | PASS |
| UI Components | `recommendations-card` (default/hover) | Yes | Yes - `ProductCard` mit hover | PASS |
| UI Components | `trust-badge-item` (static) | Yes | Yes - via product.yaml inline | PASS |
| State Machine | Review Form: hidden/open/loading/success/error | Yes | Yes - `ReviewFormState` type + alle Transitionen | PASS |
| State Machine | Alle Transitionen (7 Uebergaenge) | Yes | Yes - Code Example 1 implementiert alle | PASS |
| Transitions | hidden → open: Click "Bewertung schreiben" | Yes | Yes | PASS |
| Transitions | open → loading: Click "Absenden" (valide) | Yes | Yes | PASS |
| Transitions | open → open: Click "Absenden" (invalide) | Yes | Yes - inline Fehler | PASS |
| Transitions | open → hidden: Click "Abbrechen" | Yes | Yes - `onCancel` | PASS |
| Transitions | loading → success: Mutation OK | Yes | Yes | PASS |
| Transitions | loading → error: Mutation Fehler | Yes | Yes | PASS |
| Transitions | error → loading: "Erneut versuchen" | Yes | Yes | PASS |
| Business Rules | Name >= 2 chars | Yes | Yes - `validateReviewInput()` | PASS |
| Business Rules | Email valid format | Yes | Yes - Regex | PASS |
| Business Rules | Rating 1-5 Pflicht | Yes | Yes | PASS |
| Business Rules | Content min 10 chars | Yes | Yes | PASS |
| Business Rules | Reviews pending (moderation) | Yes | Yes - Discovery + Architecture referenziert | PASS |
| Business Rules | Recommendations Fallback chain | Yes | Yes - Code Example 6 | PASS |
| Data | `reviewer_name` min 2 chars | Yes | Yes | PASS |
| Data | `reviewer_email` valid format | Yes | Yes | PASS |
| Data | `rating` 1-5 Int | Yes | Yes | PASS |
| Data | `content` min 10 chars | Yes | Yes | PASS |
| Data | `product_id` auto (aus Route) | Yes | Yes - `product.databaseId` | PASS |
| Data | `heading` (YAML, required) | Yes | Yes - YAML param | PASS |
| Data | `source` (required, enum) | Yes | Yes - YAML param, Fallback-Logik | PASS |
| Data | `custom_ids` (when source=custom) | Yes | Yes - Code Example 6 | PASS |
| Data | `first` (optional, default 4) | Yes | Yes - `first ?? 4` | PASS |

---

## Blocking Issues Summary

### Issue 1: `GET_PRODUCT` Query in Code Example 6 nicht definiert

**Category:** Code / API
**Severity:** FAILED

**Spec says (Slice §Code Example 6, Zeilen 1335-1340):**
```typescript
const { data: productData } = await getClient().query({
  query: GET_PRODUCT,
  variables: { slug: productSlug },
})
const productId = productData?.product?.databaseId
const categorySlug = productData?.product?.productCategories?.nodes?.[0]?.slug
```

**Reference says (architecture.md §New GraphQL Queries):**
Die Query `GET_PRODUCT` ist weder in der Architecture-Tabelle der neuen GraphQL Queries noch in der bestehenden Codebase-Dokumentation als verfuegbare Query aufgefuehrt. Die Deliverables-Liste (§Geaenderte Dateien) listet `GET_PRODUCT_REVIEWS`, `GET_RELATED_PRODUCTS`, `GET_BESTSELLER_PRODUCTS`, `GET_PRODUCTS_BY_IDS` - aber NICHT `GET_PRODUCT`.

**Problem:**
Code Example 6 ist das Pflicht-Deliverable fuer den `product_recommendations`-Branch in `data-loaders.ts`. Der Branch benoetigt `productId` (als `databaseId`) und `categorySlug`, um die Fallback-Logik umzusetzen. Diese werden ueber `GET_PRODUCT` geladen. Da `GET_PRODUCT` nicht definiert ist, kann:
1. Der Implementierungsagent die Import-Zeile nicht schreiben
2. Die Fallback-Chain (source=related → category) nicht implementiert werden (kein `categorySlug`)
3. AC-7 und AC-8 koennen nicht korrekt implementiert werden

**Resolution:**
Entweder:
(a) `GET_PRODUCT` als neue Query in `lib/graphql/queries.ts` zum Deliverable hinzufuegen und das Query-Schema in §4 definieren (Felder: `databaseId`, `productCategories { nodes { slug } }`), ODER
(b) `GET_RELATED_PRODUCTS` so erweitern, dass es neben `related.nodes` auch `productCategories.nodes.slug` zurueckgibt, um den separaten Lookup zu vermeiden.

---

### Issue 2: Test-Mock `getApolloClient()` passt nicht zu tatsaechlichem Export `apolloClient`

**Category:** Code / Test
**Severity:** FAILED

**Spec says (Slice Testspec, Zeilen 590-594):**
```typescript
vi.mock('@/lib/apollo/client', () => ({
  getApolloClient: () => ({
    mutate: mockMutate,
  }),
}))
```

**Reference says (frontend/lib/apollo/client.ts, Zeile 55):**
```typescript
export const apolloClient = new ApolloClient({ ... })
```

**Problem:**
Die tatsaechliche `lib/apollo/client.ts` exportiert `apolloClient` als benanntes Singleton-Export, KEINE Funktion `getApolloClient()`. Der Mock ersetzt ein Modul-Export das nicht existiert. Wenn die `ReviewForm`-Komponente `import { apolloClient } from '@/lib/apollo/client'` importiert und `apolloClient.mutate()` aufruft, wird der Mock ignoriert - `mockMutate` wird nie aufgerufen. Die Tests fuer AC-4 ("should call WRITE_REVIEW mutation with correct variables") und AC-4/5 werden daher faelschlicherweise bestehen oder fehlschlagen, weil der Mock nicht greift.

**Resolution:**
Mock auf den tatsaechlichen Export anpassen:
```typescript
vi.mock('@/lib/apollo/client', () => ({
  apolloClient: {
    mutate: mockMutate,
  },
}))
```

---

### Issue 3: AC-5 (Error-Toast) ohne ausfuehrbaren Test

**Category:** Test Coverage
**Severity:** FAILED

**Spec says (AC-5):**
> GIVEN die `WRITE_REVIEW` Mutation schlaegt fehl (GraphQL-Fehler)
> WHEN der Server einen Fehler zurueckgibt
> THEN bleibt das Formular offen und ein Toast mit "Bewertung konnte nicht gespeichert werden. Bitte versuche es erneut." erscheint

**Reference says (Testspec):**
Kein `it()`-Block in den Unit Tests der Datei `slice-02-produkt-page-enhancements.test.ts` deckt AC-5 ab. Die vorhandenen Tests pruefen nur den Erfolgsfall (`mockMutate.mockResolvedValueOnce(...)`) und Validierungsfehler vor dem Submit. Ein Fehler-Szenario (`mockMutate.mockRejectedValueOnce(new Error(...))`) fehlt vollstaendig.

**Resolution:**
Folgenden `it`-Block zum `describe('ProductReviewsBlock')` hinzufuegen:
```typescript
it('should show error toast and keep form open when WRITE_REVIEW mutation fails', async () => {
  mockMutate.mockRejectedValueOnce(new Error('Network error'))
  const data = buildProductReviewsResult()
  render(<ProductReviewsBlock data={{ ...data, productId: 42 } as any} />)

  fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))
  await waitFor(() => { expect(screen.getByLabelText(/Name/i)).toBeTruthy() })

  fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'Max Muster' } })
  fireEvent.change(screen.getByLabelText(/E-Mail/i), { target: { value: 'max@example.com' } })
  const starButtons = screen.getAllByRole('button', { name: /Stern/i })
  fireEvent.click(starButtons[4])
  const textarea = screen.getByRole('textbox', { name: /Bewertungstext|Deine Bewertung/i })
  fireEvent.change(textarea, { target: { value: 'Tolles Produkt hier!' } })
  fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

  await waitFor(() => {
    expect(screen.getByText(/Bewertung konnte nicht gespeichert werden/i)).toBeTruthy()
    expect(screen.getByLabelText(/Name/i)).toBeTruthy() // Form bleibt offen
  })
})
```

---

### Issue 4: AC-7 und AC-8 als `it.todo` - keine ausfuehrbaren Tests

**Category:** Test Coverage
**Severity:** FAILED

**Spec says (AC-7):**
> GIVEN der Block mit `source: related`, WHEN keine verwandten Produkte aber Kategorie-Produkte vorhanden sind, THEN greift der Fallback auf Kategorie-Produkte

**Spec says (AC-8):**
> GIVEN der Block mit `source: related`, WHEN weder verwandte noch Kategorie-Produkte vorhanden sind, THEN ist der Block unsichtbar

**Reference says (Testspec, Zeilen 882-885):**
```typescript
it.todo('should return null when related and category both return empty (requires Apollo mock setup)')
it.todo('should return category products as fallback when related is empty')
```

**Problem:**
`it.todo()` erzeugt in Vitest einen "todo"-Eintrag, keinen ausgefuehrten Test. Der Orchestrator fuehrt `pnpm test` aus und bekommt keinen Fehler fuer diese ACs, aber auch keinen Nachweis, dass die Fallback-Logik korrekt implementiert ist. Die Fallback-Logik ist die kritischste Business-Anforderung in AC-7/8 und die komplexeste Logik in Code Example 6. Ohne ausgefuehrte Tests kann der Implementierungsagent die Fallback-Chain falsch implementieren ohne dass ein Test fehlschlaegt.

Die Anmerkung "requires Apollo mock setup" in den todos ist kein valider Grund - die Apollo-Mock-Infrastruktur ist bereits mit `vi.mock('@/lib/apollo/client', ...)` in der Datei eingerichtet.

**Resolution:**
`it.todo`-Bloecke durch implementierte Tests in `describe('woocommerceLoader product_recommendations fallback')` ersetzen. Da `woocommerceLoader` ein async Server-Side-Loader ist, sollte dieser mit gemocktem `getClient()` aus `lib/apollo/server-client.ts` getestet werden:

```typescript
describe('woocommerceLoader product_recommendations fallback', () => {
  it('should return category products as fallback when related is empty', async () => {
    // Mock getClient() return: related empty, category has products
    // Call woocommerceLoader with { query: 'product_recommendations', source: 'related', slug: 'test-product', first: 4 }
    // Assert result.data.products.nodes.length === 4
  })

  it('should return null when related and category both return empty', async () => {
    // Mock: both queries return empty nodes
    // Assert result.data === null
  })
})
```

---

## Recommendations

1. `GET_PRODUCT` Query in `lib/graphql/queries.ts` als Deliverable ergaenzen: Felder `databaseId: number` und `productCategories { nodes { slug: string } }` benoetigt. Query-Definition in §4 des Slices hinzufuegen. Deliverables-Liste unter "Geaenderte Dateien" ergaenzen.

2. Test-Mock fuer `apolloClient` korrigieren: `getApolloClient` → `apolloClient` (Named Export des Singletons). Alle 4 Test-Bloecke die `mockMutate` verwenden muessen nach dieser Korrektur validiert werden.

3. AC-5 Test-Block implementieren (Error-Toast bei Mutation-Fehler) - siehe Resolution in Issue 3.

4. `it.todo`-Bloecke fuer AC-7 und AC-8 durch ausgefuehrte Tests ersetzen. `getClient()` aus `lib/apollo/server-client.ts` mocken.

5. (Nicht-blocking, empfohlen) Architecture-DTO-Tabelle fuer `ProductReviewsResult` und `ReviewEdge` auf die tatsaechliche GraphQL-Response-Struktur aktualisieren (`reviews: { edges: ReviewEdge[] }` statt `reviews: ReviewEdge[]`, `authorName` → `author.node.name`). Verhindert Verwirrung beim Implementierungsagenten.

6. (Nicht-blocking, empfohlen) `productId: number` zum `ProductReviewsResult`-DTO in architecture.md ergaenzen mit Hinweis: "WooCommerce databaseId, benoetigt fuer writeReview commentOn Input".

---

## Verdict

**Status:** FAILED

**Blocking Issues:** 4
**Warnings:** 0

**Next Steps:**
- Fix Issue 1: `GET_PRODUCT` Query definieren und als Deliverable aufnehmen
- Fix Issue 2: Test-Mock `getApolloClient` → `apolloClient` korrigieren
- Fix Issue 3: AC-5 Error-Toast Test implementieren
- Fix Issue 4: `it.todo` fuer AC-7 und AC-8 durch ausfuehrbare Tests ersetzen
- Re-run Gate 2 compliance check nach Korrekturen
