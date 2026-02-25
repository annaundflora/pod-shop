# Integration Map: Block-basierte Seiten-Migration

**Generated:** 2026-02-25
**Slices:** 4
**Connections:** 18

---

## Dependency Graph (Visual)

```
┌──────────────────────────────┐
│  Slice 01                    │
│  Block-System Upgrade        │
│  (Foundation)                │
└──────────────────────────────┘
         │           │          │
         ▼           ▼          ▼
┌──────────────┐ ┌────────────────┐ ┌──────────────────┐
│  Slice 02    │ │   Slice 03     │ │    Slice 04       │
│  Kategorie-  │ │   Produkt-     │ │    Rechtsseiten   │
│  Seite       │ │   Seite        │ │    als Block-Pages│
└──────────────┘ └────────────────┘ └──────────────────┘
         ^                                    ^
         │                                    │
         └── Slice 04 depends on Slice 02 ────┘
             (PageHeadingBlock)
```

---

## Nodes

### Slice 01: Block-System Upgrade

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | None |
| Test Command | `cd frontend && pnpm test tests/slices/block-page-migration/slice-01-block-system-upgrade.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| None | — | No external dependencies |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `SectionConfig` Interface | TypeScript Interface | Slice 02, 03, 04 |
| `BlockConfig.span` + `row_span` | TypeScript Interface fields | Slice 02, 03, 04 |
| `PageConfig.sections[]` | TypeScript Interface | Slice 02, 03, 04 |
| `WooCommerceLoaderParams.query` Union | TypeScript Union Type | Slice 02, 03 |
| `WordPressLoaderParams.query` Union | TypeScript Union Type | Slice 04 |
| `WPPageContent` Interface | TypeScript Interface | Slice 04 |
| `CategoryWithProducts` Interface | TypeScript Interface | Slice 02 |
| `resolveParams()` | Function | Slice 02, 03, 04 (via loadPageConfig) |
| `loadPageConfig()` | Function | Slice 02, 03, 04 |
| `SectionRenderer` | React Server Component | Slice 02, 03, 04 |
| `SectionLayout` | React Component | `SectionRenderer` (internal) |
| `frontend/lib/blocks/types.ts` | Modified file | Downstream consumers |
| `frontend/lib/blocks/page-config.ts` | Modified file | Downstream consumers |
| `frontend/lib/blocks/section-renderer.tsx` | New file | Slice 02, 03, 04 |
| `frontend/lib/blocks/section-layout.tsx` | New file | `SectionRenderer` |
| `frontend/themes/default/pages/home.yaml` | Modified YAML | Homepage rendering |
| `frontend/app/page.tsx` | Modified page | Homepage (existing route) |

---

### Slice 02: Kategorie-Seite als Block-Page

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | slice-01-block-system-upgrade |
| Test Command | `cd frontend && pnpm test tests/slices/block-page-migration/slice-02-kategorie-seite.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `SectionConfig` Interface | Slice 01 | APPROVED |
| `PageConfig.sections[]` | Slice 01 | APPROVED |
| `loadPageConfig()` (new signature) | Slice 01 | APPROVED |
| `resolveParams()` | Slice 01 | APPROVED |
| `SectionRenderer` | Slice 01 | APPROVED |
| `WooCommerceLoaderParams.query` Union (incl. `products_by_category`) | Slice 01 | APPROVED |
| `CategoryWithProducts` Interface | Slice 01 | APPROVED |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `PageHeadingBlock` + `PageHeadingBlockSkeleton` | React Server Component | Slice 04 |
| `GET_CATEGORY_WITH_PRODUCTS` GraphQL Query | GraphQL Query | `data-loaders.ts` (internal) |
| `products_by_category` DataLoader Handler | Data-Loader Case | category.yaml via SectionRenderer |
| `product_categories` Handler (extended with `currentSlug`) | Data-Loader Case | filter-chips Block |
| `page-heading` Block-Type | Registry Entry | category.yaml, legal.yaml (Slice 04) |
| `filter-chips` Block-Type | Registry Entry | category.yaml |
| `product-count` Block-Type | Registry Entry | category.yaml |
| `frontend/components/blocks/page-heading-block.tsx` | New file | Slice 04 imports |
| `frontend/components/blocks/filter-chips-block.tsx` | New file | category.yaml rendering |
| `frontend/components/blocks/product-count-block.tsx` | New file | category.yaml rendering |
| `frontend/themes/default/pages/category.yaml` | New YAML | `loadPageConfig('category', ...)` |
| `frontend/app/kategorie/[slug]/page.tsx` | Modified page | Browser (existing route) |

---

