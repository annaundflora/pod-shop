# Feature: Seed Data — 100+ POD-Produkte mit KI-generierten Bildern

**Epic:** –
**Status:** Ready
**Discovery:** `discovery.md` (same folder)
**Derived from:** Discovery constraints, NFRs, and risks

---

## Problem & Solution

**Problem:**
- Shop hat nur 3 Demo-Produkte in 2 Kategorien — wirkt leer und unrealistisch
- Keine Produktbilder vorhanden (`sourceUrl: null`)
- Featured Products nicht markiert → Homepage-Section leer
- Keine Reviews → Social Proof fehlt
- Keine Kategorie-Hierarchie → Navigation unbrauchbar

**Solution:**
- Erweiterte `seed-products.php` mit 110 handkuratierten POD-Produkten in 12 Kategorien
- Separates `generate-images.mjs` Script generiert 2 Bilder pro Produkt via Replicate Flux 2 Pro
- Bilder werden committet und beim Seed als WordPress-Medien importiert

**Business Value:**
- Realistischer Shop-Eindruck für Demos und Entwicklung
- Pagination, Suche, Filterung testbar mit echten Datenmengen
- Professionelle Produktbilder für ~$3.50 (232 Bilder × ~$0.015)

---

## Scope & Boundaries

| In Scope |
|----------|
| 3 Parent-Kategorien + 12 Unter-Kategorien mit Beschreibungen |
| 110 Produkte (variable + simple), handkuratierte Namen/Beschreibungen (DE/EN kreativ gemischt) |
| Bestehende Attribute: `pa_groesse` (S–XXL) + `pa_farbe` (Schwarz/Weiß/Grau/Navy) |
| 2 KI-generierte Bilder pro Produkt via Replicate Flux 2 Pro (1MP) |
| Separates Node.js-Script `scripts/generate-images.mjs` für Bildgenerierung |
| Optimierte Prompts pro Produktkategorie |
| Definition der Motive/Designs pro Produkt |
| Featured-Markierung für ~10 Produkte |
| 3–5 deutsche Mock-Reviews pro Featured-Produkt |
| Kategorie-Bilder (1 pro Kategorie aus Replicate) |
| `REPLICATE_API_KEY` in `.env` |
| Spreadconnect Demo-IDs für alle Produkte |

| Out of Scope |
|--------------|
| Neue Attribute (Material, Schnitt, Format) |
| Bilder pro Farbvariante (nur 2 pro Produkt, nicht pro Farbe) |
| Spreadshirt-API-Integration für Produktdaten |
| Echte rechtliche Inhalte (Legal Pages bleiben Placeholder) |
| Replicate-Aufruf im Docker-Seed (Bilder werden vorab generiert + committet) |

---

## API Design

### Overview

| Aspect | Specification |
|--------|---------------|
| Style | Replicate HTTP API (REST) — consumed by `generate-images.mjs` |
| Authentication | `REPLICATE_API_KEY` as Bearer token |
| Rate Limiting | Replicate: varies by plan, Script handles 429 with retry |

### Endpoints (consumed)

| Method | Path | Request | Response | Auth | Business Logic |
|--------|------|---------|----------|------|----------------|
| POST | `https://api.replicate.com/v1/models/black-forest-labs/flux-2-pro/predictions` | `{ input: { prompt, aspect_ratio, output_format } }` | `{ id, status, output: [url] }` | Bearer token | Creates image prediction |
| GET | `https://api.replicate.com/v1/predictions/{id}` | — | `{ status, output: [url] }` | Bearer token | Polls prediction status |

### Data Transfer Objects

| DTO | Fields | Validation | Notes |
|-----|--------|------------|-------|
| PredictionInput | `prompt: string`, `aspect_ratio: "1:1"`, `output_format: "webp"` | prompt ≤ 2000 chars | Flux 2 Pro input schema |
| PredictionOutput | `id: string`, `status: string`, `output: string[]` | status ∈ {starting, processing, succeeded, failed} | Poll until succeeded/failed |

---

## Database Schema

