# Slice 2: Produkt-Page Enhancements implementieren

> **Slice 2 von 6** für `Shop Completeness — Blocks & Pages`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-01-cross-page-infrastruktur.md` |
> | **Nächster:** | `slice-03-kategorie-page-enhancements.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-02-produkt-page-enhancements` |
| **Test** | `pnpm test tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-cross-page-infrastruktur"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit/Component Tests (kein Playwright)
- **Dependencies**: Slice 1 (trust-badges Block, registry.ts Erweiterungen) muss fertig sein

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` (next ^16.1.6, vitest ^3.0.0, typescript ^5.7.0, @tailwindcss/postcss ^4.0.0).

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts` |
| **Integration Command** | `pnpm test tests/slices/shop-completeness/` |
| **Acceptance Command** | `pnpm test tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts --reporter=verbose` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: typescript-nextjs (Next.js 16 App Router, Vitest, Tailwind CSS v4)
- **Mocking Strategy**: Apollo Client + Server Client werden gemockt; WooCommerce GraphQL wird nicht echt aufgerufen

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Cross-Page Infrastruktur | Done | `slice-01-cross-page-infrastruktur.md` |
| 2 | Produkt-Page Enhancements | Ready | `slice-02-produkt-page-enhancements.md` |
| 3 | Kategorie-Page Enhancements | Pending | `slice-03-kategorie-page-enhancements.md` |
| 4 | Homepage Enhancements | Pending | `slice-04-homepage-enhancements.md` |
| 5 | Suchseite | Pending | `slice-05-suchseite.md` |
| 6 | Neue Pages | Pending | `slice-06-neue-pages.md` |

---

## Kontext & Ziel

Dieser Slice ergänzt die bestehende Produktdetail-Seite (`/produkt/[slug]`) um drei neue Sections:

1. **`product-reviews` Block** — Sterne-Zusammenfassung + Review-Liste (öffentlich lesbar) + Review-Formular (schreiben, kein Login nötig). Höchster Conversion-Impact: Reviews erhöhen die Conversion Rate um 15–30%.
2. **`product-recommendations` Block** — Horizontales Produkt-Grid mit konfigurierbarer Quelle (related/category/bestsellers/custom). AOV +20–35% durch Cross-sell.
3. **YAML-Erweiterung `themes/default/pages/product.yaml`** — Alle neuen Blocks + bereits in Slice 1 implementierter `trust-badges` Block werden in die Produktseite eingetragen.

**Aktuelle Probleme:**
1. Produktseite hat kein Social Proof → niedrige Conversion Rate
2. Kein Cross-sell → niedriger durchschnittlicher Bestellwert (AOV)
3. Keine Trust-Signale auf Produktseite (Trust Badges fehlen in product.yaml)

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Block Inventory + Data Flow + GraphQL Queries

```
Block Inventory (Slice 2 relevant):
  #7  product-reviews         Client Component   woocommerce  ProductReviewsResult    ProductReviewsBlockSkeleton
  #8  product-recommendations Server Component   woocommerce  { products: { nodes } } ProductGridBlockSkeleton (reuse)
  #3  trust-badges            Server Component   inline       TrustBadgeData          null (inline)

Data Flow:
  Browser → /produkt/[slug] (RSC)
    → loadPageConfig("product", theme, { slug })
    → SectionRenderer → foreach block:
        product-reviews:
          woocommerceLoader(query: 'product_reviews', slug)
            → GET_PRODUCT_REVIEWS(productSlug: ID!)
            → ProductReviewsResult { averageRating, reviewCount, reviewsAllowed, reviews }
          Renders: StarSummary + ReviewList + ReviewForm (Client)
        product-recommendations:
          woocommerceLoader(query: 'product_recommendations', source, productSlug, first)
            → Fallback chain: related → category → null
          Renders: Heading + 4-column ProductCard grid
        trust-badges (from Slice 1):
          inlineLoader → TrustBadgeData from YAML
          Renders: 3 icon+text badges

Review Submit (Client-Side):
  ReviewForm (Client Component)
    → validates: name >= 2 chars, email valid, rating 1-5, content >= 10 chars
    → apolloClient.mutate(WRITE_REVIEW, { productId, content, author, authorEmail, rating })
    → WooCommerce creates wp_comment (status: pending)
    → success: hide form, show "Danke"-Message
    → error: show toast, keep form open
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/lib/graphql/queries.ts` | Neu: `GET_PRODUCT_REVIEWS`, `GET_RELATED_PRODUCTS`, `GET_BESTSELLER_PRODUCTS`, `GET_PRODUCTS_BY_IDS`, `GET_PRODUCT_CATEGORY` |
| `frontend/lib/graphql/mutations.ts` | Neu: Datei erstellen + `WRITE_REVIEW` Mutation |
| `frontend/lib/blocks/types.ts` | Neu: `ProductReviewsResult`, `ReviewEdge`, `WriteReviewInput`, `ProductRecommendationsParams` + WooCommerceLoaderParams erweitern |
| `frontend/lib/blocks/data-loaders.ts` | Neu: `product_reviews` + `product_recommendations` Branches in `woocommerceLoader` |
| `frontend/lib/blocks/registry.ts` | Neu: `product-reviews` + `product-recommendations` registrieren |
| `frontend/components/blocks/product-reviews-block.tsx` | Neuer Client Block: StarSummary + ReviewList + ReviewForm |
| `frontend/components/blocks/product-recommendations-block.tsx` | Neuer Server Block: Heading + ProductCard Grid |
| `frontend/themes/default/pages/product.yaml` | Erweitern: trust-badges, product-reviews, product-recommendations Sections hinzufügen |

### 2. Datenfluss

```
product.yaml (YAML-Config)
  ↓ loadPageConfig("product", theme, { slug })
  ↓ SectionRenderer

─── product-reviews Block ───
  woocommerceLoader({ query: 'product_reviews', slug })
    → getClient().query(GET_PRODUCT_REVIEWS, { productSlug: slug })
    → WooCommerce: wp_comments (type=review) + wp_commentmeta (rating)
    → Transform: { averageRating, reviewCount, reviewsAllowed, reviews: ReviewEdge[] }
  ProductReviewsBlock (Client Component):
    → starSummary: averageRating + reviewCount
    → reviewList: reviews.map(edge => ReviewCard)
    → reviewForm: hidden → open → loading → success/error

─── product-recommendations Block ───
  woocommerceLoader({ query: 'product_recommendations', source, productSlug, first: 4 })
    → source='related': GET_RELATED_PRODUCTS(productSlug, first)
      → empty? → GET_PRODUCTS_PAGINATED(categorySlug, first, exclude current)
        → empty? → return null (block hidden)
    → source='category'/'bestsellers'/'custom': direct query → empty? → null
  ProductRecommendationsBlock (Server Component):
    → heading aus YAML params
    → 4-column grid mit ProductCard
    → returns null wenn data null/leer

─── trust-badges Block (aus Slice 1) ───
  inlineLoader({ props: { items: [...] } }) → static render
```

### 3. State-Änderungen

Review Form State Machine (Client Component):

```typescript
// Mögliche Zustände des Review-Formulars
type ReviewFormState = 'hidden' | 'open' | 'loading' | 'success' | 'error'

// Übergänge:
// hidden → open:    Klick "Bewertung schreiben"
// open → loading:   Klick "Absenden" (valide Eingaben)
// open → open:      Klick "Absenden" (invalide Eingaben) → inline Fehler
// open → hidden:    Klick "Abbrechen"
// loading → success: Mutation erfolgreich → form versteckt + Danke-Text
// loading → error:  Mutation Fehler → Toast + form bleibt offen
// error → loading:  Klick "Erneut versuchen"
```