### Slice 03: Produkt-Seite als Block-Page

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | slice-01-block-system-upgrade |
| Test Command | `cd frontend && pnpm test tests/slices/block-page-migration/slice-03-produkt-seite.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `SectionConfig` Interface | Slice 01 | APPROVED |
| `PageConfig.sections[]` | Slice 01 | APPROVED |
| `WooCommerceLoaderParams.query` Union (incl. `product_by_slug`) | Slice 01 | APPROVED |
| `loadPageConfig()` | Slice 01 | APPROVED |
| `SectionRenderer` | Slice 01 | APPROVED |
| `BlockComponentProps<T>` Interface | Slice 01 | APPROVED |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `product-gallery` Block-Type | Registry Entry | product.yaml via SectionRenderer |
| `product-purchase` Block-Type | Registry Entry | product.yaml via SectionRenderer |
| `product-description` Block-Type | Registry Entry | product.yaml via SectionRenderer |
| `product_by_slug` DataLoader Handler | Data-Loader Case | product.yaml blocks |
| `frontend/themes/default/pages/product.yaml` | New YAML | `loadPageConfig('product', ...)` |
| `frontend/components/blocks/product-gallery-block.tsx` | New file | product.yaml rendering |
| `frontend/components/blocks/product-purchase-block.tsx` | New file | product.yaml rendering |
| `frontend/components/blocks/product-description-block.tsx` | New file | product.yaml rendering |
| `frontend/app/produkt/[slug]/page.tsx` | Modified page | Browser (existing route) |

---

### Slice 04: Rechtsseiten als Block-Pages

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | slice-01-block-system-upgrade, slice-02-kategorie-seite |
| Test Command | `cd frontend && pnpm test tests/slices/block-page-migration/slice-04-rechtsseiten.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `SectionRenderer` | Slice 01 | APPROVED |
| `loadPageConfig()` | Slice 01 | APPROVED |
| `resolveParams()` | Slice 01 | APPROVED |
| `WordPressLoaderParams.query` Union (incl. `page_content`) | Slice 01 | APPROVED |
| `WPPageContent` Interface | Slice 01 | APPROVED |
| `PageHeadingBlock` + `PageHeadingBlockSkeleton` | Slice 02 | APPROVED |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `LegalContentBlock` | React Server Component | `lib/blocks/registry.ts` |
| `LegalContentBlockSkeleton` | React Component | Route-Pages Skeleton-Map |
| `/impressum`, `/agb`, `/datenschutz`, `/widerruf` Routes | Next.js Routes | Footer-Links, Browser |
| `frontend/lib/graphql/queries.ts` | Modified file (GET_PAGE_CONTENT added) | `wordpressLoader` |
| `frontend/components/blocks/legal-content-block.tsx` | New file | legal.yaml rendering |
| `frontend/themes/default/pages/legal.yaml` | New YAML | `loadPageConfig('legal', ...)` |
| `frontend/app/impressum/page.tsx` | New page | Browser |
| `frontend/app/agb/page.tsx` | New page | Browser |
| `frontend/app/datenschutz/page.tsx` | New page | Browser |
| `frontend/app/widerruf/page.tsx` | New page | Browser |
| `frontend/lib/blocks/data-loaders.ts` | Modified file (page_content branch) | SectionRenderer |
| `frontend/lib/blocks/registry.ts` | Modified file (legal-content added) | SectionRenderer |
| `frontend/components/layout/footer.tsx` | Modified file (internal links) | Browser |

---

## Connections

| # | From | To | Resource | Type | Status |
|---|------|-----|----------|------|--------|
| 1 | Slice 01 | Slice 02 | `SectionConfig` Interface | TypeScript Interface | VALID |
| 2 | Slice 01 | Slice 02 | `PageConfig.sections[]` | TypeScript Interface | VALID |
| 3 | Slice 01 | Slice 02 | `loadPageConfig()` | Function | VALID |
| 4 | Slice 01 | Slice 02 | `resolveParams()` | Function | VALID |
| 5 | Slice 01 | Slice 02 | `SectionRenderer` | React Component | VALID |
| 6 | Slice 01 | Slice 02 | `WooCommerceLoaderParams.query` (products_by_category) | Union Type | VALID |
| 7 | Slice 01 | Slice 02 | `CategoryWithProducts` Interface | TypeScript Interface | VALID |
| 8 | Slice 01 | Slice 03 | `SectionConfig` Interface | TypeScript Interface | VALID |
| 9 | Slice 01 | Slice 03 | `loadPageConfig()` | Function | VALID |
| 10 | Slice 01 | Slice 03 | `SectionRenderer` | React Component | VALID |
| 11 | Slice 01 | Slice 03 | `WooCommerceLoaderParams.query` (product_by_slug) | Union Type | VALID |
| 12 | Slice 01 | Slice 03 | `BlockComponentProps<T>` Interface | TypeScript Interface | VALID |
| 13 | Slice 01 | Slice 04 | `SectionRenderer` | React Component | VALID |
| 14 | Slice 01 | Slice 04 | `loadPageConfig()` | Function | VALID |
| 15 | Slice 01 | Slice 04 | `resolveParams()` | Function | VALID |
| 16 | Slice 01 | Slice 04 | `WordPressLoaderParams.query` (page_content) | Union Type | VALID |
| 17 | Slice 01 | Slice 04 | `WPPageContent` Interface | TypeScript Interface | VALID |
| 18 | Slice 02 | Slice 04 | `PageHeadingBlock` + `PageHeadingBlockSkeleton` | React Component | VALID |

