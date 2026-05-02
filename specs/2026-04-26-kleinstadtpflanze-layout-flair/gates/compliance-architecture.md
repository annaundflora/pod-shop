# Gate 1: Architecture Compliance Report

**Reviewed Architecture:** `specs/2026-04-26-kleinstadtpflanze-layout-flair/architecture.md`
**Review Date:** 2026-04-28
**Discovery:** `specs/2026-04-26-kleinstadtpflanze-layout-flair/discovery.md`
**Codebase Scan:** `specs/2026-04-26-kleinstadtpflanze-layout-flair/codebase-scan.md`
**Wireframes:** N/A — Discovery line 6 explicitly states no wireframes; Design-E HTML at `/tmp/kp-design-e/` is the visual reference. Wireframe checks are skipped per task instructions.

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 41 |
| Blocking | 0 |

**Verdict: APPROVED**

---

## A) Feature Mapping

Every In-Scope item from `discovery.md` (lines 33–41) is covered by the architecture. Every Out-of-Scope item (lines 43–59) is NOT introduced.

### A.1 In-Scope coverage

| Discovery In-Scope Item | Architecture Section | API/Loader | YAML/Component | Status |
|-------------------------|----------------------|-----------|----------------|--------|
| 6 new blocks: `editorial-mini`, `faq-accordion`, `motif-grid`, `service-box`, `motif-cross-sell`, `brand-recap` | §2.1–2.6 (each block fully specified) | inline (Slice 1+2); WC extension path documented for `motif-cross-sell` | One file each in `frontend/components/blocks/` | Pass |
| Registry registration for 6 new blocks | §2.7 (concrete patch) | — | `frontend/lib/blocks/registry.ts` | Pass |
| HeroBlock 2-col variant (YAML `variant: "two-col"`) | §3.1 | inline (kleinstadtpflanze) | `hero-block.tsx` extended; default kept | Pass |
| ProductImageGallery vertical-thumbs | §3.2 | YAML `layout` param routed via loader merge | `product-image-gallery.tsx` + `product-gallery-block.tsx` | Pass |
| PDP description integrated into Purchase column | §3.3 (Option A chosen) | YAML `withDescription: true` | `product-purchase-block.tsx` extended in place | Pass |
| `themes/kleinstadtpflanze/pages/home.yaml` | §5.1 (full content) | — | New file | Pass |
| `themes/kleinstadtpflanze/pages/product.yaml` | §5.2 (full content) | — | New file | Pass |
| `themes/kleinstadtpflanze/pages/category.yaml` | §5.3 (full content) | — | New file | Pass |
| Trust-Badges YAML re-copy with honest texts | §5.1 (homepage trust-badges block) + §5.2 (PDP trust-badges block) | inline | YAML-only — no code change | Pass |
| Default `home.yaml` unchanged | Invariant table §1 ("Default theme YAMLs are NEVER edited") | — | — | Pass |

### A.2 Out-of-Scope respected

| Out-of-Scope Item | Architecture | Status |
|-------------------|--------------|--------|
| DealBar with countdown | Not in any YAML or component | Pass |
| Editorial "Werkstatt" / Familienbetrieb | Not introduced; §5.1 explicitly notes honesty review | Pass |
| Reviews-Summary / Trust-Theater | Not introduced; PDP omits `product-reviews` | Pass |
| Newsletter on kleinstadtpflanze | Not in `home.yaml` | Pass |
| Testimonials on kleinstadtpflanze | Not in `home.yaml` | Pass |
| Featured-Collection on kleinstadtpflanze | Not in `home.yaml` (uses `featured_products` via `product-grid`, which is allowed) | Pass |
| UtilBar / MenuRow | Not introduced | Pass |
| HeroC | Only `two-col` variant added | Pass |
| Sticky-ATC-Bar | Not introduced | Pass |
| PDP Tabs | §3.3 explicitly chose direct integration over tabs | Pass |
| Hero-Visual asset pipeline | §6 + §11 confirm `image_url` is a string only; placeholder fallback documented | Pass |
| Motiv-Taxonomie in WooCommerce | §4.2 explicitly defers; graceful-null contract | Pass |
| Spreadshirt color extensions | Not touched | Pass |

---

## B) Constraint Mapping