### 4. GraphQL Queries (neu in `lib/graphql/queries.ts`)

**`GET_PRODUCT_REVIEWS`**

```graphql
query GetProductReviews($productSlug: ID!) {
  product(id: $productSlug, idType: SLUG) {
    databaseId
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
          author {
            node {
              name
            }
          }
        }
      }
    }
  }
}
```

**`GET_RELATED_PRODUCTS`**

```graphql
query GetRelatedProducts($productId: ID!, $first: Int) {
  product(id: $productId, idType: DATABASE_ID) {
    related(first: $first) {
      nodes {
        ...ProductCardFields
      }
    }
  }
}
```

**`GET_BESTSELLER_PRODUCTS`**

```graphql
query GetBestsellerProducts($first: Int) {
  products(
    first: $first
    where: { orderby: [{ field: TOTAL_SALES, order: DESC }] }
  ) {
    nodes {
      ...ProductCardFields
    }
  }
}
```

**`GET_PRODUCTS_BY_IDS`**

```graphql
query GetProductsByIds($include: [Int!]!, $first: Int) {
  products(
    first: $first
    where: { include: $include }
  ) {
    nodes {
      ...ProductCardFields
    }
  }
}
```

**`GET_PRODUCT_CATEGORY`** (neu — schlanke Query nur für Recommendations-Loader)

```graphql
query GetProductCategory($slug: ID!) {
  product(id: $slug, idType: SLUG) {
    databaseId
    productCategories {
      nodes {
        slug
      }
    }
  }
}
```

> **Hinweis:** Diese Query ist bewusst minimal gehalten. Sie liefert ausschliesslich `databaseId` (als `Int` benoetigt fuer `GET_RELATED_PRODUCTS`) und den ersten `categorySlug` (fuer den Category-Fallback). Der bestehende `GET_PRODUCT` Query laedt den vollen `ProductDetailFields`-Fragment und ist fuer den Recommendations-Loader zu schwergewichtig.

### 5. GraphQL Mutation (neu: `lib/graphql/mutations.ts`)

**`WRITE_REVIEW`**

```graphql
mutation WriteReview(
  $productId: Int!
  $content: String!
  $author: String!
  $authorEmail: String!
  $rating: Int!
) {
  writeReview(
    input: {
      commentOn: $productId
      content: $content
      author: $author
      authorEmail: $authorEmail
      rating: $rating
    }
  ) {
    rating
    review {
      id
      content
      date
    }
  }
}
```

### 6. YAML-Erweiterung `themes/default/pages/product.yaml`

```yaml
# themes/default/pages/product.yaml
# Produktseite Block-Konfiguration — Sections-Format

sections:
  - columns: 2
    gap: "gap-16"
    blocks:
      - type: product-gallery
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"

      - type: product-purchase
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"

  - columns: 1
    gap: "gap-8"
    blocks:
      - type: product-description
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"

  - columns: 1
    blocks:
      - type: trust-badges
        content_source: inline
        params:
          props:
            items:
              - icon: truck
                text: "Versand in 3–5 Werktagen"
              - icon: shield
                text: "30 Tage Rückgabe"
              - icon: lock
                text: "Sichere Zahlung"

  - columns: 1
    blocks:
      - type: product-reviews
        content_source: woocommerce
        params:
          query: product_reviews
          slug: "$route.slug"

  - columns: 1
    blocks:
      - type: product-recommendations
        content_source: woocommerce
        params:
          query: product_recommendations
          source: related
          slug: "$route.slug"
          first: 4
          heading: "Das könnte dir auch gefallen"
```

### 7. Produkt Recommendations Fallback-Logik (in data-loaders.ts)

```
Input: source (YAML), slug (current product), first (default: 4), customIds?

Fallback Chain:
  source=related:
    1. GET_RELATED_PRODUCTS(productId, first)
    2. Leer? → GET_PRODUCTS_PAGINATED(categorySlug, first, exclude: [productId])
    3. Leer? → return null (Block rendert nichts)
  source=category:
    1. GET_PRODUCTS_PAGINATED(categorySlug, first, exclude: [productId])
    2. Leer? → return null
  source=bestsellers:
    1. GET_BESTSELLER_PRODUCTS(first)
    2. Leer? → return null
  source=custom:
    1. GET_PRODUCTS_BY_IDS(customIds.split(',').map(Number), first)
    2. Leer? → return null
```

### 8. Abhängigkeiten

| Paket | Status | Verwendung |
|-------|--------|------------|
| `@apollo/client` | Bereits vorhanden | Review-Mutation via `apolloClient.mutate()` |
| `lib/apollo/client.ts` | Bereits vorhanden | Client-seitige Mutation |
| `lib/apollo/server-client.ts` | Bereits vorhanden | Reviews-Query (RSC) |
| `lib/blocks/registry.ts` | Slice 1 erweitert | Neue Blocks registrieren |
| `TrustBadgesBlock` | Aus Slice 1 | Bereits in registry.ts |

### 9. Wiederverwendete Code-Bausteine

| Funktion/Komponente | Datei | Verwendung |
|---------------------|-------|------------|
| `ProductCard` | `components/product/product-card.tsx` | Im product-recommendations Grid |
| `ProductGridBlockSkeleton` | `components/blocks/product-grid-block.tsx` | Als Skeleton für product-recommendations |
| `getClient()` | `lib/apollo/server-client.ts` | RSC Queries (product-reviews, recommendations) |
| `apolloClient` | `lib/apollo/client.ts` | Review-Mutation (Client Component) |
| `BlockComponentProps<T>` | `lib/blocks/types.ts` | Interface für beide neuen Blocks |

---

## UI Anforderungen

### Wireframe (aus discovery.md)

> **Quelle:** `discovery.md` → UI Layout & Context → Screen: Produktdetail-Page (erweitert)

```
┌─────────────────────────────────────────────────────────────┐
│  [product-gallery]          [product-purchase]               │ ← 2-col (existing)
├─────────────────────────────────────────────────────────────┤
│  [product-description]                                       │ ← 1-col (existing)
├─────────────────────────────────────────────────────────────┤
│  [🚚 Versand 3-5 Tage]  [🛡 30 Tage Rückgabe]  [🔒 Sicher] │ ← trust-badges (Slice 1)
├─────────────────────────────────────────────────────────────┤
│  Kundenbewertungen                                           │
│  ★★★★☆  4.2 von 5  ·  47 Bewertungen                        │
│  ─────────────────────────────────────────────────          │
│  [★★★★★] Max M. · 12. Jan 2026                              │
│  "Tolles Produkt! Qualität top, Versand schnell."            │
│  ─────────────────────────────────────────────────          │
│  [★★★★☆] Anna K. · 5. Feb 2026                              │
│  "Schönes Design, bin sehr zufrieden."                       │
│  ─────────────────────────────────────────────────          │
│  [Bewertung schreiben ▼]                                     │
│  ┌───────────────────────────────────────────────┐          │
│  │ Name: ______________________                  │          │ ← nur wenn open
│  │ E-Mail: ____________________                  │          │
│  │ Bewertung: ☆☆☆☆☆                             │          │
│  │ Bewertung: ________________________           │          │
│  │ [Abbrechen]              [Absenden ▶]         │          │
│  └───────────────────────────────────────────────┘          │
├─────────────────────────────────────────────────────────────┤
│  Das könnte dir auch gefallen                                │
│  ┌──────┐  ┌──────┐  ┌──────┐  ┌──────┐                    │ ← 4 Desktop
│  │ Img  │  │ Img  │  │ Img  │  │ Img  │                    │ ← 2 Mobile
│  │Name  │  │Name  │  │Name  │  │Name  │                    │
│  │19,99€│  │24,99€│  │17,99€│  │22,99€│                    │
│  └──────┘  └──────┘  └──────┘  └──────┘                    │
└─────────────────────────────────────────────────────────────┘
```

