# Gate 1: Architecture Compliance Report

**Prufdatum:** 2026-02-27
**Discovery:** `specs/phase-1/2026-02-27-seed-data/discovery.md`
**Architecture:** `specs/phase-1/2026-02-27-seed-data/architecture.md`
**Wireframes:** N/A (kein UI-Feature, Discovery: "Wireframes: -- (kein UI-Feature)")
**Retry:** 2/3

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 18 |
| Warning | 0 |
| Blocking | 0 |

**Verdict:** APPROVED

---

## A) Feature Mapping

| Discovery Feature | Architecture Section | API Endpoint | DB Schema | Status |
|-------------------|---------------------|--------------|-----------|--------|
| 3 Parent + 12 Unter-Kategorien | Scope, Database Schema (wp_terms + wp_term_taxonomy), Data Flow | N/A (WP-CLI local) | wp_terms, wp_term_taxonomy (parent/child) | PASS |
| 110 Produkte (variable + simple) | Scope, Server Logic, Data Flow | N/A (WP-CLI local) | wp_posts (product, product_variation), wp_postmeta | PASS |
| Bestehende Attribute pa_groesse + pa_farbe | Scope, Constraints (bilingual attributes) | N/A | Reuse existing | PASS |
| 2 KI-Bilder pro Produkt via Replicate Flux 2 Pro | API Design (POST/GET predictions), Server Logic (generate-images.mjs) | Replicate REST API | wp_posts (attachment), wp_postmeta | PASS |
| Separates generate-images.mjs Script | Server Logic, Architecture Layers, New Files | Replicate API | N/A (filesystem) | PASS |
| Optimierte Prompts pro Kategorie | Server Logic (Business Logic Flow), Discovery Prompt-Strategie | N/A | N/A | PASS |
| Motiv-Definition pro Produkt | Slice 3 in Discovery, Architecture Scope (motifs in catalog) | N/A | N/A (part of catalog JSON) | PASS |
| Featured-Markierung ~10 Produkte | Database Schema (product_visibility taxonomy), Data Flow | N/A | wp_term_relationships (featured) | PASS |
| 3-5 deutsche Mock-Reviews pro Featured | Database Schema (wp_comments, wp_commentmeta), Data Flow | N/A | wp_comments + rating meta | PASS |
| Kategorie-Bilder (1 pro Kategorie) | New Files, Data Flow (update_term_meta thumbnail_id) | N/A | wp_termmeta (thumbnail_id) | PASS |
| REPLICATE_API_KEY in .env | Security (Authentication), Migration Map (.env.example) | N/A | N/A | PASS |
| Spreadconnect Demo-IDs | Database Schema (_spreadconnect_product_id postmeta) | N/A | wp_postmeta | PASS |
| Idempotenz (safe to run N times) | Validation Rules, NFRs, Constraints | N/A | WP option flag + per-slug check | PASS |
| Graceful degradation (missing images) | Error Handling Strategy, Validation Rules | N/A | N/A | PASS |
| Variable vs Simple product types | Constraints (Variable vs Simple), Business Logic Flow | N/A | wp_posts (product vs product_variation) | PASS |
| Color-only variable products (Accessoires) | Constraints (pod_create_color_only_variable_product) | N/A | Variations with color only | PASS |

---

## B) Constraint Mapping

