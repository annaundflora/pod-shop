# Orchestrator Configuration: Block-basierte Seiten-Migration

**Integration Map:** `integration-map.md`
**E2E Checklist:** `e2e-checklist.md`
**Generated:** 2026-02-25

---

## Pre-Implementation Gates

```yaml
pre_checks:
  - name: "Gate 1: Architecture Compliance"
    file: "specs/phase-0/2026-02-25-block-page-migration/compliance-architecture.md"
    required: "Verdict == APPROVED"

  - name: "Gate 2: All Slices Approved"
    files:
      - "specs/phase-0/2026-02-25-block-page-migration/slices/compliance-slice-01.md"
      - "specs/phase-0/2026-02-25-block-page-migration/slices/compliance-slice-02.md"
      - "specs/phase-0/2026-02-25-block-page-migration/slices/compliance-slice-03.md"
      - "specs/phase-0/2026-02-25-block-page-migration/slices/compliance-slice-04.md"
    required: "ALL Verdict == APPROVED"

  - name: "Gate 3: Integration Map Valid"
    file: "specs/phase-0/2026-02-25-block-page-migration/integration-map.md"
    required: "VERDICT: READY FOR ORCHESTRATION"
    checks:
      - "Missing Inputs == 0"
      - "Orphaned Outputs == 0"
      - "Deliverable-Consumer Gaps == 0"
```

---

## Implementation Order

Based on dependency analysis from `integration-map.md`:

| Order | Slice | Name | Depends On | Parallel? |
|-------|-------|------|------------|-----------|
| 1 | slice-01 | Block-System Upgrade | — | No (foundation — all others depend on this) |
| 2 | slice-02 | Kategorie-Seite als Block-Page | slice-01 | Yes — parallel with slice-03 |
| 2 | slice-03 | Produkt-Seite als Block-Page | slice-01 | Yes — parallel with slice-02 |
| 3 | slice-04 | Rechtsseiten als Block-Pages | slice-01, slice-02 | No (needs PageHeadingBlock from slice-02) |

**Critical path:** Slice 01 → Slice 02 → Slice 04

Slice 03 is independent of Slice 02 and can be implemented in parallel after Slice 01 completes.

---

## Slice Specifications

### Slice 01: Block-System Upgrade

**Spec:** `specs/phase-0/2026-02-25-block-page-migration/slices/slice-01-block-system-upgrade.md`
**Compliance:** `specs/phase-0/2026-02-25-block-page-migration/slices/compliance-slice-01.md`

**Deliverables to create/modify:**

```yaml
new_files:
  - frontend/lib/blocks/section-renderer.tsx
  - frontend/lib/blocks/section-layout.tsx

modified_files:
  - frontend/lib/blocks/types.ts
    changes:
      - Add SectionConfig interface
      - Add span? and row_span? to BlockConfig
      - Replace PageConfig.blocks[] with PageConfig.sections[]
      - Extend WooCommerceLoaderParams.query union
      - Extend WordPressLoaderParams with optional query field
      - Add WPPageContent interface
      - Add CategoryWithProducts interface
  - frontend/lib/blocks/page-config.ts
    changes:
      - Add resolveParams() function (named export)
      - Extend loadPageConfig() signature (pageType, theme?, routeParams?)
      - Implement 3-tier template lookup
      - Add backwards-compat: flat blocks[] to sections[] conversion
  - frontend/themes/default/pages/home.yaml
    changes:
      - Migrate from flat blocks: to sections: format (4 sections, each columns:1)
  - frontend/app/page.tsx
    changes:
      - Replace flat block iteration with <SectionRenderer sections={pageConfig.sections} skeletonMap={SKELETON_MAP} />

test_files:
  - frontend/tests/slices/block-page-migration/slice-01-block-system-upgrade.test.ts
```

**Test command:** `cd frontend && pnpm test tests/slices/block-page-migration/slice-01-block-system-upgrade.test.ts`

**Acceptance command:** `cd frontend && pnpm build`

---

### Slice 02: Kategorie-Seite als Block-Page

**Spec:** `specs/phase-0/2026-02-25-block-page-migration/slices/slice-02-kategorie-seite.md`
**Compliance:** `specs/phase-0/2026-02-25-block-page-migration/slices/compliance-slice-02.md`

**Gate:** Slice 01 must be complete and tests passing before starting.

**Deliverables to create/modify:**