**Referenz Skills für UI-Implementation:**
- `.claude/skills/react-best-practices/SKILL.md` — `async-suspense-boundaries`, `rerender-memo`, `rerender-derived-state`
- `.claude/skills/web-design/SKILL.md` — Form Labels, Inline Errors, aria-live für async Updates, Star Rating aria
- `.claude/skills/tailwind-v4/SKILL.md` — Theme Tokens (`bg-primary`, `text-text-primary`), Mobile-first

### 1. ProductReviewsBlock (Client Component)

**Komponenten & Dateien:**
- `components/blocks/product-reviews-block.tsx` — Haupt-Client-Component (gesamter Block)
- `components/reviews/star-rating-display.tsx` — Sterne-Anzeige (read-only, 0.5-Schritte)
- `components/reviews/star-rating-input.tsx` — Sterne-Eingabe (interaktiv, 1-5)
- `components/reviews/review-card.tsx` — Einzelne Review-Karte
- `components/reviews/review-form.tsx` — Bewertungsformular

**Verhalten:**
- Block lädt Reviews via RSC-Query (averageRating, reviewCount, review-Liste)
- Review-Form ist initial versteckt (`state: 'hidden'`)
- Button "Bewertung schreiben" öffnet Form (slide-in Animation)
- Form-Submit validiert Client-seitig vor Mutation
- Submit löst `apolloClient.mutate(WRITE_REVIEW)` aus
- Success: Form schließt, Danke-Text erscheint ("Deine Bewertung wird nach Prüfung angezeigt")
- Error: Toast erscheint, Form bleibt offen

**Zustände:**
- Loading (Skeleton): `ProductReviewsBlockSkeleton` — Grau-Blöcke für StarSummary + 2 Review-Cards
- Leer (keine Reviews + reviewsAllowed=true): Nur "Bewertung schreiben"-Button, kein "Noch keine Bewertungen"-Text (der lädt unnötig negativ)
- Leer (reviewsAllowed=false): Block rendert null (kein leerer Bereich)
- Error (GraphQL-Fehler): Block rendert null (silent fail)

**Design Patterns (aus Skills):**
- [x] Accessibility: `aria-label` für Sterne-Buttons ("1 Stern", "2 Sterne", ...), `aria-live="polite"` für Formular-Statusmeldungen
- [x] Animation: slide-in via `transform: translateY` + `opacity` (`prefers-reduced-motion` berücksichtigt)
- [x] Responsive: Form ist fullwidth mobile, max-w-lg desktop
- [x] Performance: `'use client'` nur für diesen Block — Reviews-Daten werden via RSC-Props übergeben

### 2. ProductRecommendationsBlock (Server Component)

**Komponenten & Dateien:**
- `components/blocks/product-recommendations-block.tsx` — Server Component

**Verhalten:**
- Zeigt YAML-konfigurierbare Überschrift (`heading` aus params)
- Horizontales Grid: 4 Spalten Desktop, 2 Spalten Mobile
- Kein Render wenn data null (leerer Container ist verboten!)
- Wiederverwendet `ProductCard` aus bestehendem Codebase

**Zustände:**
- Loading (Skeleton): `ProductGridBlockSkeleton` (count=4) wiederverwendet
- Leer/Error: `return null` — Block ist unsichtbar

**Design Patterns (aus Skills):**
- [x] Accessibility: `aria-labelledby` mit Überschrift-ID
- [x] Responsive: `grid-cols-2 lg:grid-cols-4` (Mobile-first)
- [x] Performance: Server Component (kein JS-Bundle für Grid)

### 3. Accessibility

- [x] Sterne-Rating-Input: Jeder Stern-Button hat `aria-label="N Sterne"` und `aria-pressed` State
- [x] Review-Form: Alle Inputs haben `<label>` oder `aria-label`
- [x] Form-Fehler: `aria-invalid="true"` + `aria-describedby` auf Fehler-Span
- [x] Danke-Text: `aria-live="polite"` Container
- [x] Review-Form-Toggle: `aria-expanded` auf dem Toggle-Button
- [x] Images in ProductCard: bereits `alt` + `width`/`height` (bestehende Komponente)

---

## Acceptance Criteria

1) GIVEN die Produktdetail-Seite wird geladen
   WHEN das Produkt mindestens eine genehmigte Bewertung hat
   THEN zeigt der `product-reviews` Block die Sterne-Zusammenfassung (Durchschnitt + Anzahl) und die Review-Liste unterhalb von `product-description`

2) GIVEN der `product-reviews` Block ist sichtbar
   WHEN der User auf "Bewertung schreiben" klickt
   THEN erscheint das Review-Formular mit den Feldern: Name, E-Mail, Sterne-Auswahl (1-5), Bewertungstext

3) GIVEN das Review-Formular ist offen
   WHEN der User "Absenden" klickt ohne alle Pflichtfelder zu füllen
   THEN erscheinen Inline-Fehlermeldungen direkt beim jeweiligen Feld und das Formular bleibt offen

4) GIVEN das Review-Formular ist korrekt ausgefüllt (Name >= 2 Zeichen, gültige E-Mail, Rating 1-5, Content >= 10 Zeichen)
   WHEN der User "Absenden" klickt
   THEN wechselt der Button zu einem Lade-Spinner, die Mutation `WRITE_REVIEW` wird abgeschickt, und bei Erfolg verschwindet das Formular und ein Danke-Text erscheint

5) GIVEN die `WRITE_REVIEW` Mutation schlägt fehl (GraphQL-Fehler)
   WHEN der Server einen Fehler zurückgibt
   THEN bleibt das Formular offen und ein Toast mit "Bewertung konnte nicht gespeichert werden. Bitte versuche es erneut." erscheint

6) GIVEN der `product-recommendations` Block ist in `product.yaml` mit `source: related` konfiguriert
   WHEN das Produkt verwandte Produkte hat
   THEN zeigt der Block die Überschrift aus YAML (`heading`) + ein 4-spaltige Grid mit Produkt-Cards (Desktop), 2-spaltig (Mobile)

7) GIVEN der `product-recommendations` Block ist mit `source: related` konfiguriert
   WHEN das Produkt keine verwandten Produkte hat, aber Produkte aus derselben Kategorie existieren
   THEN greift der Fallback auf Kategorie-Produkte (excluding aktuelles Produkt) und zeigt diese

8) GIVEN der `product-recommendations` Block ist mit `source: related` konfiguriert
   WHEN weder verwandte Produkte noch Kategorie-Produkte vorhanden sind
   THEN ist der Block unsichtbar (kein leerer Container, kein Heading ohne Inhalt)

9) GIVEN `product.yaml` wurde erweitert
   WHEN die Produktdetail-Seite geladen wird
   THEN ist der `trust-badges` Block mit den drei Badges "Versand in 3–5 Werktagen", "30 Tage Rückgabe", "Sichere Zahlung" unterhalb von `product-description` sichtbar

10) GIVEN das Produkt `reviewsAllowed: false` hat (WooCommerce-Einstellung)
    WHEN die Produktseite geladen wird
    THEN ist der `product-reviews` Block vollständig unsichtbar (kein leerer Bereich)

---

## Testfälle

**WICHTIG:** Tests müssen VOR der Implementierung definiert werden! Der Orchestrator führt diese Tests automatisch nach der Slice-Implementierung aus.

### Test-Datei