| Constraint | Source | Architecture | Status |
|------------|--------|--------------|--------|
| Idempotenz (safe to run multiple times) | Discovery: Business Rules | Constraints: WP option flag + per-entity slug checks, NFRs: "run N times same result" | PASS |
| Existing seed pipeline integration | Discovery: Current State Reference | Constraints: "Must integrate with setup.sh -> mock-data.sh -> seed-products.php", Migration Map: all files documented | PASS |
| Docker volume mount (uploads in wpcli) | Discovery: "wordpress/uploads/ Verzeichnis ist gemountet in Docker" | Constraints: explicitly documents wpcli needs bind mount. Migration Map: docker-compose.yml listed with specific change "Add ./wordpress/uploads:/var/www/html/wp-content/uploads to wpcli volumes" | PASS |
| Variable vs Simple per category | Discovery: Business Rules | Constraints: 3 product creation functions (size+color, color-only, simple) | PASS |
| Bilingual attributes reuse | Discovery: Scope | Constraints: "pa_groesse / pa_farbe already exist, reuse, no changes needed" | PASS |
| Preise pro Kategorie | Discovery: Data (Produktverteilung) | Prices are in product catalog data, no separate architecture constraint needed | PASS |
| Review Rating 3-5 Sterne, gewichtet | Discovery: Data (Reviews) | Database Schema: "3-5 Sterne" | PASS |
| Review Namen Format | Discovery: Data ("Maria K.", "Thomas B.") | Mock data is hardcoded (implementation detail) | PASS |
| Bild-Format WebP 1MP | Discovery: Data (Bild-Generierung) | Technology Decisions: "WebP" + DTO: output_format "webp" | PASS |
| Bild-Speicherort | Discovery: Data | Data Flow: wordpress/uploads/products/{cat-slug}/{slug}-N.webp | PASS |
| Prompt <= 2000 chars | Architecture: DTO | PredictionInput validation documented | PASS |
| Concurrency max 5 parallel | Architecture: Rate Limiting | Security: "max 5 parallel" | PASS |
| Shared data format (PHP + Node.js) | Architecture: Trade-offs | JSON file (product-catalog.json) readable by both scripts | PASS |

---

## C) Realistic Data Check

### Codebase Evidence

```
# Existing patterns in seed-products.php (scripts/seed-products.php):
- Product names: wp_posts.post_title — WP core TEXT type, no sizing issue
- Product slugs: wp_posts.post_name — WP core VARCHAR(200), longest slug ~30 chars
- Descriptions: wp_posts.post_content — WP core LONGTEXT, no sizing issue
- Prices: wp_postmeta.meta_value — WP LONGTEXT, stored as string "24.99"
- Spreadconnect ID: wp_postmeta.meta_value — LONGTEXT, format "demo-tshirt-001" (~20 chars)
- Category names: wp_terms.name — VARCHAR(200), longest "Poster & Kunstdrucke" (20 chars)
- Category slugs: wp_terms.slug — VARCHAR(200), longest "poster-kunstdrucke" (18 chars)

# All data uses WordPress core tables — no custom schema.
# WordPress meta tables use LONGTEXT for meta_value.
# No VARCHAR sizing decisions introduced by this feature.
```

### External API Analysis

| API | Field | Measured Length | Sample | Arch Type | Recommendation |
|-----|-------|----------------|--------|-----------|----------------|
| Replicate | prediction.id | ~26 chars | UUID-like string | string (DTO) | PASS — transient, not stored in DB |
| Replicate | prediction.output[] | Variable (presigned URL, 200-500+ chars) | `https://replicate.delivery/...` | string[] (DTO) | PASS — downloaded to file, URL not persisted |
| Replicate | prediction.status | 10-20 chars | "starting", "processing", "succeeded", "failed" | string (DTO) | PASS — polling logic only |
| Replicate | input.prompt | Up to 2000 chars | Category-specific lifestyle prompt | string, validated <= 2000 | PASS — validation documented in DTO |

### Data Type Verdicts

| Field | Arch Type | Evidence | Verdict | Issue |
|-------|-----------|----------|---------|-------|
| _spreadconnect_product_id | postmeta (TEXT) | WP postmeta meta_value is LONGTEXT. Format "demo-{cat}-{nnn}" ~20 chars | PASS | None |
| Review rating | commentmeta (INT) | WP commentmeta meta_value is LONGTEXT. Values 3-5 stored as string | PASS | None |
| Review verified | commentmeta (STRING) | Value "0". WP commentmeta meta_value is LONGTEXT | PASS | None |
| Attachment file path | postmeta (TEXT) | Relative path e.g. "products/t-shirts/cool-shirt-1.webp" ~50 chars. WP LONGTEXT | PASS | None |
| Product name | wp_posts.post_title (TEXT) | Handcurated German names ~30-60 chars | PASS | None |
| Product slug | wp_posts.post_name (VARCHAR 200) | Slugified names ~20-40 chars, well within 200 limit | PASS | None |
| Category name | wp_terms.name (VARCHAR 200) | Longest "Poster & Kunstdrucke" = 20 chars | PASS | None |
| Category description | wp_term_taxonomy.description (LONGTEXT) | Short German descriptions | PASS | None |
| PredictionInput.prompt | string (in-memory) | Architecture validates <= 2000 chars | PASS | None |