| Constraint | Source | Architecture Location | Status |
|------------|--------|----------------------|--------|
| Theme isolation (kleinstadtpflanze YAMLs only) | Discovery Business Rules line 239 | §1 Invariant table; §5.1–5.3 only under `themes/kleinstadtpflanze/pages/` | Pass |
| German UI | CLAUDE.md + discovery copy | §5.1–5.3 all copy in German; honesty constraints respected | Pass |
| Theme tokens only (no hardcoded colors/radii) | CLAUDE.md + Discovery + scanner AVOID #4 | §8.4 explicit token-by-block table with forbidden constructs listed | Pass |
| HeroBlock backward compatibility | Discovery line 241 | §3.1 — branch only fires on `data?.variant === 'two-col'`; default render path untouched; default theme uses `content_source: wordpress` and never sets `variant` | Pass |
| ProductImageGallery backward compatibility | Discovery line 242 | §3.2 — `layout` defaults to `'default'`; existing render unchanged; mobile branch identical | Pass |
| Motif-cross-sell graceful null | Discovery line 243 | §2.5 + §4.2 explicit contract; `tiles == null \|\| tiles.length === 0` → `null` | Pass |
| Service-box content source default = inline | Discovery line 244 + Q4 | §4.1 + §6 — `source` defaults `'inline'`; per-category/per-product reserved | Pass |
| FAQ Single-Open mode | Discovery line 246 + Q3 | §2.2 + §7 — `useState<number>(-1)`; opening B closes A | Pass |
| Brand-recap tagline configurable | Discovery line 247 | §2.6 + §6 — YAML `tagline` required string; logoText defaults to env | Pass |
| `prefers-reduced-motion` for FAQ | Discovery risk line 318 | §2.2 + §8.1 — explicit CSS rule | Pass |
| Per-product YAML override (slug-tier) reserved | Discovery line 244 (Q4-C future) | §4.1 — Tier-1 lookup `themes/kleinstadtpflanze/pages/product/{slug}.yaml` | Pass |
| Honest copy enforced (no `4,9★`, no `Klimaneutral`, no `Made in Germany`, no `Familienbetrieb`) | Discovery Out-of-Scope + §245 + scanner AVOID #5 | §4.1 inline note: "No Familienbetrieb, no review claims, no Klimaneutral, no Made-in-Germany lie"; §5.1 trust-badges items use neutral copy | Pass |
| `space-y-12` collapses cleanly when block returns null | Discovery line 243 | §4.2 references existing null-guard pattern (`product-recommendations-block.tsx:16`) | Pass |
| `aria-labelledby` for sections | Codebase convention | §2 common contract | Pass |
| Prose recipe for HTML rendering reused | Scanner REUSE #5 | §3.3 — `prose prose-sm text-text-secondary max-w-none` copied locally | Pass |

---

## C) Realistic Data Check

### C.1 No new DB columns

Architecture §6 (line 907) explicitly states: **"No new DB columns. All new block fields are YAML-only (read at build/RSC time). VARCHAR/length limits do not apply."**

Verified: every new field in `Data Types & Validation` table (§6) is YAML-only. The only DB-persisted fields touched are existing WC fields (`description`, `image`, `galleryImages`) consumed by existing loaders. No migrations are introduced.

### C.2 Codebase Evidence (existing patterns)

```
# Pattern: BlockComponentProps<T> contract — found in 26 existing blocks
frontend/lib/blocks/types.ts:86 → BlockComponentProps<T = unknown>

# Pattern: prose recipe for WP HTML — found in product-description-block.tsx:13-17
"prose prose-sm text-text-secondary max-w-none" + dangerouslySetInnerHTML

# Pattern: ICON_MAP (lucide-react string→Component) — found in 2 places
frontend/components/blocks/trust-badges-block.tsx:5-13
frontend/components/blocks/usp-bar-block.tsx:6-11

# Pattern: graceful-null guard — found in product-recommendations-block.tsx:16
if (!data?.products?.length) return null

# Pattern: 'use client' + useState — found in 4 of 26 blocks
announcement-bar-block.tsx, newsletter-signup-block.tsx, product-purchase-block.tsx, product-gallery-block.tsx
```

All architecture-introduced patterns reuse existing recipes verbatim.

### C.3 Field-by-Field Runtime Contract Check