**Konvention:** `tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import React from 'react'

// ─── Hilfsfunktionen ────────────────────────────────────────────────────────

function buildReviewEdge(overrides?: Partial<{ rating: number; name: string; content: string; id: string }>) {
  return {
    rating: overrides?.rating ?? 4,
    node: {
      id: overrides?.id ?? 'review-1',
      content: overrides?.content ?? 'Tolles Produkt!',
      date: '2026-01-12',
      author: { node: { name: overrides?.name ?? 'Max M.' } },
    },
  }
}

function buildProductReviewsResult(overrides?: {
  averageRating?: number
  reviewCount?: number
  reviewsAllowed?: boolean
  reviews?: ReturnType<typeof buildReviewEdge>[]
}) {
  return {
    averageRating: overrides?.averageRating ?? 4.2,
    reviewCount: overrides?.reviewCount ?? 3,
    reviewsAllowed: overrides?.reviewsAllowed ?? true,
    reviews: { edges: overrides?.reviews ?? [buildReviewEdge()] },
  }
}

// ─── Mocks ──────────────────────────────────────────────────────────────────

const mockMutate = vi.fn()

vi.mock('@/lib/apollo/client', () => ({
  apolloClient: {
    mutate: mockMutate,
  },
}))

vi.mock('@/lib/graphql/mutations', () => ({
  WRITE_REVIEW: 'WRITE_REVIEW_MUTATION',
}))

// ─── ProductReviewsBlock ─────────────────────────────────────────────────────

describe('ProductReviewsBlock', () => {
  // Lazy import nach Mock-Setup
  let ProductReviewsBlock: React.ComponentType<{ data: ReturnType<typeof buildProductReviewsResult> | null }>

  beforeEach(async () => {
    vi.clearAllMocks()
    const mod = await import('@/components/blocks/product-reviews-block')
    ProductReviewsBlock = mod.ProductReviewsBlock
  })

  it('should render star summary with average rating and review count', () => {
    const data = buildProductReviewsResult({ averageRating: 4.2, reviewCount: 47 })
    render(<ProductReviewsBlock data={data} />)

    expect(screen.getByText(/4[,.]2/)).toBeTruthy()
    expect(screen.getByText(/47/)).toBeTruthy()
  })

  it('should render review list with author names and content', () => {
    const data = buildProductReviewsResult({
      reviews: [
        buildReviewEdge({ name: 'Max M.', content: 'Super Qualität!' }),
        buildReviewEdge({ id: 'r2', name: 'Anna K.', content: 'Schönes Design.' }),
      ],
    })
    render(<ProductReviewsBlock data={data} />)

    expect(screen.getByText('Max M.')).toBeTruthy()
    expect(screen.getByText('Super Qualität!')).toBeTruthy()
    expect(screen.getByText('Anna K.')).toBeTruthy()
  })

  it('should show review form when "Bewertung schreiben" is clicked', async () => {
    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={data} />)

    const toggleButton = screen.getByRole('button', { name: /Bewertung schreiben/i })
    fireEvent.click(toggleButton)

    await waitFor(() => {
      expect(screen.getByLabelText(/Name/i)).toBeTruthy()
      expect(screen.getByLabelText(/E-Mail/i)).toBeTruthy()
    })
  })

  it('should show inline validation errors when submitting empty form', async () => {
    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={data} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))

    await waitFor(() => {
      expect(screen.getByLabelText(/Name/i)).toBeTruthy()
    })

    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/Bitte gib deinen Namen ein/i)).toBeTruthy()
    })
  })

  it('should validate minimum name length of 2 characters', async () => {
    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={data} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))

    await waitFor(() => {
      expect(screen.getByLabelText(/Name/i)).toBeTruthy()
    })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'A' } })
    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/min. 2 Zeichen/i)).toBeTruthy()
    })
  })

  it('should validate minimum content length of 10 characters', async () => {
    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={data} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))
    await waitFor(() => { expect(screen.getByLabelText(/Name/i)).toBeTruthy() })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'Max Muster' } })
    fireEvent.change(screen.getByLabelText(/E-Mail/i), { target: { value: 'max@example.com' } })
    // Rating wird nicht gesetzt, Content zu kurz
    const textarea = screen.getByRole('textbox', { name: /Bewertungstext|Deine Bewertung/i })
    fireEvent.change(textarea, { target: { value: 'Kurz' } })
    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/mindestens 10 Zeichen/i)).toBeTruthy()
    })
  })

  it('should call WRITE_REVIEW mutation with correct variables on valid submit', async () => {
    mockMutate.mockResolvedValueOnce({
      data: { writeReview: { rating: 5, review: { id: 'r-new', content: 'Super!', date: '2026-02-25' } } },
    })

    const data = buildProductReviewsResult()
    // ProductReviewsBlock braucht productId — via data oder prop
    render(<ProductReviewsBlock data={{ ...data, productId: 42 } as any} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))
    await waitFor(() => { expect(screen.getByLabelText(/Name/i)).toBeTruthy() })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'Max Muster' } })
    fireEvent.change(screen.getByLabelText(/E-Mail/i), { target: { value: 'max@example.com' } })
    // Stern-Rating: Klick auf 5-Sterne-Button
    const starButtons = screen.getAllByRole('button', { name: /Stern/i })
    fireEvent.click(starButtons[4]) // 5. Stern
    const textarea = screen.getByRole('textbox', { name: /Bewertungstext|Deine Bewertung/i })
    fireEvent.change(textarea, { target: { value: 'Tolles Produkt, sehr empfehlenswert!' } })

    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledWith(
        expect.objectContaining({
          variables: expect.objectContaining({
            author: 'Max Muster',
            authorEmail: 'max@example.com',
            rating: 5,
            content: 'Tolles Produkt, sehr empfehlenswert!',
          }),
        })
      )
    })
  })

  it('should show success message and hide form after successful mutation', async () => {
    mockMutate.mockResolvedValueOnce({
      data: { writeReview: { rating: 4, review: { id: 'r-new', content: 'Toll!', date: '2026-02-25' } } },
    })

    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={{ ...data, productId: 42 } as any} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))
    await waitFor(() => { expect(screen.getByLabelText(/Name/i)).toBeTruthy() })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'Max Muster' } })
    fireEvent.change(screen.getByLabelText(/E-Mail/i), { target: { value: 'max@example.com' } })
    const starButtons = screen.getAllByRole('button', { name: /Stern/i })
    fireEvent.click(starButtons[3])
    const textarea = screen.getByRole('textbox', { name: /Bewertungstext|Deine Bewertung/i })
    fireEvent.change(textarea, { target: { value: 'Sehr gutes Produkt!' } })
    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/wird.*geprüft|Danke.*Bewertung/i)).toBeTruthy()
    })

    // Form-Felder sollten nicht mehr sichtbar sein
    expect(screen.queryByLabelText(/Name/i)).toBeNull()
  })

  it('should show error toast and keep form open when WRITE_REVIEW mutation fails', async () => {
    mockMutate.mockRejectedValueOnce(new Error('Network error'))

    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={{ ...data, productId: 42 } as any} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))
    await waitFor(() => { expect(screen.getByLabelText(/Name/i)).toBeTruthy() })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'Max Muster' } })
    fireEvent.change(screen.getByLabelText(/E-Mail/i), { target: { value: 'max@example.com' } })
    const starButtons = screen.getAllByRole('button', { name: /Stern/i })
    fireEvent.click(starButtons[4]) // 5. Stern
    const textarea = screen.getByRole('textbox', { name: /Bewertungstext|Deine Bewertung/i })
    fireEvent.change(textarea, { target: { value: 'Tolles Produkt, sehr empfehlenswert!' } })
    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/Bewertung konnte nicht gespeichert werden/i)).toBeTruthy()
      // Formular bleibt offen (Name-Feld noch sichtbar)
      expect(screen.getByLabelText(/Name/i)).toBeTruthy()
    })
  })

  it('should return null when reviewsAllowed is false', () => {
    const data = buildProductReviewsResult({ reviewsAllowed: false })
    const { container } = render(<ProductReviewsBlock data={data} />)
    expect(container.firstChild).toBeNull()
  })

  it('should return null when data is null', () => {
    const { container } = render(<ProductReviewsBlock data={null} />)
    expect(container.firstChild).toBeNull()
  })
})

// ─── ProductRecommendationsBlock ─────────────────────────────────────────────

describe('ProductRecommendationsBlock', () => {
  let ProductRecommendationsBlock: React.ComponentType<{ data: unknown }>

  beforeEach(async () => {
    const mod = await import('@/components/blocks/product-recommendations-block')
    ProductRecommendationsBlock = mod.ProductRecommendationsBlock
  })

  const makeProduct = (id: string, name: string) => ({
    id,
    slug: `produkt-${id}`,
    name,
    price: '19,99\u00a0€',
    regularPrice: null,
    onSale: false,
    stockStatus: 'IN_STOCK',
    image: { sourceUrl: `/img/${id}.jpg`, altText: name },
    productCategories: { nodes: [] },
  })

  it('should render heading from params and 4 product cards', () => {
    const data = {
      heading: 'Das könnte dir gefallen',
      products: {
        nodes: [
          makeProduct('1', 'Produkt 1'),
          makeProduct('2', 'Produkt 2'),
          makeProduct('3', 'Produkt 3'),
          makeProduct('4', 'Produkt 4'),
        ],
      },
    }
    render(<ProductRecommendationsBlock data={data} />)

    expect(screen.getByText('Das könnte dir gefallen')).toBeTruthy()
    expect(screen.getByText('Produkt 1')).toBeTruthy()
    expect(screen.getByText('Produkt 4')).toBeTruthy()
  })

  it('should return null when data is null (fallback chain exhausted)', () => {
    const { container } = render(<ProductRecommendationsBlock data={null} />)
    expect(container.firstChild).toBeNull()
  })

  it('should return null when products array is empty', () => {
    const data = { heading: 'Empfehlungen', products: { nodes: [] } }
    const { container } = render(<ProductRecommendationsBlock data={data} />)
    expect(container.firstChild).toBeNull()
  })

  it('should render heading with aria-labelledby on section', () => {
    const data = {
      heading: 'Ähnliche Produkte',
      products: { nodes: [makeProduct('1', 'Test')] },
    }
    render(<ProductRecommendationsBlock data={data} />)

    const heading = screen.getByRole('heading', { name: 'Ähnliche Produkte' })
    expect(heading).toBeTruthy()
    const section = heading.closest('section')
    expect(section?.getAttribute('aria-labelledby')).toBeTruthy()
  })
})

// ─── Review Form Validierung (isoliert) ──────────────────────────────────────

describe('Review Validation Logic', () => {
  it('should validate email format correctly', async () => {
    const { validateReviewInput } = await import('@/components/reviews/review-form')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'invalid-email', rating: 4, content: 'Tolles Produkt hier' }))
      .toHaveProperty('authorEmail')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 4, content: 'Tolles Produkt hier' }))
      .not.toHaveProperty('authorEmail')
  })

  it('should require rating between 1 and 5', async () => {
    const { validateReviewInput } = await import('@/components/reviews/review-form')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 0, content: 'Tolles Produkt hier' }))
      .toHaveProperty('rating')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 6, content: 'Tolles Produkt hier' }))
      .toHaveProperty('rating')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 3, content: 'Tolles Produkt hier' }))
      .not.toHaveProperty('rating')
  })

  it('should require content of minimum 10 characters', async () => {
    const { validateReviewInput } = await import('@/components/reviews/review-form')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 4, content: 'Kurz' }))
      .toHaveProperty('content')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 4, content: 'Mindestens zehn!' }))
      .not.toHaveProperty('content')
  })
})

// ─── Data Loader: product_recommendations Fallback ───────────────────────────

const mockQuery = vi.fn()

vi.mock('@/lib/apollo/server-client', () => ({
  getClient: () => ({
    query: mockQuery,
  }),
}))

describe('woocommerceLoader product_recommendations fallback', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('should return category products as fallback when related is empty', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')

    const categoryProduct = {
      id: 'cat-prod-1',
      slug: 'kategorie-produkt-1',
      name: 'Kategorie Produkt',
      price: '19,99\u00a0€',
      regularPrice: null,
      onSale: false,
      stockStatus: 'IN_STOCK',
      image: { sourceUrl: '/img/cat1.jpg', altText: 'Kategorie Produkt' },
      productCategories: { nodes: [] },
    }

    // Aufruf 1: GET_PRODUCT_CATEGORY → gibt productId + categorySlug
    mockQuery.mockResolvedValueOnce({
      data: {
        product: {
          databaseId: 99,
          productCategories: { nodes: [{ slug: 't-shirts' }] },
        },
      },
    })
    // Aufruf 2: GET_RELATED_PRODUCTS → leer
    mockQuery.mockResolvedValueOnce({
      data: { product: { related: { nodes: [] } } },
    })
    // Aufruf 3: GET_PRODUCTS_PAGINATED (category fallback) → 4 Produkte
    mockQuery.mockResolvedValueOnce({
      data: { products: { nodes: [categoryProduct, categoryProduct, categoryProduct, categoryProduct] } },
    })

    const result = await woocommerceLoader({
      query: 'product_recommendations',
      source: 'related',
      slug: 'aktuelles-produkt',
      first: 4,
      heading: 'Das koennte dir gefallen',
    } as any)

    expect(result.data).not.toBeNull()
    expect((result.data as any).products.nodes.length).toBeGreaterThan(0)
  })

  it('should return null when related and category both return empty', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')

    // Aufruf 1: GET_PRODUCT_CATEGORY → gibt productId + categorySlug
    mockQuery.mockResolvedValueOnce({
      data: {
        product: {
          databaseId: 99,
          productCategories: { nodes: [{ slug: 't-shirts' }] },
        },
      },
    })
    // Aufruf 2: GET_RELATED_PRODUCTS → leer
    mockQuery.mockResolvedValueOnce({
      data: { product: { related: { nodes: [] } } },
    })
    // Aufruf 3: GET_PRODUCTS_PAGINATED (category fallback) → leer
    mockQuery.mockResolvedValueOnce({
      data: { products: { nodes: [] } },
    })

    const result = await woocommerceLoader({
      query: 'product_recommendations',
      source: 'related',
      slug: 'aktuelles-produkt',
      first: 4,
      heading: 'Das koennte dir gefallen',
    } as any)

    expect(result.data).toBeNull()
  })

  it('should return null when source=bestsellers and query returns empty', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')

    // Aufruf 1: GET_PRODUCT_CATEGORY
    mockQuery.mockResolvedValueOnce({
      data: {
        product: { databaseId: 99, productCategories: { nodes: [] } },
      },
    })
    // Aufruf 2: GET_BESTSELLER_PRODUCTS → leer
    mockQuery.mockResolvedValueOnce({
      data: { products: { nodes: [] } },
    })

    const result = await woocommerceLoader({
      query: 'product_recommendations',
      source: 'bestsellers',
      slug: 'aktuelles-produkt',
      first: 4,
      heading: 'Bestseller',
    } as any)

    expect(result.data).toBeNull()
  })
})

// ─── YAML product.yaml ────────────────────────────────────────────────────────

describe('product.yaml configuration', () => {
  it('should contain trust-badges, product-reviews, and product-recommendations sections', async () => {
    const fs = await import('fs')
    const path = await import('path')
    const yaml = await import('js-yaml')

    const yamlPath = path.resolve('themes/default/pages/product.yaml')
    const content = fs.readFileSync(yamlPath, 'utf-8')
    const config = yaml.load(content) as { sections: Array<{ blocks: Array<{ type: string }> }> }

    const allBlockTypes = config.sections.flatMap(s => s.blocks.map(b => b.type))
    expect(allBlockTypes).toContain('trust-badges')
    expect(allBlockTypes).toContain('product-reviews')
    expect(allBlockTypes).toContain('product-recommendations')
  })
})
```
</test_spec>

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig (10 ACs)
- [x] Security: Review-Emails werden nicht im Frontend angezeigt; WooCommerce moderiert Reviews (status: pending)
- [x] UX/Copy: Alle Texte auf Deutsch; Fehlermeldungen aus architecture.md Validierungsregeln
- [x] Privacy: Review-Autor-Email wird vom Backend nicht in der Review-List-Query exposed

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `async-parallel`: GET_PRODUCT_REVIEWS und GET_RELATED_PRODUCTS werden parallel gestartet falls nötig — aber in RSC sequenziell ist ok (separater Block je Query)
- [x] `bundle-dynamic-imports`: Review-Form kann lazy geladen werden (erst bei Klick auf "Bewertung schreiben")
- [x] `async-suspense-boundaries`: product-reviews + product-recommendations jeweils in `<Suspense>` via SectionRenderer