```yaml
modified_files:
  - frontend/lib/graphql/queries.ts
    changes:
      - Add GET_CATEGORY_WITH_PRODUCTS query (single multi-root: products + productCategory)
  - frontend/lib/blocks/data-loaders.ts
    changes:
      - Add products_by_category case in woocommerceLoader()
      - Extend product_categories case to merge currentSlug into return value
  - frontend/lib/blocks/registry.ts
    changes:
      - Register page-heading, filter-chips, product-count block types
  - frontend/app/kategorie/[slug]/page.tsx
    changes:
      - Remove hardcoded JSX (h1, FilterChips, ProductCount, ProductGrid)
      - Add loadPageConfig('category', theme, { slug })
      - Add <SectionRenderer sections={pageConfig.sections} skeletonMap={...} />
      - Keep generateStaticParams, generateMetadata, CategoryPageClient unchanged

new_files:
  - frontend/components/blocks/page-heading-block.tsx
  - frontend/components/blocks/filter-chips-block.tsx
  - frontend/components/blocks/product-count-block.tsx
  - frontend/themes/default/pages/category.yaml

test_files:
  - frontend/tests/slices/block-page-migration/slice-02-kategorie-seite.test.ts
```

**Test command:** `cd frontend && pnpm test tests/slices/block-page-migration/slice-02-kategorie-seite.test.ts`

**Health check:** `curl http://localhost:3000/kategorie/t-shirts` — must return HTTP 200

---

### Slice 03: Produkt-Seite als Block-Page

**Spec:** `specs/phase-0/2026-02-25-block-page-migration/slices/slice-03-produkt-seite.md`
**Compliance:** `specs/phase-0/2026-02-25-block-page-migration/slices/compliance-slice-03.md`

**Gate:** Slice 01 must be complete and tests passing before starting. (Slice 02 NOT required.)

**Deliverables to create/modify:**

```yaml
modified_files:
  - frontend/lib/blocks/data-loaders.ts
    changes:
      - Add product_by_slug handler in woocommerceLoader()
      - Add GET_PRODUCT to imports
  - frontend/lib/blocks/registry.ts
    changes:
      - Register product-gallery, product-purchase, product-description block types
  - frontend/app/produkt/[slug]/page.tsx
    changes:
      - Remove hardcoded JSX rendering of gallery, variants, description
      - Add loadPageConfig('product', theme, { slug })
      - Add <SectionRenderer sections={pageConfig.sections} skeletonMap={SKELETON_MAP} />
      - Keep generateStaticParams, generateMetadata, JSON-LD, notFound() check unchanged

new_files:
  - frontend/components/blocks/product-gallery-block.tsx  # "use client"
  - frontend/components/blocks/product-purchase-block.tsx  # "use client"
  - frontend/components/blocks/product-description-block.tsx  # Server Component
  - frontend/themes/default/pages/product.yaml

test_files:
  - frontend/tests/slices/block-page-migration/slice-03-produkt-seite.test.ts
```

**Test command:** `cd frontend && pnpm test tests/slices/block-page-migration/slice-03-produkt-seite.test.ts`

**Health check:** `curl http://localhost:3000/produkt/[any-valid-slug]` — must return HTTP 200

**Client-Island note:** `ProductGalleryBlock` and `ProductPurchaseBlock` use `"use client"` directive. Verify no RSC boundary violations in build output.

---

### Slice 04: Rechtsseiten als Block-Pages

**Spec:** `specs/phase-0/2026-02-25-block-page-migration/slices/slice-04-rechtsseiten.md`
**Compliance:** `specs/phase-0/2026-02-25-block-page-migration/slices/compliance-slice-04.md`

**Gate:** Slice 01 AND Slice 02 must be complete and tests passing before starting.

**Reason for Slice 02 dependency:** `PageHeadingBlock` and `PageHeadingBlockSkeleton` are defined in Slice 02 and reused by legal route pages.

**Deliverables to create/modify:**

```yaml
modified_files:
  - frontend/lib/graphql/queries.ts
    changes:
      - Add GET_PAGE_CONTENT query (pageBy(uri: $slug) { title content })
  - frontend/lib/blocks/data-loaders.ts
    changes:
      - Add page_content branch in wordpressLoader()
      - Import GET_PAGE_CONTENT from queries.ts
      - Extend return type union to include WPPageContent
  - frontend/lib/blocks/registry.ts
    changes:
      - Register legal-content block type
  - frontend/components/layout/footer.tsx
    changes:
      - Replace ${WP_URL}/impressum etc. with /impressum etc.
      - Replace <a href> with Next.js <Link href>

new_files:
  - frontend/components/blocks/legal-content-block.tsx
  - frontend/themes/default/pages/legal.yaml
  - frontend/app/impressum/page.tsx
  - frontend/app/agb/page.tsx
  - frontend/app/datenschutz/page.tsx
  - frontend/app/widerruf/page.tsx

test_files:
  - frontend/tests/slices/block-page-migration/slice-04-rechtsseiten.test.ts
```