### Entities (WooCommerce — managed by WP-CLI/WooCommerce API)

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `wp_terms` + `wp_term_taxonomy` | Product categories (parent/child hierarchy) | `term_id`, `parent`, `taxonomy=product_cat` |
| `wp_posts` (type=product) | Products (simple + variable) | `post_title`, `post_name`, `post_type` |
| `wp_posts` (type=product_variation) | Product variations (size × color) | `post_parent`, `post_type` |
| `wp_postmeta` | Product metadata | `_price`, `_regular_price`, `_spreadconnect_product_id` |
| `wp_posts` (type=attachment) | Media library entries | `post_mime_type`, `guid` |
| `wp_postmeta` (attachment) | Image metadata | `_wp_attached_file`, `_wp_attachment_metadata` |
| `wp_comments` | Product reviews | `comment_post_ID`, `comment_author`, `comment_content` |
| `wp_commentmeta` | Review rating | `meta_key=rating`, `meta_value=1-5` |
| `wp_term_relationships` | Product ↔ Category assignment | `object_id`, `term_taxonomy_id` |
| `wp_term_relationships` | Product ↔ Visibility (featured) | `object_id`, taxonomy=`product_visibility`, term=`featured` |

### Schema Details

Keine neuen Tabellen. Alle Daten nutzen bestehende WooCommerce-Tabellen und -Taxonomien.

| Entity | Field | Type | Created by | Notes |
|--------|-------|------|------------|-------|
| Product | `_spreadconnect_product_id` | `postmeta (TEXT)` | `update_meta_data()` | Demo-ID Format: `demo-{category}-{nnn}` |
| Product | Featured flag | `term_relationship` | `$product->set_featured(true)` | Setzt `product_visibility` Taxonomy-Term "featured" |
| Review | Rating | `commentmeta (INT)` | `update_comment_meta($id, 'rating', N)` | 3–5 Sterne |
| Review | Verified | `commentmeta (STRING)` | `update_comment_meta($id, 'verified', '0')` | Mock-Reviews = nicht verifiziert |
| Attachment | File path | `postmeta (TEXT)` | `wp_insert_attachment()` | Relative to uploads dir |

### Relationships

| From | To | Relationship | Mechanism |
|------|-----|--------------|-----------|
| Product | Category | N:M | `wp_set_object_terms()` / `set_category_ids()` |
| Product | Variation | 1:N | `set_parent_id()` on WC_Product_Variation |
| Product | Attachment (Image) | 1:N | `set_image_id()` (featured), `product_image_gallery` meta |
| Category | Attachment (Image) | 1:1 | `update_term_meta($cat_id, 'thumbnail_id', $attachment_id)` |
| Product | Review | 1:N | `comment_post_ID` |

---

## Server Logic

### Services & Processing

| Service | Responsibility | Input | Output | Side Effects |
|---------|----------------|-------|--------|--------------|
| `generate-images.mjs` | Bild-Generierung via Replicate API | Product catalog JSON, REPLICATE_API_KEY | WebP images in `wordpress/uploads/products/` | Filesystem writes, Replicate API calls |
| `seed-products.php` | WooCommerce data seeding | Product catalog (PHP array), image files | WP database entries | DB writes (products, categories, variations, reviews, attachments) |
| `mock-data.sh` | Orchestrator/wrapper, idempotency | — | — | Calls seed-products.php, sets WP option flag |

### Business Logic Flow

```
Developer Workflow:

1) generate-images.mjs
   Read product catalog → Build prompts (catalog + category template + motif)
     → Replicate API (batch, concurrent) → Poll predictions → Download WebP
       → Save to wordpress/uploads/products/{category-slug}/{product-slug}-{1|2}.webp

2) docker compose up -d → setup.sh → mock-data.sh → seed-products.php
   Check idempotency flag → Create parent categories → Create child categories
     → Create products (variable: size×color variations, simple: no variations)
       → Set featured flag on ~10 products
         → Import images as WP attachments → Assign to products/categories
           → Create mock reviews on featured products
             → Set idempotency flag
```

### Validation Rules