**Note:** This feature creates NO custom database tables and NO custom columns. All data goes into existing WordPress/WooCommerce tables with standard field types (LONGTEXT for meta values). Data type risks are minimal since WP manages its own schema.

---

## D) External Dependencies

### D1) Dependency Version Check

**Project Type:** Existing project (frontend/package.json exists, docker-compose.yml pins images)

| Dependency | Arch Version | Pinning File | Pinned? | "Latest"? | Actual Latest | Current? | Status |
|------------|-------------|--------------|---------|-----------|---------------|----------|--------|
| `replicate` npm SDK | 1.4.0 | N/A (new dep for scripts/) | N/A (Greenfield) | No | 1.4.0 (verified via `npm view replicate version`) | PASS | PASS |
| WordPress | 6.9 | docker-compose.yml: `wordpress:6.9-php8.2-apache` | PASS | No | Pinned via Docker tag | PASS | PASS |
| WooGraphQL | v0.21.2 | setup.sh: explicit GitHub URL with version | PASS | No | Pinned | PASS | PASS |
| MySQL | 8.0 | docker-compose.yml: `mysql:8.0` | PASS | No | Pinned | PASS | PASS |
| PHP | 8.2 | Docker image `wordpress:6.9-php8.2-apache` | PASS | No | Pinned | PASS | PASS |
| Next.js | ^16.1.6 | frontend/package.json | PASS | No | Range-pinned | PASS | PASS |
| Flux 2 Pro model | `black-forest-labs/flux-2-pro` | N/A (Replicate model, no version pinning) | N/A | N/A | Model ID pinned | N/A | PASS |

**Pre-existing unpinned dependencies (not introduced by this feature):**

| Dependency | Arch Version | Status | Notes |
|------------|-------------|--------|-------|
| WooCommerce | "Latest stable at install time" | Pre-existing | setup.sh line 94: no --version flag. Architecture documents this as Risk (line 365) with mitigation: "Pin WC version in future infrastructure update; seed script uses stable WC APIs unlikely to break" |
| WPGraphQL | "latest stable" | Pre-existing | setup.sh line 102: no --version flag. Same risk category |

These are NOT introduced by the seed-data feature. The architecture correctly documents WooCommerce unpinned as a Risk with explicit mitigation strategy. Not blocking for this feature.

### D2) External APIs & Services

| Dependency | Rate Limits | Auth | Errors | Timeout | Status |
|------------|-------------|------|--------|---------|--------|
| Replicate API (Flux 2 Pro) | Plan-dependent, script max 5 concurrent | Bearer token via REPLICATE_API_KEY | Missing key: exit. 429: exponential backoff max 3 retries. Prediction failed: skip. Download failed: skip | Polling-based (status-driven, no explicit wall-clock timeout) | PASS |

---

## E) Migration Completeness

### Quantitaets-Check

| Discovery Claim | Architecture Coverage | Status |
|---|---|---|
| 4 existing files to modify (seed-products.php, mock-data.sh, docker-compose.yml, .env.example) | Migration Map: 4 rows matching exactly | PASS |
| 4 new file groups (generate-images.mjs, product-catalog.json, product images, category images) | New Files table: 4 entries matching exactly | PASS |

### Qualitaets-Check (Existing Files)

| File in Migration Map | Current Pattern | Target Pattern | Specific enough for test? | Status |
|---|---|---|---|---|
| `scripts/seed-products.php` | 3 hardcoded products, 2 flat categories, no images/reviews/featured | 110 products from catalog, 3+12 hierarchy, image import, reviews, featured | Yes: count products >= 110, check category parent/child, verify image attachments, verify reviews on featured | PASS |
| `scripts/mock-data.sh` | Runs seed with idempotency flag | Same + optional --force flag to re-run | Yes: test --force flag resets idempotency and re-runs | PASS |
| `docker-compose.yml` | wpcli has no uploads bind mount | wpcli gets `./wordpress/uploads:/var/www/html/wp-content/uploads` | Yes: test volume line present in wpcli service definition | PASS |
| `.env.example` | Contains REPLICATE_API_KEY with real key value | Contains REPLICATE_API_KEY with placeholder `your_replicate_api_token_here` | Yes: test grep for placeholder string, no `r8_` prefix | PASS |