**High Priority:**
- [x] `server-cache-react`: `getClient()` nutzt bereits `React.cache()` (bestehend)
- [x] `rerender-memo`: ReviewCard als `memo()` — wird in großen Listen viele Male gerendert
- [x] `rerender-dependencies`: Sterne-State ist `number` (primitiv) — kein Object als dependency

**Medium Priority:**
- [x] `rerender-derived-state-no-effect`: `hasReviews` aus `reviews.edges.length > 0` direkt im Render, kein useEffect
- [x] `rendering-conditional-render`: Ternary statt `&&` bei Review-Listen-Rendering

### Web Design Guidelines Verification

**Accessibility:**
- [x] Sterne-Buttons haben `aria-label="N Stern(e)"` und `aria-pressed`
- [x] Form-Inputs haben assoziierte `<label>` mit `htmlFor`
- [x] Fehler-Spans haben `id` + `aria-describedby` auf Input
- [x] Formular-Status hat `aria-live="polite"` Container
- [x] "Bewertung schreiben"-Button hat `aria-expanded={formState !== 'hidden'}`
- [x] Sterne-Zusammenfassung: `role="img"` + `aria-label="4,2 von 5 Sternen, 47 Bewertungen"`

**Animation & Motion:**
- [x] Review-Form slide-in: `transform: translateY` + `opacity` (compositor-freundlich)
- [x] `@media (prefers-reduced-motion: reduce)` → Animation disabled