| Field | Rule | Error Handling |
|-------|------|----------------|
| `REPLICATE_API_KEY` | Must be set in env | Script exits with error message |
| Product slug | Must be unique | `get_page_by_path()` check before create (idempotent) |
| Category slug | Must be unique | `get_term_by('slug')` check before create (idempotent) |
| Image file | Should exist at expected path | Graceful degradation — product created without image |
| Replicate response | `status === 'succeeded'` | Retry on failure, skip on permanent error |

---

## Security

### Authentication & Authorization

| Area | Mechanism | Notes |
|------|-----------|-------|
| Replicate API | Bearer token via `REPLICATE_API_KEY` | Read from `.env` at root level, never committed |
| WP-CLI | `--allow-root` flag | Runs in Docker container with root access |
| Seed script | No auth needed | Local execution only, no external access |

### Data Protection

| Data Type | Protection | Notes |
|-----------|------------|-------|
| `REPLICATE_API_KEY` | `.env` file, `.gitignore`d | Already in `.env.example` as placeholder |
| Generated images | Committed to repo | Public product images, no sensitive data |

### Input Validation & Sanitization

| Input | Validation | Sanitization |
|-------|------------|--------------|
| Product names/descriptions | Handkuratiert (keine User-Eingabe) | WP sanitizes on save |
| Review content | Hardcoded mock data | WP sanitizes on save |
| Image file names | Script-generated slugs | Only alphanumeric + hyphens |

### Rate Limiting & Abuse Prevention

| Resource | Limit | Strategy | Fallback |
|----------|-------|----------|----------|
| Replicate API | Plan-dependent | Concurrency limit in script (max 5 parallel) | Exponential backoff on 429 |
| WP-CLI operations | N/A (local) | Sequential execution | N/A |

---

## Architecture Layers

### Layer Responsibilities

| Layer | Responsibility | Pattern |
|-------|----------------|---------|
| `scripts/generate-images.mjs` | Image generation orchestrator | Batch processor with concurrency control |
| `scripts/seed-products.php` | WooCommerce data seeder | Idempotent seed script (WP-CLI eval-file) |
| `scripts/mock-data.sh` | Wrapper with idempotency flag | Shell script, WP option flag check |
| `scripts/setup.sh` | Automated setup pipeline | Calls mock-data.sh at end |
| Product catalog data | Static product definitions | PHP array in separate file or inline |

### Data Flow

```
┌─────────────────────────────────────────────────────────────────┐
│ Phase 1: Image Generation (developer-triggered, one-time)       │
│                                                                 │
│ generate-images.mjs                                             │
│   ├── Read product catalog (110 products + 12 categories)       │
│   ├── Build prompts (motif + category template + style params)  │
│   ├── Replicate API (Flux 2 Pro, concurrent batch)              │
│   │   ├── POST /predictions (create)                            │
│   │   ├── GET /predictions/{id} (poll)                          │
│   │   └── Download output URL → WebP                            │
│   └── Save: wordpress/uploads/products/{cat-slug}/{slug}-N.webp │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ Phase 2: Docker Seed (automatic on docker compose up)           │
│                                                                 │
│ setup.sh → mock-data.sh → seed-products.php                    │
│   ├── Check idempotency (WP option: pod_shop_mock_data_seeded) │
│   ├── Create parent categories (3)                              │
│   ├── Create child categories (12) with descriptions            │
│   ├── Create products (110)                                     │
│   │   ├── Variable: WC_Product_Variable + Variations            │
│   │   └── Simple: WC_Product_Simple                             │
│   ├── Set featured (10 products): set_featured(true)            │
│   ├── Import images as WP attachments                           │
│   │   ├── wp_insert_attachment()                                │
│   │   ├── wp_generate_attachment_metadata()                     │
│   │   └── set_image_id() / set_gallery_image_ids()              │
│   ├── Assign category images: update_term_meta(thumbnail_id)    │
│   ├── Create reviews: wp_insert_comment() + rating meta         │
│   └── Set idempotency flag                                      │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│ Phase 3: Frontend Consumption (automatic)                       │
│                                                                 │
│ Next.js (existing, no changes needed)                           │
│   ├── GET_FEATURED_PRODUCTS (featured: true) → Homepage         │
│   ├── GET_PRODUCTS (categoryIn) → Category pages                │
│   ├── GET_PRODUCT (slug) → Product detail                       │
│   ├── GET_PRODUCT_CATEGORIES → Navigation                       │
│   └── image { sourceUrl, altText } → Product images             │
└─────────────────────────────────────────────────────────────────┘
```