---

## Validation Results

### Valid Connections: 18

All declared dependencies have matching outputs. Every input declared in slices 02, 03, and 04 is provided by an earlier approved slice.

### Orphaned Outputs: 0

All outputs are consumed either by downstream slices or by the application itself (browser-facing routes, SectionRenderer internals).

| Output | Defined In | Consumers | Action |
|--------|------------|-----------|--------|
| `SectionLayout` | Slice 01 | `SectionRenderer` (internal) | Final — used as internal layout primitive |
| `filter-chips` Block-Type | Slice 02 | `category.yaml` blocks | Final — rendered via SectionRenderer |
| `product-count` Block-Type | Slice 02 | `category.yaml` blocks | Final — rendered via SectionRenderer |
| `product-gallery` Block-Type | Slice 03 | `product.yaml` blocks | Final — rendered via SectionRenderer |
| `product-purchase` Block-Type | Slice 03 | `product.yaml` blocks | Final — rendered via SectionRenderer |
| `product-description` Block-Type | Slice 03 | `product.yaml` blocks | Final — rendered via SectionRenderer |
| `LegalContentBlock` | Slice 04 | `legal.yaml` + `registry.ts` | Final — rendered via SectionRenderer |

All outputs serve a clear final purpose. No unresolved orphans.

### Missing Inputs: 0

All inputs have matching producers in approved slices. No gaps detected.

### Deliverable-Consumer Gaps: 0

All components with mount points are properly traced:

| Component | Defined In | Consumer Page | Page In Deliverables? | Status |
|-----------|------------|---------------|-----------------------|--------|
| `SectionRenderer` | Slice 01 | `frontend/app/page.tsx` | Yes — Slice 01 Deliverables | VALID |
| `SectionRenderer` | Slice 01 | `frontend/app/kategorie/[slug]/page.tsx` | Yes — Slice 02 Deliverables | VALID |
| `SectionRenderer` | Slice 01 | `frontend/app/produkt/[slug]/page.tsx` | Yes — Slice 03 Deliverables | VALID |
| `SectionRenderer` | Slice 01 | `frontend/app/impressum/page.tsx` etc. | Yes — Slice 04 Deliverables | VALID |
| `PageHeadingBlock` | Slice 02 | `frontend/themes/default/pages/legal.yaml` (via SectionRenderer) | Yes — Slice 04 Deliverables | VALID |
| `LegalContentBlock` | Slice 04 | `frontend/lib/blocks/registry.ts` | Yes — Slice 04 Modified Files | VALID |
| Footer internal links | Slice 04 | `frontend/components/layout/footer.tsx` | Yes — Slice 04 Modified Files | VALID |

---

## Discovery Traceability

### UI Components Coverage

| Discovery Element | Type | Location | Covered In | Status |
|-------------------|------|----------|------------|--------|
| `SectionRenderer` (loading, loaded, error) | Server Component | `lib/blocks/section-renderer.tsx` | Slice 01 | COVERED |
| `SectionLayout` (columns, span, row-span) | Server Component | `lib/blocks/section-layout.tsx` | Slice 01 | COVERED |
| `PageHeadingBlock` (with-subtitle, without-subtitle) | Server Component | `components/blocks/page-heading-block.tsx` | Slice 02 | COVERED |
| `FilterChipsBlock` (loading, loaded) | Server Component | `components/blocks/filter-chips-block.tsx` | Slice 02 | COVERED |
| `ProductCountBlock` (loading, loaded) | Server Component | `components/blocks/product-count-block.tsx` | Slice 02 | COVERED |
| `ProductGalleryBlock` (loading, loaded, no-images) | Client-Island | `components/blocks/product-gallery-block.tsx` | Slice 03 | COVERED |
| `ProductPurchaseBlock` (loading, loaded, no-variants) | Client-Island | `components/blocks/product-purchase-block.tsx` | Slice 03 | COVERED |
| `ProductDescriptionBlock` (loading, has-description, no-description) | Server Component | `components/blocks/product-description-block.tsx` | Slice 03 | COVERED |
| `LegalContentBlock` (loading, loaded, not-found) | Server Component | `components/blocks/legal-content-block.tsx` | Slice 04 | COVERED |