**Forms:**
- [x] Submit-Button zeigt Spinner während loading, bleibt im DOM
- [x] Fehler erscheinen inline neben Feldern (nicht als Modal/Alert)

**Touch & Mobile:**
- [x] Sterne-Buttons mindestens 44x44px touch target
- [x] `touch-action: manipulation` auf Sterne-Buttons

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Keine hardcoded Farben — nur `bg-primary`, `text-text-primary`, `border-border`, `rounded-card`
- [x] Sterne-Farbe via Theme-Token (z.B. `text-warning` oder `text-yellow-400` wenn kein Token)

**Responsive:**
- [x] Recommendations Grid: `grid-cols-2 lg:grid-cols-4` (Mobile-first)
- [x] Review-Form: `max-w-lg` auf Desktop, fullwidth auf Mobile

---

## Constraints & Hinweise

**Betrifft:**
- `lib/blocks/types.ts` — `WooCommerceLoaderParams.query` muss um `'product_reviews' | 'product_recommendations'` erweitert werden
- `lib/graphql/mutations.ts` — Neue Datei, muss angelegt werden (existiert noch nicht)
- `lib/blocks/registry.ts` — Zwei neue Einträge am Ende des bestehenden Registry-Maps

**API Contract:**
- WooGraphQL `writeReview` Mutation: `commentOn: Int!` (Produkt-Database-ID, NICHT der Slug)
- `rating` auf dem Review-Edge (NICHT auf dem review-Node) — WooGraphQL-Quirk, in `ProductReviewsResult` entsprechend mappen
- Review `author.node.name` — geschachtelter Node, nicht direkt auf Edge (WooGraphQL-Pattern)

**Abgrenzung:**
- Dieser Slice implementiert NICHT: search-results, pagination, sort-bar (Slice 3+5)
- Dieser Slice implementiert NICHT: Backend-Integration des Newsletter-Signups (Out of Scope)
- `trust-badges` Block selbst ist in Slice 1 implementiert — dieser Slice trägt ihn nur in product.yaml ein

---

## Integration Contract (GATE 2 PFLICHT)

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-01-cross-page-infrastruktur` | `TrustBadgesBlock` | Component | In `lib/blocks/registry.ts` unter Key `'trust-badges'` registriert |
| `slice-01-cross-page-infrastruktur` | `TrustBadgeData` Interface | Type | In `lib/blocks/types.ts` definiert |
| `slice-01-cross-page-infrastruktur` | `registry.ts` Erweiterung | File | Alle Slice-1-Blocks registriert, damit registry.ts-Pattern stabil ist |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `ProductReviewsBlock` | Component | `lib/blocks/registry.ts` (in diesem Slice registriert) | `BlockComponentProps<ProductReviewsResult \| null>` |
| `ProductRecommendationsBlock` | Component | `lib/blocks/registry.ts` (in diesem Slice registriert) | `BlockComponentProps<ProductRecommendationsData \| null>` |
| `ProductReviewsResult` | Type | `lib/blocks/data-loaders.ts` (product_reviews branch) | `{ averageRating: number, reviewCount: number, reviewsAllowed: boolean, reviews: { edges: ReviewEdge[] }, productId: number }` |
| `ReviewEdge` | Type | `ProductReviewsBlock` | `{ rating: number, node: { id: string, content: string, date: string, author: { node: { name: string } } } }` |
| `WriteReviewInput` | Type | `components/reviews/review-form.tsx` | `{ productId: number, content: string, author: string, authorEmail: string, rating: number }` |
| `validateReviewInput()` | Function | Test + review-form.tsx | `(input: Omit<WriteReviewInput, 'productId'>) => Partial<Record<keyof WriteReviewInput, string>>` |
| `WRITE_REVIEW` | GraphQL Mutation | `lib/graphql/mutations.ts` | Review-Mutation string (gql tag) |
| `GET_PRODUCT_REVIEWS` | GraphQL Query | `lib/graphql/queries.ts` | Query string für product-reviews data-loader branch |
| `GET_RELATED_PRODUCTS` | GraphQL Query | `lib/graphql/queries.ts` | Query string für product-recommendations source=related |
| `GET_BESTSELLER_PRODUCTS` | GraphQL Query | `lib/graphql/queries.ts` | Query string für product-recommendations source=bestsellers |
| `GET_PRODUCTS_BY_IDS` | GraphQL Query | `lib/graphql/queries.ts` | Query string für product-recommendations source=custom |
| `product.yaml` (erweitert) | YAML Config | `app/produkt/[slug]/page.tsx` (via loadPageConfig) | trust-badges + product-reviews + product-recommendations Sections vorhanden |

### Integration Validation Tasks

- [x] `TrustBadgesBlock` aus Slice 1 in `product.yaml` konfiguriert und via SectionRenderer renderbar
- [x] `product-reviews` + `product-recommendations` in `lib/blocks/registry.ts` registriert
- [x] `WooCommerceLoaderParams.query` Union Type erweitert (backwards-kompatibel)
- [x] `lib/graphql/mutations.ts` Datei existiert und exportiert `WRITE_REVIEW`

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind PFLICHT-Deliverables.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.
> Abweichung nur mit expliziter Begründung im Commit erlaubt.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `ProductReviewsBlock` | Code Example 1 | YES | Client Component, reviewsAllowed Guard, form state machine |
| `ProductRecommendationsBlock` | Code Example 2 | YES | Server Component, null guard, heading + grid |
| `validateReviewInput()` | Code Example 3 | YES | Export aus review-form.tsx, verwendbar in Tests |
| `WRITE_REVIEW` Mutation | Code Example 4 | YES | In lib/graphql/mutations.ts |
| `ProductReviewsResult` Type | Code Example 5 | YES | In lib/blocks/types.ts |
| `product_recommendations` Loader Branch | Code Example 6 | YES | In lib/blocks/data-loaders.ts; verwendet GET_PRODUCT_CATEGORY |
| `GET_PRODUCT_CATEGORY` Query | Technische Umsetzung §4 | YES | Neue schlanke Query in lib/graphql/queries.ts |
| `product.yaml` (erweitert) | Technische Umsetzung §6 | YES | Alle 3 neuen Sections vorhanden |

### Code Example 1: ProductReviewsBlock (Skelett)

```typescript
// frontend/components/blocks/product-reviews-block.tsx
'use client'