### Error Handling Strategy

| Error Type | Handling | User Response | Logging |
|------------|----------|---------------|---------|
| Missing REPLICATE_API_KEY | Exit with message | "Set REPLICATE_API_KEY in .env" | Console error |
| Replicate 429 (rate limit) | Exponential backoff, max 3 retries | Progress bar shows retry | Console warning |
| Replicate prediction failed | Skip image, continue batch | Summary shows failed count | Console warning |
| Image download failed | Skip, continue | Product created without image | Console warning |
| WP product already exists | Skip (idempotent) | Log "already exists" | WP_CLI::log |
| WP category already exists | Skip (idempotent) | Log "already exists" | WP_CLI::log |
| Image file missing at seed | Product created without image | Graceful degradation | WP_CLI::warning |

---

## Migration Map

| Existing File | Current Pattern | Target Pattern | Specific Changes |
|---|---|---|---|
| `scripts/seed-products.php` | 3 hardcoded products, 2 flat categories, no images, no reviews, no featured | 110 products from catalog data, 3+12 category hierarchy, image import, reviews, featured flag | Refactor into modular structure: separate catalog data, add `pod_create_simple_product()`, add parent/child category creation, add image import helper, add review creation, add featured marking |
| `scripts/mock-data.sh` | Runs seed-products.php with idempotency flag `pod_shop_mock_data_seeded` | Same pattern, possibly reset flag for re-seeding | Add optional `--force` flag to re-run seed (reset flag before calling PHP) |
| `docker-compose.yml` | `wpcli` service has no `./wordpress/uploads` bind mount | `wpcli` service gets uploads bind mount | Add `./wordpress/uploads:/var/www/html/wp-content/uploads` to `wpcli` volumes (needed for image import in seed script) |
| `.env.example` | Contains `REPLICATE_API_KEY` with real key value | Contains `REPLICATE_API_KEY` with placeholder value | Replace real API key with placeholder `your_replicate_api_token_here` |

### New Files

| New File | Purpose | Notes |
|---|---|---|
| `scripts/generate-images.mjs` | Node.js script for Replicate Flux 2 Pro batch image generation | Standalone, runs locally before docker compose |
| `scripts/product-catalog.json` | JSON file with all 110 products + categories + motifs | Shared between generate-images.mjs (reads for prompts) and seed-products.php (reads via `json_decode(file_get_contents())`) |
| `wordpress/uploads/products/{cat-slug}/{slug}-1.webp` | Generated product images (2 per product) | Committed to repo, ~232 files |
| `wordpress/uploads/products/{cat-slug}/category-{slug}.webp` | Generated category images (1 per category) | Committed to repo, 12 files |

---

## Constraints & Integrations

### Constraints

| Constraint | Technical Implication | Solution |
|------------|----------------------|----------|
| Idempotency | Seed must be safe to run multiple times | WP option flag `pod_shop_mock_data_seeded` + per-product slug check |
| Existing seed pipeline | Must integrate with `setup.sh → mock-data.sh → seed-products.php` | Extend existing files, keep same flow |
| Docker volume mounts | `wordpress/uploads/` must be mounted in BOTH `wordpress` and `wpcli` containers | `wordpress` service already has bind mount; `wpcli` service needs `./wordpress/uploads:/var/www/html/wp-content/uploads` added (see Migration Map) |
| WooCommerce product types | Variable products need attribute + variation creation | Reuse existing `pod_create_variable_product()`, add `pod_create_simple_product()` |
| Bilingual attributes | `pa_groesse` / `pa_farbe` already exist | Reuse, no changes needed |
| Variable vs Simple products | Kleidung = Variable (Size+Color), some Accessoires = Variable (Color only), Wohnen/Simple items = Simple | Add `pod_create_color_only_variable_product()` for color-only variants |