**Test command:** `cd frontend && pnpm test tests/slices/block-page-migration/slice-04-rechtsseiten.test.ts`

**Health check:** `curl http://localhost:3000/impressum` — must return HTTP 200

---

## Post-Slice Validation

FOR each completed slice:

```yaml
validation_steps:
  - step: "Deliverables Check"
    action: "Verify all files listed in DELIVERABLES_START/END section of the slice spec exist on disk"
    fail_action: "Stop — missing deliverables must be created before proceeding"

  - step: "Unit Tests"
    action: "Run slice-specific test command from slice metadata"
    fail_action: "Stop — fix failing tests before marking slice complete"

  - step: "Integration Tests"
    action: "cd frontend && pnpm test tests/slices/block-page-migration/"
    run_after: "All slices complete"
    fail_action: "Identify failing slice via integration-map.md Connections table, create targeted fix"

  - step: "TypeScript Build"
    action: "cd frontend && pnpm build"
    run_after: "Each slice (not just final)"
    fail_action: "Fix TypeScript errors before proceeding to next slice"

  - step: "Integration Points Check"
    action: "For each connection in integration-map.md Connections table: verify output exists and is importable by consumer"
    reference: "integration-map.md → Connections"
```

---

## E2E Validation

AFTER all slices completed:

```yaml
e2e_validation:
  - step: "Execute e2e-checklist.md"
    description: "Work through all checkboxes in e2e-checklist.md sequentially"

  - step: "Happy Path Flows"
    flows:
      - "Flow 1: Homepage with sections format"
      - "Flow 2: Category page block-based layout"
      - "Flow 3: Product page with client-island blocks"
      - "Flow 4: Legal pages as new Next.js routes"

  - step: "Cross-Slice Integration Points"
    action: "Verify all 12 integration points in e2e-checklist.md Cross-Slice section"

  - step: "FOR each failing check"
    actions:
      - "Identify responsible slice from integration-map.md Connections table"
      - "Check if failing check is a missing input, orphaned output, or implementation bug"
      - "Create targeted fix referencing the specific slice and connection number"
      - "Re-run slice test + integration test suite"

  - step: "Final Approval"
    condition: "ALL checks in e2e-checklist.md PASS"
    output: "Feature READY for merge — block-basierte Seiten-Migration complete"
```

---

## Key Implementation Notes for the Orchestrator

### Note 1: Shared File Modifications

Multiple slices modify the same files. The orchestrator must coordinate modifications:

| File | Modified by Slices | Strategy |
|------|--------------------|----------|
| `frontend/lib/blocks/data-loaders.ts` | Slice 02, Slice 03, Slice 04 | Each slice adds an additive case/handler. Apply in slice order (02 → 03 → 04). No conflicts. |
| `frontend/lib/blocks/registry.ts` | Slice 02, Slice 03, Slice 04 | Each slice adds new entries. Apply in slice order. No conflicts. |
| `frontend/lib/graphql/queries.ts` | Slice 02, Slice 04 | Slice 02 adds GET_CATEGORY_WITH_PRODUCTS, Slice 04 adds GET_PAGE_CONTENT. Both are new named exports — no conflicts. |

### Note 2: backwards-compat in loadPageConfig

Slice 01 implements backwards-compat: old flat `blocks:` YAML format is automatically converted to `sections: [{ columns: 1, blocks: [...] }]` with a `console.warn`. This is intentional. Do not remove the `console.warn` — it signals files that need migration.

### Note 3: Client-Island Directive

`ProductGalleryBlock` and `ProductPurchaseBlock` in Slice 03 require `"use client"` at the top of the file. The `SectionRenderer` (from Slice 01) is a Server Component. This is the correct Client-Island pattern: Server Component (SectionRenderer) contains async data loading, Client-Island (block component) receives data as props. Do not add `"use client"` to `SectionRenderer` or `SectionLayout`.

### Note 4: Apollo React.cache() Deduplication

Three blocks on the product page call `product_by_slug` with the same slug. Three blocks on each legal page call `page_content` with the same slug. Apollo `getClient()` uses `React.cache()` which deduplicates identical queries within the same RSC render pass. This means only one network request per unique (query, variables) combination. This is a design feature, not a bug.

### Note 5: PageHeadingBlock genericity

Slice 02 defines `PageHeadingBlock` for category pages (receives `CategoryWithProducts`, extracts `productCategory.name`). Slice 04 reuses `PageHeadingBlock` for legal pages (receives `WPPageContent`, extracts `title`). The block component must handle both data shapes. Refer to the Slice 02 spec "Code Examples" section for the implementation that handles `CategoryWithProducts | null`. Slice 04's legal pages use `page_content` query which returns `WPPageContent { title, content }` — the `PageHeadingBlock` receives this and renders `data?.title` or `data?.productCategory?.name` depending on what is available.