import { useState } from 'react'
import { StarRatingDisplay } from '@/components/reviews/star-rating-display'
import { ReviewCard } from '@/components/reviews/review-card'
import { ReviewForm, validateReviewInput } from '@/components/reviews/review-form'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductReviewsResult } from '@/lib/blocks/types'

export { validateReviewInput }

type ReviewFormState = 'hidden' | 'open' | 'loading' | 'success' | 'error'

export function ProductReviewsBlock({ data }: BlockComponentProps<ProductReviewsResult | null>) {
  const [formState, setFormState] = useState<ReviewFormState>('hidden')

  // Guard: keine Reviews erlaubt → Block unsichtbar
  if (!data || !data.reviewsAllowed) return null

  const { averageRating, reviewCount, reviews, productId } = data

  return (
    <section aria-labelledby="reviews-heading">
      <h2 id="reviews-heading" className="text-2xl font-semibold tracking-tight mb-6">
        Kundenbewertungen
      </h2>

      {/* Sterne-Zusammenfassung */}
      <div
        role="img"
        aria-label={`${averageRating.toFixed(1).replace('.', ',')} von 5 Sternen, ${reviewCount} Bewertungen`}
        className="flex items-center gap-3 mb-6"
      >
        <StarRatingDisplay rating={averageRating} />
        <span className="text-lg font-medium">{averageRating.toFixed(1).replace('.', ',')}</span>
        <span className="text-text-secondary">· {reviewCount} Bewertungen</span>
      </div>

      {/* Review-Liste */}
      {reviews.edges.length > 0 ? (
        <ul className="divide-y divide-border mb-8" aria-label="Bewertungsliste">
          {reviews.edges.map((edge) => (
            <li key={edge.node.id}>
              <ReviewCard edge={edge} />
            </li>
          ))}
        </ul>
      ) : null}

      {/* Review-Form Toggle */}
      <div aria-live="polite" aria-atomic="true">
        {formState === 'success' ? (
          <p className="text-success font-medium">
            Danke für deine Bewertung! Sie wird nach Prüfung angezeigt.
          </p>
        ) : (
          <>
            {formState === 'hidden' ? (
              <button
                type="button"
                aria-expanded={false}
                onClick={() => setFormState('open')}
                className="btn-secondary touch-manipulation"
              >
                Bewertung schreiben
              </button>
            ) : (
              <ReviewForm
                productId={productId}
                formState={formState}
                onStateChange={setFormState}
                onCancel={() => setFormState('hidden')}
              />
            )}
          </>
        )}
      </div>
    </section>
  )
}

export function ProductReviewsBlockSkeleton() {
  return (
    <section aria-labelledby="reviews-heading-loading">
      <div className="h-8 w-48 bg-surface-sunken rounded animate-pulse mb-6" />
      <div className="h-6 w-64 bg-surface-sunken rounded animate-pulse mb-6" />
      {[0, 1].map((i) => (
        <div key={i} className="py-4 space-y-2 border-b border-border">
          <div className="h-4 w-32 bg-surface-sunken rounded animate-pulse" />
          <div className="h-4 w-full bg-surface-sunken rounded animate-pulse" />
        </div>
      ))}
    </section>
  )
}
```

### Code Example 2: ProductRecommendationsBlock

```typescript
// frontend/components/blocks/product-recommendations-block.tsx
import { ProductCard } from '@/components/product/product-card'
import { ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductCardData } from '@/lib/graphql/types'

interface ProductRecommendationsData {
  heading: string
  products: { nodes: ProductCardData[] } | null
}

export function ProductRecommendationsBlock({ data }: BlockComponentProps<ProductRecommendationsData | null>) {
  const products = data?.products?.nodes ?? []

  // Guard: kein Heading ohne Inhalt, kein leerer Container
  if (!data || products.length === 0) return null

  const headingId = 'recommendations-heading'

  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} className="text-2xl font-semibold tracking-tight mb-6">
        {data.heading}
      </h2>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} />
        ))}
      </div>
    </section>
  )
}

export { ProductGridBlockSkeleton as ProductRecommendationsBlockSkeleton }
```

### Code Example 3: validateReviewInput()

```typescript
// Exportiert aus: frontend/components/reviews/review-form.tsx

export interface ReviewFormInput {
  author: string
  authorEmail: string
  rating: number
  content: string
}

export type ReviewValidationErrors = Partial<Record<keyof ReviewFormInput, string>>

export function validateReviewInput(input: ReviewFormInput): ReviewValidationErrors {
  const errors: ReviewValidationErrors = {}

  if (!input.author || input.author.trim().length < 2) {
    errors.author = 'Bitte gib deinen Namen ein (min. 2 Zeichen)'
  }

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!input.authorEmail || !emailRegex.test(input.authorEmail.trim())) {
    errors.authorEmail = 'Bitte gib eine gültige E-Mail-Adresse ein'
  }

  if (!input.rating || input.rating < 1 || input.rating > 5 || !Number.isInteger(input.rating)) {
    errors.rating = 'Bitte wähle eine Bewertung (1–5 Sterne)'
  }

  if (!input.content || input.content.trim().length < 10) {
    errors.content = 'Bitte schreibe mindestens 10 Zeichen'
  }

  return errors
}
```

### Code Example 4: WRITE_REVIEW Mutation

```typescript
// frontend/lib/graphql/mutations.ts
import { gql } from '@apollo/client'

export const WRITE_REVIEW = gql`
  mutation WriteReview(
    $productId: Int!
    $content: String!
    $author: String!
    $authorEmail: String!
    $rating: Int!
  ) {
    writeReview(
      input: {
        commentOn: $productId
        content: $content
        author: $author
        authorEmail: $authorEmail
        rating: $rating
      }
    ) {
      rating
      review {
        id
        content
        date
      }
    }
  }
`
```

### Code Example 5: ProductReviewsResult Type (in types.ts)

```typescript
// Ergänzungen in frontend/lib/blocks/types.ts