### Integrations

| Area | System / Capability | Interface | Version | Notes |
|------|----------------------|-----------|---------|-------|
| Image Gen | Replicate — Flux 2 Pro | REST API (`api.replicate.com/v1`) | Flux 2 Pro model `black-forest-labs/flux-2-pro` | ~$0.015/image at 1MP |
| Node.js SDK | `replicate` npm package | JS SDK wrapping REST API | 1.4.0 (npm, Feb 2026) | Handles auth, polling, streaming |
| CMS | WordPress | WP-CLI `eval-file` | 6.9 (Docker image `wordpress:6.9-php8.2-apache`) | Seed runs inside wpcli container |
| E-Commerce | WooCommerce | PHP API (WC_Product, WC_Product_Variable, etc.) | Installed via `wp plugin install woocommerce` in setup.sh | Latest stable at install time |
| GraphQL | WPGraphQL + WooGraphQL | GraphQL endpoint `/graphql` | WPGraphQL: latest stable, WooGraphQL: v0.21.2 | Frontend consumes seeded data |
| Frontend | Next.js | Apollo Client queries | 16.x (package.json: `^16.1.6`) | No frontend changes needed |

---

## Quality Attributes (NFRs)

### From Discovery → Technical Solution

| Attribute | Target | Technical Approach | Measure / Verify |
|-----------|--------|--------------------|------------------|
| Idempotency | Seed safe to run N times, same result | WP option flag + per-entity slug/existence checks | Run `docker compose up -d` twice, verify no duplicates |
| Seed Performance | 110 products + variations in < 5 min | Sequential product creation (WP-CLI), no external API during seed | Time `mock-data.sh` execution |
| Image Gen Performance | 232 images in reasonable time | Concurrent Replicate calls (max 5 parallel), progress display | Monitor script runtime, expect ~15-30 min |
| Data Quality | Products look realistic in frontend | Handkuratierte Namen/Beschreibungen, category-specific prompts | Visual review in browser |
| Graceful Degradation | Missing images don't break seed | Check file existence before import, skip if missing | Seed without images → products still created |
| Disk Space | Committed images manageable | WebP format, 1MP resolution (~50-150KB per image) | ~232 images × ~100KB ≈ ~23MB total |

### Monitoring & Observability

| Metric | Type | Target | Alert |
|--------|------|--------|-------|
| `generate-images.mjs` progress | Console output | Shows X/232 completed | Logs failures per image |
| `seed-products.php` progress | WP_CLI::log/success | Shows each product created | WP_CLI::warning on image import failure |

---

## Risks & Assumptions

### Assumptions

| Assumption | Technical Validation | Impact if Wrong |
|------------|---------------------|-----------------|
| Replicate Flux 2 Pro available and stable | API health check at script start | Use Flux 1.1 Pro as fallback model |
| WebP images displayable in all target browsers | WebP has >97% browser support | Convert to JPEG as fallback |
| `wordpress/uploads/products/` accessible in Docker | Volume mount `./wordpress/uploads:/var/www/html/wp-content/uploads/` exists | Script fails gracefully, products created without images |
| WooCommerce `set_featured(true)` sets `product_visibility` taxonomy correctly | Existing `GET_FEATURED_PRODUCTS` query uses `featured: true` filter | Featured section remains empty, manual fix via WP admin |
| `wp_insert_comment()` with `rating` meta creates valid WooCommerce reviews | WooCommerce uses comments + rating meta for reviews | Reviews don't show in frontend, manual fix needed |
| ~23MB of images acceptable for git repo | Standard for small projects | Use Git LFS if repo becomes too large |

### Risks & Mitigation