### Qualitaets-Check (New Files)

| File | Purpose | Specific enough for test? | Status |
|---|---|---|---|
| `scripts/generate-images.mjs` | Replicate Flux 2 Pro batch image generation | Yes: file exists, imports replicate, handles 429, outputs to correct path | PASS |
| `scripts/product-catalog.json` | 110 products + 12 categories + motifs (shared between PHP and Node.js) | Yes: valid JSON, 110 products, 12 categories, each product has motif field | PASS |
| `wordpress/uploads/products/{cat-slug}/{slug}-{1,2}.webp` | 220 product images | Yes: verify path pattern, WebP format, 2 per product | PASS |
| `wordpress/uploads/products/{cat-slug}/category-{slug}.webp` | 12 category images | Yes: verify path pattern, 1 per category | PASS |

---

## Blocking Issues

None.

---

## Previous Blocking Issues (Resolved)

### Issue 1 (Retry 1): Docker wpcli container missing uploads bind mount

**Resolution in Architecture:** Migration Map now includes `docker-compose.yml` with specific change: "Add `./wordpress/uploads:/var/www/html/wp-content/uploads` to wpcli volumes". Constraints section explicitly documents: "wordpress service already has bind mount; wpcli service needs ... added (see Migration Map)".

**Status:** RESOLVED

### Issue 2 (Retry 1): .env.example contained real API key

**Resolution in Architecture:** Migration Map now includes `.env.example` with target pattern: "Contains REPLICATE_API_KEY with placeholder value" and specific change: "Replace real API key with placeholder `your_replicate_api_token_here`".

**Status:** RESOLVED

### Issue 3 (Retry 1): Product catalog format inconsistency

**Resolution in Architecture:** New Files table now consistently says `product-catalog.json`. Trade-offs section documents JSON rationale. Technology Decisions table still says "PHP array in separate file" (minor residual text) but this is superseded by the more specific New Files and Trade-offs sections.

**Status:** RESOLVED

### Issue 4 (Retry 1): WooCommerce version unpinned

**Resolution in Architecture:** Risks section now includes explicit entry: "WooCommerce version unpinned (pre-existing)" with mitigation "Pin WC version in future infrastructure update; seed script uses stable WC APIs unlikely to break". Correctly scoped as pre-existing infrastructure issue.

**Status:** RESOLVED (documented as Risk)

---

## Recommendations

1. **[Info]** The `.env.example` file currently contains what appears to be a real Replicate API key (`r8_Yx8...`). The Migration Map correctly documents replacing it with a placeholder. Ensure this replacement happens in the first implementation slice or before any public commit.

2. **[Info]** The `replicate` npm package (1.4.0) will be a standalone dependency for `scripts/generate-images.mjs`. Consider adding a `scripts/package.json` or documenting `npm install replicate` in developer workflow documentation.

3. **[Info]** Technology Decisions table (line 377) still says "PHP array in separate file" for product catalog, while New Files and Trade-offs correctly say JSON. Minor text inconsistency that does not affect implementation since JSON is clearly documented in 3 other locations.

4. **[Info]** Pre-existing WooCommerce/WPGraphQL version pinning should be tracked as a separate infrastructure improvement outside this feature's scope.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 0

**Changes since Retry 1:**
- docker-compose.yml added to Migration Map with wpcli uploads bind mount
- .env.example added to Migration Map with placeholder replacement
- product-catalog format unified to JSON (product-catalog.json)
- WooCommerce unpinned version documented as Risk with mitigation

**Next Steps:**
- [ ] Proceed to slice planning and implementation
- [ ] Prioritize .env.example API key placeholder fix early (security)