| Field | Storage | Validation in Arch | Runtime Risk | Verdict |
|-------|---------|--------------------|--------------|---------|
| `editorial-mini.headline` | YAML string | Required, non-empty; renders as h2 with `text-balance` | None | Pass |
| `editorial-mini.text` | YAML string | 1–3 sentences (~300 chars target); reflows naturally | None | Pass |
| `editorial-mini.image_url` | YAML string (URL or empty) | Empty allowed → SVG placeholder fallback | None | Pass |
| `editorial-mini.cta` | YAML `{text, href}` | Both required when `cta` present; href internal `/`-prefixed or anchor | None | Pass |
| `motif-grid.items[].image_url` | YAML string | Used inside `<Image>` with `aspect-[4/3]`; treated as opaque path string `/motifs/*.jpg` (no asset-pipeline coupling) | None — block does not validate file existence (Next/Image returns 404 if missing — acceptable per Discovery risk) | Pass |
| `motif-grid.items[].href` | YAML string | Block-level guard: skip item if `!href.startsWith('/')` | None | Pass |
| `motif-grid.items[].name` | YAML string | Required; `truncate` if >~20 chars | None | Pass |
| `faq-accordion.items[].q` | YAML string | Required; renders as `<button>` text; long questions wrap | None | Pass |
| `faq-accordion.items[].a` | YAML string (PLAIN) | Required; PLAIN STRING decision documented (§6 + Q&A #3); no `dangerouslySetInnerHTML`, zero XSS surface | None — schema (`a: string`) and block (`{item.a}` text node) agree | Pass |
| `service-box.items[].icon` | YAML enum (ICON_MAP key) | Unknown key → no icon, no crash (graceful) | None | Pass |
| `service-box.items[].label` / `.detail` | YAML string | Required; bold/1-line + 1–2 lines | None | Pass |
| `service-box.source` | YAML enum `'inline' \| 'category' \| 'product'` | Default `'inline'`; non-inline = no-op in Slice 2 | None — reserved enum, deferred per Q4 | Pass |
| `motif-cross-sell.tiles` | YAML `Tile[] \| null` | `null` or `[]` → block returns `null` | None — graceful-null contract unambiguous (§2.5 + §4.2): block returns `null`, no heading, no empty container, no layout shift, no console errors. SectionRenderer wraps each block in its own grid cell so adjacent sections collapse cleanly. | Pass |
| `motif-cross-sell.tiles[].href` | YAML string | Validated as `/produkt/{slug}` | None | Pass |
| `brand-recap.tagline` | YAML string | Required, ~60 chars target; `text-balance` reflow | None | Pass |
| `brand-recap.logoText` | YAML string (optional) | Default = `process.env.NEXT_PUBLIC_SHOP_NAME` | None | Pass |
| `brand-recap.href` | YAML string | Default `/` | None | Pass |
| `hero.variant` | YAML enum `'default' \| 'two-col'` | Default render path runs when missing | None — branch only on explicit match | Pass |
| `hero.tag`, `hero.ctaSecondary`, `hero.trustItems`, `hero.visual.imageUrl` | YAML | Optional; `trustItems` capped at 3 via `slice(0,3)` | None | Pass |
| `product-gallery.layout` | YAML enum `'default' \| 'vertical-thumbs'` | Default layout when missing; mobile branch unchanged | None | Pass |
| `product-purchase.withDescription` | YAML boolean | Default `false`; renders existing WC `description` HTML via reused prose recipe | None — reuses existing XSS surface (no new `dangerouslySetInnerHTML` sink) | Pass |
| `product-purchase.serviceBox` | YAML `ServiceBoxData` | Optional; composed inline | None | Pass |

### C.4 External APIs (none new)

No new external APIs introduced. Existing WC GraphQL fields (`product.description`, `product.image`, `product.galleryImages`) are consumed unchanged. No rate-limit / timeout / auth concerns added by this feature.

### C.5 Ambiguity #4 cross-check (FAQ schema vs component)

- **Schema** (§2.2 / §6): `FaqAccordionItem.a: string` (PLAIN).
- **Component contract** (§2.2): renders inside `role="region"` panel; no `dangerouslySetInnerHTML` mentioned.
- **Q&A #3** (§Q&A line 1136): "Plain string. Smallest XSS surface, simplest block; Markdown is a future opt-in."

Schema and block render path agree: plain string only. No ambiguity.

---

## D) External Dependencies

### D.1 Dependency Version Check

This feature introduces **NO new runtime dependencies** (no new npm packages, no new GraphQL schema fields, no new external services).

Architecture §11 explicitly notes: "Phase 2 does NOT touch `theme.yaml`" and §1 confirms additive-only changes. The reused stack:

| Dependency | Source | Already Pinned in repo? | Status |
|------------|--------|-------------------------|--------|
| Next.js 16 App Router | Existing `frontend/package.json` | Yes (existing project) | Pass |
| Tailwind CSS v4 | Existing | Yes | Pass |
| Vitest | Existing `frontend/vitest.config.ts` | Yes | Pass |
| `@testing-library/jest-dom` | Existing `tests/setup.ts` | Yes | Pass |
| `lucide-react` (for ICON_MAP) | Existing (used in `trust-badges-block.tsx`, `usp-bar-block.tsx`) | Yes | Pass |
| Apollo Client (for WC queries) | Existing | Yes | Pass |

No "Latest" / "current" / unpinned strings appear in architecture. No greenfield concern.

### D.2 External APIs & Services

| Dependency | Status |
|------------|--------|
| New external APIs | None introduced |
| Rate limits | N/A (no new API calls) |
| Timeouts | N/A |
| Auth flows | N/A |

---

## E) Pattern Consistency (Gate 1b)

`codebase-scan.md` IS present. All 13 REUSE, 9 EXTEND, 6 NEW, and 5 AVOID recommendations are checked against architecture decisions.

### E.1 Scanner Output Structural Plausibility

| Check | Verdict |
|-------|---------|
| Every AVOID has basis (replaces pattern OR decision-log) | Pass — all 5 AVOID items reference Discovery Out-of-Scope or CLAUDE.md |
| Every REUSE has count >= 2 (or is justified single-instance pattern) | Pass — most REUSE items reference >= 2 occurrences; single-instance patterns (e.g. `prose` recipe, `Card variant="interactive"`) are explicitly labeled "(reference)" / "(pattern)" |
| Every recommendation has at least 1 concrete file path | Pass — every row lists a file path with line ranges |

### E.2 REUSE recommendations (13)

| # | Scanner REUSE | Architecture | Verdict |
|---|---------------|--------------|---------|
| R1 | `BlockComponentProps<T>` + named exports | §2 common contract — every new block follows it | Pass |
| R2 | 3-tier YAML override (`themes/kleinstadtpflanze/pages/{home,product,category}.yaml`) | §5.1–5.3 create exactly those 3 files; no loader change | Pass |
| R3 | Inline data source (`content_source: inline`, `params.props`) | All 6 new blocks default to `inline`; YAMLs use `params.props.{...}` | Pass |
| R4 | `ICON_MAP` pattern (lucide-react) | §2.4 service-box uses local ICON_MAP; §3.1 hero trust-row "copied locally per repo convention"; §2.2 FAQ allows icon optionally | Pass |
| R5 | `prose prose-sm text-text-secondary max-w-none` recipe | §3.3 — exact recipe copied locally into `ProductPurchaseBlock` | Pass |
| R6 | Tile-Grid pattern (`Card variant="interactive" asChild`) | §2.3 motif-grid + §2.5 motif-cross-sell both reuse it | Pass |
| R7 | Card-with-figure recipe | §2.4 service-box uses testimonials-card recipe | Pass |
| R8 | `ProductRecommendationsBlock` reuse on Kategorie-Bottom | §5.3 reuses block as-is with `source: bestsellers` (existing source) | Pass |
| R9 | `ProductGridBlockSkeleton` re-export pattern | §2.5 — `export { ProductGridBlockSkeleton as MotifCrossSellBlockSkeleton }` | Pass |
| R10 | Suspense + Skeleton wiring (`null` for inline-sync) | §10 Slice Mapping — all 6 new blocks register `null` SKELETON_MAP entries | Pass |
| R11 | `'use client'` + localStorage + mounted guard | §7 explicitly NOT used — Discovery says "Tracking via lokalem State (kein localStorage)"; pattern available if needed but not adopted | Pass (justified non-use) |
| R12 | `SectionLayout` with `columns: 2, gap: gap-16` for PDP main row | §5.2 PDP YAML uses identical shape | Pass |
| R13 | Theme tokens for all CSS | §8.4 token-by-block compliance table | Pass |

### E.3 EXTEND recommendations (9)

| # | Scanner EXTEND | Architecture | Verdict |
|---|----------------|--------------|---------|
| E1 | `registry` — add 6 entries | §2.7 concrete patch with comment header `// Slice 07 — Kleinstadtpflanze Layout-Flair:` | Pass |
| E2 | `SKELETON_MAP` per page | §10 Slice Mapping lists each page's skeleton additions | Pass |
| E3 | `HeroBlock` `variant: "two-col"` | §3.1 documented; backward-compatible via prop branching | Pass |
| E4 | `ProductImageGallery` `layout="vertical-thumbs"` | §3.2 documented; mobile branch unchanged | Pass |
| E5 | `ProductGalleryBlock` forwards `layout` | §3.2 patch shown | Pass |
| E6 | `ProductPurchaseBlock` description + service-box | §3.3 — Option A chosen (extend in place); trade-off explicitly documented | Pass |
| E7 | `WooCommerceLoaderParams.query` union — new branch only IF Q2 lands on tag/taxonomy | §4.2 — deferred; no new query type added in Slice 2 | Pass |
| E8 | `PRODUCT_DETAIL_FRAGMENT` for tags/motif metadata | §4.2 — deferred (only required when Q2 lands) | Pass |
| E9 | Trust-Badges YAML override (no code change) | §5.1 + §5.2 use existing `{icon, text}` shape | Pass |

### E.4 NEW recommendations (6)

| # | Scanner NEW | Architecture | Verdict |
|---|-------------|--------------|---------|
| N1 | `EditorialMiniBlock` | §2.1 fully specified | Pass |
| N2 | `FaqAccordionBlock` | §2.2 fully specified with a11y contract | Pass |
| N3 | `MotifGridBlock` | §2.3 fully specified | Pass |
| N4 | `ServiceBoxBlock` | §2.4 fully specified | Pass |
| N5 | `MotifCrossSellBlock` | §2.5 fully specified with graceful-null contract | Pass |
| N6 | `BrandRecapBlock` | §2.6 fully specified | Pass |

No new abstraction is invented outside the scanner's NEW list. No deviation.

### E.5 AVOID recommendations (5)

| # | Scanner AVOID | Architecture | Verdict |
|---|---------------|--------------|---------|
| A1 | Standalone `product-description` section in kleinstadtpflanze PDP | §3.3 + §5.2 — omitted from `product.yaml`; description folded into `ProductPurchaseBlock` via `withDescription: true`. Block remains in registry for default theme. | Pass |
| A2 | `product-reviews` on kleinstadtpflanze PDP | §5.2 — omitted; block stays in registry for default | Pass |
| A3 | `testimonials`, `newsletter-signup`, `featured-collection` on kleinstadtpflanze Homepage | §5.1 — none appear in `home.yaml`; blocks stay in registry | Pass |
| A4 | Hardcoded colors/radii/shadows | §8.4 — explicit token-only enforcement per block; forbidden constructs listed | Pass |
| A5 | Marketing claims (4,9★, 2.180 Bewertungen, Klimaneutral, Made in Germany, Familienbetrieb) | §5.1 trust-badges + §4.1 honesty review explicitly excludes these | Pass |

---

## F) Migration Completeness

**Not applicable** — this feature scope is additive (new blocks + extensions + new theme YAMLs). No migration/refactoring trigger words ("migrate", "convert", "refactor migration") apply at the scope level. The PDP `product-description` removal is documented as part of `ProductPurchaseBlock` extension (§3.3), not as a migration step.

---

## G) Flagged Ambiguity Audit

The 8 ambiguities the architecture author flagged were specifically reviewed:

| # | Ambiguity | Architecture Resolution | Verdict |
|---|-----------|-------------------------|---------|
| 1 | Hero `content_source: inline` for kleinstadtpflanze (default was `wordpress`) | §3.1 explicit: only kleinstadtpflanze YAML switches to `inline`. Verified `themes/default/pages/home.yaml` line 7-8 still uses `content_source: wordpress, params.page_slug: "/"`. Default theme will continue rendering the WP-driven hero unchanged. §11 risk row "Default-theme contamination" further argues why WP cannot leak `variant` to default render. | Pass — no regression to default theme |
| 2 | ProductPurchaseBlock extended in place vs. new combined block | §3.3 — explicit Option A vs Option B comparison table; rationale ("single source of truth on PDP-right column", "avoid triplicating SectionLayout", "purchase block is already `'use client'`"); trade-off ("3 concerns") acknowledged in §11 with mitigation. Q&A #1 echoes the choice. | Pass — choice is consistent and well-justified |
| 3 | YAML params merged into WC product result inside `woocommerceLoader` | §3.2 + §3.3 + §11 risk row — merge is a single line per branch in `product_by_slug` only. Other product-page consumers: `data-loaders.ts:179` `product_by_slug` is the single branch returning `ProductDetailData`; no other branch returns the same shape. The merge of 3 optional fields (`layout`, `withDescription`, `serviceBox`) is sidecar — does not mutate WC fields. Default theme YAML omits these params, so the merge is a no-op for default. | Pass — no break to other consumers |
| 4 | FAQ answer = plain string (Markdown deferred) | §2.2 + §6 + Q&A #3 — schema `a: string`, render path uses text node only (no `dangerouslySetInnerHTML`). Schema and block agree. Future Markdown opt-in is a 1-line change documented. | Pass — schema and block agree |
| 5 | Existing test `slice-03-theme.test.ts` AC-5b will fail | §9.7 + §11 risk row + §10 Slice 1 file list — explicit: "MODIFIED test: `tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts` (retire AC-5b)". Recommendation is to retire AC-5b and replace with a positive assertion in the same Slice-1 commit. Verified the test exists at line 419-427 of the file with the exact assertion the architecture quotes. | Pass — fix path prescribed in Slice 1 |
| 6 | vitest setup defaults `NEXT_PUBLIC_THEME=kleinstadtpflanze` | §9.6 + Q&A #7 — global default with per-test override capability; trade-off acknowledged ("if any existing test asserts default-theme behavior implicitly, it could break"); mitigation ("Implementer should run the full test suite once with the global change and verify no regressions; if any, fall back to per-test scoping"). Risk explicit and reversible. | Pass — risk acknowledged, mitigation documented |
| 7 | Motif data graceful-null contract | §2.5 + §4.2 — block returns `null` when `tiles == null \|\| tiles.length === 0`; no heading, no empty container, no layout shift; `SectionRenderer` wraps in own grid cell so adjacent sections collapse cleanly. Existing pattern referenced (`product-recommendations-block.tsx:16`). Contract is unambiguous. | Pass — contract unambiguous |
| 8 | Motif images at `/motifs/*.jpg` opaque string | §6 — `image_url: YAML → string`; treated as opaque path string used by Next/Image; no asset-pipeline coupling spec'd; if file missing Next/Image returns 404 (acceptable per Discovery risk). Hero visual `imageUrl` similarly treated. | Pass — opaque string, no pipeline coupling |

---

## H) Completeness Check

| Item | Required | Found | Status |
|------|----------|-------|--------|
| All 6 new blocks fully specified (file path, data source, server/client, type, slice) | 6 | §2.1–2.6 — all 6 with table headers | Pass |
| HeroBlock extension specified | 1 | §3.1 | Pass |
| ProductImageGallery extension specified | 1 | §3.2 | Pass |
| ProductPurchaseBlock extension specified | 1 (in-place integration of description + service-box) | §3.3 | Pass |
| All 3 page YAMLs present (home, product, category) | 3 | §5.1, §5.2, §5.3 — full content | Pass |
| Slice mapping matches Discovery's 3 slices (Homepage, PDP, Category) | 3 | §10 — Slice 1, Slice 2, Slice 3 with file lists | Pass |
| Open Questions carry-over present (8 from Discovery) | 8 | §12 — all 8 carried with architectural answer | Pass |
| Q&A log present | Yes | §Q&A 1–8 | Pass |
| Risks & Mitigations present | Yes | §11 — 9 architecture-level risks added on top of Discovery | Pass |
| Sign-Off section present | Yes | §Sign-Off | Pass |

---

## Verdict

**Status: APPROVED**

**Blocking Issues: 0**

The architecture comprehensively maps all Discovery in-scope items, respects all out-of-scope exclusions, follows every scanner REUSE/EXTEND/NEW recommendation, and does not violate any AVOID recommendation. No new DB columns are introduced; all data is YAML-only with documented validation contracts. The 8 author-flagged ambiguities are each resolved with a clear, evidence-based decision. The graceful-null contract for `motif-cross-sell` is unambiguous. The fix path for the existing test `slice-03-theme.test.ts` AC-5b is prescribed in Slice 1.

**Next Steps:**
- Proceed to slice planning / implementation.
- Slice 1 implementer must remember: (a) update `tests/setup.ts` env default, (b) retire `slice-03-theme.test.ts` AC-5b in the same commit that creates `themes/kleinstadtpflanze/pages/`, (c) prompt user for the brand-recap tagline (Discovery Q1) before merge.
- Slice 2 implementer must verify the YAML-param merge in `woocommerceLoader.product_by_slug` does not affect default-theme PDP (assert YAML omission of `layout`/`withDescription`/`serviceBox` results in unchanged default render).