| Risk | Likelihood | Impact | Technical Mitigation | Fallback |
|------|------------|--------|---------------------|----------|
| Replicate API rate limiting | Medium | Low | Max 5 concurrent, exponential backoff | Reduce concurrency, run in batches |
| Replicate API key invalid/expired | Low | High | Validate key at script start | Clear error message, abort early |
| Generated images low quality | Medium | Medium | Optimized per-category prompts based on reference prompts | Manual re-generation of specific images |
| Seed timeout in Docker | Low | Medium | Sequential creation, no external API calls during seed | Increase Docker healthcheck timeouts |
| WooCommerce image import fails (GD/Imagick missing) | Low | Medium | Docker WordPress image includes GD | Use `wp media import` CLI command instead |
| Product catalog data errors | Low | Low | JSON file with clear structure, linted | Fix catalog data and re-run |
| WooCommerce version unpinned (pre-existing) | Low | Medium | `setup.sh` uses `wp plugin install woocommerce` without `--version` flag | Pin WC version in future infrastructure update; seed script uses stable WC APIs unlikely to break |

---

## Technology Decisions

### Stack Choices

| Area | Technology | Rationale |
|------|------------|-----------|
| Image generation | Replicate Flux 2 Pro via `replicate` npm SDK | Already have API key, best price/quality ratio, ~$0.015/image |
| Image format | WebP | Modern format, ~30% smaller than JPEG, >97% browser support |
| Product catalog | PHP array in separate file | Matches existing PHP seed script, no build step needed |
| Image gen script | Node.js (ESM) | Replicate SDK is JS-native, async/await for concurrent API calls |
| Image storage | Committed to `wordpress/uploads/products/` | Simple, accessible in Docker via volume mount, no external storage needed |

### Trade-offs

| Decision | Pro | Con | Mitigation |
|----------|-----|-----|------------|
| Commit images to git | Simple, no external dependency, works offline | Increases repo size by ~23MB | WebP compression, Git LFS if needed later |
| Separate generate + seed scripts | Image gen is one-time, seed runs on every `docker compose up` | Two-step developer workflow | Clear documentation in README |
| JSON for shared product catalog | Both scripts (PHP + Node.js) can read same file | JSON parsing overhead in PHP (negligible) | `json_decode(file_get_contents())` in PHP, native `import` or `JSON.parse` in Node.js |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| – | Keine offenen Fragen | – | – | – |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-27 | Codebase | `seed-products.php` — 3 products, 2 categories, `pod_create_variable_product()` with 5×4 variations, legal pages |
| 2026-02-27 | Codebase | `mock-data.sh` — idempotency via `pod_shop_mock_data_seeded` WP option |
| 2026-02-27 | Codebase | `setup.sh` — full pipeline: WP install → plugins → WC config → mock data |
| 2026-02-27 | Codebase | No `set_featured()`, no `wp_insert_comment()`, no image import in existing seed |
| 2026-02-27 | Codebase | Frontend queries: `GET_FEATURED_PRODUCTS` uses `featured: true`, images via `sourceUrl` field |
| 2026-02-27 | Codebase | Docker: `wordpress/uploads/` volume-mounted to `/var/www/html/wp-content/uploads/` |
| 2026-02-27 | Codebase | Existing attributes: `pa_groesse` (S,M,L,XL,XXL) + `pa_farbe` (Schwarz,Weiß,Grau,Navy) |
| 2026-02-27 | Web | Replicate `replicate` npm package: v1.4.0 (latest, Feb 2026) |
| 2026-02-27 | Web | Flux 2 Pro pricing: ~$0.015 per 1MP image on Replicate |
| 2026-02-27 | Web | WordPress 6.9 Docker image used (`wordpress:6.9-php8.2-apache`) |
| 2026-02-27 | Web | WooGraphQL v0.21.2 installed from GitHub release |
| 2026-02-27 | Codebase | `seed-data-prompts/prompts.md` — 3 reference prompts showing target style (photorealistic, lifestyle, specific crops, camera params) |
| 2026-02-27 | Codebase | `.env.example` already contains `REPLICATE_API_KEY` placeholder |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| – | Architecture erstellt ohne Q&A (Discovery war vollständig, keine offenen Fragen) | – |