export interface ReviewEdge {
  rating: number  // WICHTIG: rating ist auf dem Edge, NICHT auf dem Node (WooGraphQL-Quirk)
  node: {
    id: string
    content: string
    date: string
    author: {
      node: {
        name: string
      }
    }
  }
}

export interface ProductReviewsResult {
  averageRating: number
  reviewCount: number
  reviewsAllowed: boolean
  reviews: {
    edges: ReviewEdge[]
  }
  productId: number  // WooCommerce Database ID (für writeReview commentOn: Int!)
}

export interface WriteReviewInput {
  productId: number
  content: string
  author: string
  authorEmail: string
  rating: number  // Integer 1-5
}

export interface ProductRecommendationsData {
  heading: string
  products: { nodes: ProductCardData[] } | null
}

// WooCommerceLoaderParams Erweiterung (Union erweitern)
// query-Feld erhält zusätzliche Werte:
// 'product_reviews' | 'product_recommendations'
// (bestehende Werte bleiben erhalten — backwards-kompatibel)
```

### Code Example 6: data-loaders.ts — neue Branches

```typescript
// Neue Branches in frontend/lib/blocks/data-loaders.ts (innerhalb woocommerceLoader)

// ─── Branch: product_reviews ─────────────────────────────────────────────────
if (params.query === 'product_reviews') {
  const { data } = await getClient().query({
    query: GET_PRODUCT_REVIEWS,
    variables: { productSlug: params.slug },
  })

  if (!data?.product) return { data: null }

  const product = data.product
  return {
    data: {
      averageRating: product.averageRating ?? 0,
      reviewCount: product.reviewCount ?? 0,
      reviewsAllowed: product.reviewsAllowed ?? false,
      reviews: { edges: product.reviews?.edges ?? [] },
      productId: product.databaseId,  // benötigt für WRITE_REVIEW Mutation
    } satisfies ProductReviewsResult,
  }
}

// ─── Branch: product_recommendations ─────────────────────────────────────────
if (params.query === 'product_recommendations') {
  const source = (params as any).source ?? 'related'
  const first = (params as any).first ?? 4
  const productSlug = params.slug

  // Produktdaten für productId + categorySlug laden (schlanke Query, kein Full-Detail-Fragment)
  const { data: productData } = await getClient().query({
    query: GET_PRODUCT_CATEGORY,
    variables: { slug: productSlug },
  })
  const productId = productData?.product?.databaseId
  const categorySlug = productData?.product?.productCategories?.nodes?.[0]?.slug

  let nodes: ProductCardData[] = []

  if (source === 'related' && productId) {
    const { data: relData } = await getClient().query({
      query: GET_RELATED_PRODUCTS,
      variables: { productId: String(productId), first },
    })
    nodes = relData?.product?.related?.nodes ?? []

    // Fallback: category
    if (nodes.length === 0 && categorySlug) {
      const { data: catData } = await getClient().query({
        query: GET_PRODUCTS_PAGINATED,
        variables: { first: first + 1, categorySlug },
      })
      nodes = (catData?.products?.nodes ?? []).filter((p: ProductCardData) => p.slug !== productSlug).slice(0, first)
    }
  } else if (source === 'category' && categorySlug) {
    const { data: catData } = await getClient().query({
      query: GET_PRODUCTS_PAGINATED,
      variables: { first: first + 1, categorySlug },
    })
    nodes = (catData?.products?.nodes ?? []).filter((p: ProductCardData) => p.slug !== productSlug).slice(0, first)
  } else if (source === 'bestsellers') {
    const { data: bsData } = await getClient().query({
      query: GET_BESTSELLER_PRODUCTS,
      variables: { first },
    })
    nodes = bsData?.products?.nodes ?? []
  } else if (source === 'custom' && (params as any).customIds) {
    const ids = String((params as any).customIds).split(',').map(Number).filter(Boolean)
    const { data: customData } = await getClient().query({
      query: GET_PRODUCTS_BY_IDS,
      variables: { include: ids, first },
    })
    nodes = customData?.products?.nodes ?? []
  }

  if (nodes.length === 0) return { data: null }  // Block unsichtbar

  const heading = (params as any).heading ?? 'Das könnte dir auch gefallen'
  return {
    data: {
      heading,
      products: { nodes },
    } satisfies ProductRecommendationsData,
  }
}
```

---

## Links

- Design/Spec: `specs/phase-1/2026-02-25-shop-completeness/discovery.md` → UI Layout & Context → Screen: Produktdetail-Page (erweitert)
- Architecture: `specs/phase-1/2026-02-25-shop-completeness/architecture.md` → Block Inventory (#7, #8) + GraphQL Queries
- Vorheriger Slice: `specs/phase-1/2026-02-25-shop-completeness/slices/slice-01-cross-page-infrastruktur.md`
- Bestehende Referenz: `frontend/components/blocks/product-grid-block.tsx` (Skeleton-Pattern)
- Bestehende Referenz: `frontend/lib/blocks/data-loaders.ts` (woocommerceLoader Pattern)
- Bestehende Referenz: `frontend/lib/blocks/registry.ts` (Registry-Pattern)

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Neue Dateien

- [ ] `frontend/lib/graphql/mutations.ts` — GraphQL Mutation `WRITE_REVIEW` (neue Datei)
- [ ] `frontend/components/blocks/product-reviews-block.tsx` — Client Component: StarSummary + ReviewList + ReviewForm-State-Machine + Skeleton-Export
- [ ] `frontend/components/blocks/product-recommendations-block.tsx` — Server Component: Heading + ProductCard Grid + null-Guard + Skeleton-Re-Export
- [ ] `frontend/components/reviews/star-rating-display.tsx` — Read-only Sterne-Anzeige (0.5-Schritte, aria-label)
- [ ] `frontend/components/reviews/star-rating-input.tsx` — Interaktive Sterne-Auswahl (1-5, aria-label pro Stern, aria-pressed)
- [ ] `frontend/components/reviews/review-card.tsx` — Einzelne Review-Karte (Sterne + Autor + Datum + Text)
- [ ] `frontend/components/reviews/review-form.tsx` — Bewertungsformular (Felder + Validierung + Mutation + States) inkl. `validateReviewInput()` Export

### Geänderte Dateien

- [ ] `frontend/lib/blocks/types.ts` — Neue Types: `ReviewEdge`, `ProductReviewsResult`, `WriteReviewInput`, `ProductRecommendationsData`; `WooCommerceLoaderParams.query` Union erweitern
- [ ] `frontend/lib/graphql/queries.ts` — Neue Queries: `GET_PRODUCT_REVIEWS`, `GET_RELATED_PRODUCTS`, `GET_BESTSELLER_PRODUCTS`, `GET_PRODUCTS_BY_IDS`, `GET_PRODUCT_CATEGORY`
- [ ] `frontend/lib/blocks/data-loaders.ts` — Neue Branches: `product_reviews`, `product_recommendations` (inkl. Fallback-Logik); neue Query-Imports
- [ ] `frontend/lib/blocks/registry.ts` — Neue Einträge: `'product-reviews'` → `ProductReviewsBlock`, `'product-recommendations'` → `ProductRecommendationsBlock`
- [ ] `frontend/themes/default/pages/product.yaml` — 3 neue Sections: `trust-badges` (inline), `product-reviews` (woocommerce), `product-recommendations` (woocommerce)

### Tests

- [ ] `tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts` — Vitest Tests (alle oben definierten `describe`/`it` Blöcke)
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- Der Stop-Hook prüft automatisch ob alle Dateien existieren
- Bei fehlenden Dateien wird der Agent blockiert und muss nachfragen