### State Machine Coverage

Not applicable — discovery.md explicitly states: "Nicht anwendbar — kein interaktiver Feature-State. Das Feature ist eine Architektur-Migration."

### Transitions Coverage

Not applicable — no feature state machine defined. Architecture migration only.

### Business Rules Coverage

| Rule | Covered In | Status |
|------|------------|--------|
| Template-Override-Reihenfolge: slug-spezifisch → theme-generisch → default | Slice 01 (`loadPageConfig()` 3-tier Lookup) | COVERED |
| Sections ohne `columns:` verwenden `columns: 1` als Default (fullwidth) | Slice 01 (`SectionLayout` + `SectionRenderer`) | COVERED |
| Blocks koennen optional `span: N` und `row-span: N` definieren (default: jeweils 1) | Slice 01 (`BlockConfig.span` + `row_span`) | COVERED |
| Alle Sections auf Mobile zu `columns: 1` (Stack) | Slice 01 (`SectionLayout` `grid-cols-1` base class) | COVERED |
| `$route.slug` Platzhalter werden vor dem Data-Loading aufgeloest | Slice 01 (`resolveParams()` in `loadPageConfig()`) | COVERED |
| `generateStaticParams` und `generateMetadata` bleiben in page.tsx | Slice 02, 03, 04 (all page.tsx files retain these) | COVERED |
| JSON-LD Schema-Markup bleibt in page.tsx | Slice 03 (`app/produkt/[slug]/page.tsx`) | COVERED |
| Pinterest-Tracking Side-Effects bleiben in page.tsx | Slice 02 (`CategoryPageClient` retained) | COVERED |
| Warenkorb-Seite ist explizit ausgeschlossen | Slice 01-04 (none touch /warenkorb) | COVERED |
| Rechtsseiten verwenden Platzhalter-Content (echte Texte in P0.5) | Slice 04 (Constraints-Abschnitt) | COVERED |
| Alle Farben/Radii/Shadows muessen Theme-Tokens verwenden | Slice 01-04 (all components use theme tokens) | COVERED |
| `app/generated-theme.css` wird nie manuell editiert | All slices — not touched | COVERED |

### Data Fields Coverage

| Field / Query | Required | Covered In | Status |
|---------------|----------|------------|--------|
| `products_by_category` query handler | Yes | Slice 02 (`data-loaders.ts` case) | COVERED |
| `products_by_category` variables: `slug`, `first` | Yes | Slice 02 | COVERED |
| `products_by_category` returns `products.nodes: ProductCardData[]` | Yes | Slice 02 (`CategoryWithProducts.products.nodes`) | COVERED |
| `products_by_category` returns `productCategory: { name, description, slug }` | Yes | Slice 02 (`CategoryWithProducts.productCategory`) | NOTE: discovery uses `category` field name, architecture and slices correctly use `productCategory` per WPGraphQL |
| `product_by_slug` query handler | Yes | Slice 03 (`data-loaders.ts` handler) | COVERED |
| `product_by_slug` variables: `slug` | Yes | Slice 03 | COVERED |
| `product_by_slug` returns `ProductDetailData` | Yes | Slice 03 | COVERED |
| `page_content` WordPress query | Yes | Slice 04 (`wordpressLoader` + `GET_PAGE_CONTENT`) | COVERED |
| `page_content` variables: `slug` | Yes | Slice 04 | COVERED |
| `page_content` returns `{ title, content }` as `WPPageContent` | Yes | Slice 04 (NOTE: discovery uses `page.{title,content}`, architecture/slices correctly use `pageBy.{title,content}` per WPGraphQL) | COVERED |
| `dynamic` content-source | Listed in discovery | NOT IMPLEMENTED — architecture.md explicitly excludes it: "No dynamic content source needed" | INTENTIONALLY OUT OF SCOPE |

**Discovery Coverage:** 100% of in-scope items covered. The `dynamic` content-source and `category` vs `productCategory` field name discrepancies are documented as discovery-vs-architecture inconsistencies, resolved in favor of architecture.md (the authoritative source).

---

## Summary

| Metric | Value |
|--------|-------|
| Total Slices | 4 |
| All Slices APPROVED | Yes |
| Total Connections | 18 |
| Valid Connections | 18 |
| Orphaned Outputs | 0 |
| Missing Inputs | 0 |
| Deliverable-Consumer Gaps | 0 |
| Discovery Business Rules Covered | 12/12 |
| Discovery UI Components Covered | 9/9 |
| Discovery Data Fields Covered | 10/10 (1 intentionally out of scope) |

---

VERDICT: READY FOR ORCHESTRATION