### Note 6: footer.tsx WP_URL removal

Slice 04 removes the `WP_URL` constant from `footer.tsx`. The `LEGAL_LINKS` array changes from `href: \`${WP_URL}/impressum\`` to `href: '/impressum'`. The `<a href>` tags become Next.js `<Link href>` components. Verify that `next/link` import is added if not already present.

---

## Rollback Strategy

IF implementation fails at a specific slice:

```yaml
rollback:
  - condition: "Slice 01 fails"
    action: "Revert all Slice 01 changes"
    note: "Safe — no user-facing impact. Slices 02-04 cannot start without Slice 01."
    git_hint: "git revert all commits from Slice 01 implementation"

  - condition: "Slice 02 fails"
    action: "Revert Slice 02 changes only"
    note: "Slice 01 remains stable. Category page falls back to previous implementation."
    files_to_revert:
      - frontend/lib/graphql/queries.ts (remove GET_CATEGORY_WITH_PRODUCTS)
      - frontend/lib/blocks/data-loaders.ts (remove products_by_category case)
      - frontend/lib/blocks/registry.ts (remove page-heading, filter-chips, product-count)
      - frontend/app/kategorie/[slug]/page.tsx (restore hardcoded JSX)
      - frontend/components/blocks/page-heading-block.tsx (delete)
      - frontend/components/blocks/filter-chips-block.tsx (delete)
      - frontend/components/blocks/product-count-block.tsx (delete)
      - frontend/themes/default/pages/category.yaml (delete)

  - condition: "Slice 03 fails"
    action: "Revert Slice 03 changes only"
    note: "Independent of Slice 02. Product page falls back to previous implementation."
    files_to_revert:
      - frontend/lib/blocks/data-loaders.ts (remove product_by_slug handler)
      - frontend/lib/blocks/registry.ts (remove product-gallery, product-purchase, product-description)
      - frontend/app/produkt/[slug]/page.tsx (restore hardcoded JSX)
      - frontend/components/blocks/product-gallery-block.tsx (delete)
      - frontend/components/blocks/product-purchase-block.tsx (delete)
      - frontend/components/blocks/product-description-block.tsx (delete)
      - frontend/themes/default/pages/product.yaml (delete)

  - condition: "Slice 04 fails"
    action: "Revert Slice 04 changes only"
    note: "Footer links revert to WordPress external URLs. Legal pages revert to 404."
    files_to_revert:
      - frontend/lib/graphql/queries.ts (remove GET_PAGE_CONTENT)
      - frontend/lib/blocks/data-loaders.ts (remove page_content branch)
      - frontend/lib/blocks/registry.ts (remove legal-content)
      - frontend/components/layout/footer.tsx (restore ${WP_URL}/... hrefs)
      - frontend/components/blocks/legal-content-block.tsx (delete)
      - frontend/themes/default/pages/legal.yaml (delete)
      - frontend/app/impressum/page.tsx (delete)
      - frontend/app/agb/page.tsx (delete)
      - frontend/app/datenschutz/page.tsx (delete)
      - frontend/app/widerruf/page.tsx (delete)

  - condition: "Integration fails after all slices"
    action: "Review integration-map.md Connections table for the failing connection"
    note: "Most likely cause: data shape mismatch between DataLoader return and Block Component expectation"
    debug_steps:
      - "Check GET_CATEGORY_WITH_PRODUCTS returns productCategory (not category) field"
      - "Check wordpressLoader page_content branch returns WPPageContent shape"
      - "Check PageHeadingBlock handles both CategoryWithProducts and WPPageContent"
      - "Check registry.ts has all 11 block types registered"
```

---

## Monitoring

During implementation:

| Metric | Alert Threshold | Action |
|--------|-----------------|--------|
| Slice unit test failures | > 0 blocking | Stop — fix tests before next slice |
| TypeScript build errors | Any | Stop — fix type errors before next slice |
| Missing Deliverable | Any file not created | Stop — create missing file |
| Registry missing entry | Any YAML block type not in registry | Stop — add registry entry |
| Integration test failures | > 0 | Identify responsible slice via integration-map.md, fix targeted |
| E2E checklist unchecked items | Any | Document and create fix tickets |

---

## Final Integration Summary

All 4 slices are APPROVED. Dependencies are correctly declared and verified. No missing inputs, no orphaned outputs, no deliverable-consumer gaps. Discovery coverage is 100% for in-scope items. The feature is ready for implementation in the order: Slice 01 → (Slice 02 and Slice 03 in parallel) → Slice 04.
