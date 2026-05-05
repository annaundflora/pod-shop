# Slim Spec: Spreadconnect Hub — CSS Layout (Late-Add)

**Discovery:** specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/discovery.md (context only — describes the v2 API work, NOT this CSS work)
**Architecture:** specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/architecture.md (Section "Adapter — Admin Page", Z. 529)
**Wireframes:** specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/wireframes.md (Screens 1-8 — the visual layout the CSS must realise)

---

## Scope

The 47-slice "v2 API coverage" feature shipped without a stylesheet. Hub view classes
(`Hub\View\Dashboard|Sidebar|Settings|Catalog|Webhooks|FailedOps|Logs|Subscriptions`)
emit BEM-prefixed class hooks but no CSS rules and no `wp_enqueue_style` call were ever
written, so the browser renders raw HTML with no layout. This spec adds the missing CSS
file and a screen-gated enqueue adapter — no view-class markup is touched, no JS changes.

**One slice only:** `slice-css-hub-layout`.

---

## Test-Strategy

| Key | Value |
|-----|-------|
| **Stack** | PHP 8.2 / WordPress plugin (PHPUnit 11 + Brain\Monkey) |
| **Test Command** | `docker run --rm -v /home/dev/pod-shop/.claude/worktrees/7-spreadconnect-pod-plugin-v2-full-api-coverage:/app -w /app php:8.2-cli vendor/bin/phpunit --filter SliceCssHubLayoutTest` |
| **E2E Command** | -- (visual rendering is manual QA, not testable in PHPUnit) |
| **Start Command** | `docker compose up -d` (WordPress on :8080) |
| **Health Endpoint** | `http://localhost:8080/wp-admin/admin.php?page=spreadconnect` |
| **Mocking Strategy** | `mock_external` — Brain\Monkey for `add_action`, `wp_enqueue_style`, `plugins_url`, `current_screen`, `WP_Screen` stub |

---

## Slice css-hub-layout: Add CSS layout + enqueue adapter to the Spreadconnect Hub

**Goal:** Ship a single stylesheet that turns the existing BEM-prefixed Hub markup into the
8-screen layout from `wireframes.md`, plus a screen-gated `Hub\Assets` enqueue adapter that
loads the file only on the Spreadconnect hub page (`woocommerce_page_spreadconnect`).
**Depends on:** -- (purely additive; no view-class or controller changes)

### Acceptance Criteria

- **AC-1 (CSS file present at canonical path):**
  GIVEN the plugin is installed at `wordpress/plugins/spreadconnect-pod/`
  WHEN a developer or test inspects the assets directory
  THEN the file `wordpress/plugins/spreadconnect-pod/assets/css/spreadconnect-hub.css` exists,
  is non-empty (> 500 bytes), and parses without syntax errors when validated by a CSS parser
  (or, in PHPUnit, when `file_get_contents()` returns a non-false string of length > 500).

- **AC-2 (Enqueue adapter registered on `admin_enqueue_scripts`):**
  GIVEN `Bootstrap\Plugin::init( $plugin_file )` has executed
  WHEN the test calls `has_action( 'admin_enqueue_scripts', [ \SpreadconnectPod\Hub\Assets::class, 'enqueue' ] )`
  THEN the return value is the integer `10` (default WP priority), proving the adapter is
  hooked exactly once from `Bootstrap\Plugin::init()`.

- **AC-3 (Screen-gating positive case — enqueued on the Hub page):**
  GIVEN the current admin screen has `id === 'woocommerce_page_spreadconnect'`
  (i.e. the `$hook_suffix` argument passed to `admin_enqueue_scripts` equals
  `'woocommerce_page_spreadconnect'`)
  WHEN `\SpreadconnectPod\Hub\Assets::enqueue( 'woocommerce_page_spreadconnect' )` runs
  THEN exactly one `wp_enqueue_style()` call is recorded with handle
  `'spreadconnect-hub'` and a source URL ending in `assets/css/spreadconnect-hub.css`.

- **AC-4 (Screen-gating negative case — NOT enqueued elsewhere):**
  GIVEN the current admin screen is anything other than the hub page
  (e.g. `'index.php'`, `'edit.php'`, `'woocommerce_page_wc-orders'`, `'plugins.php'`,
  `'post.php'`)
  WHEN `\SpreadconnectPod\Hub\Assets::enqueue( $hook_suffix )` runs for each of those values
  THEN zero `wp_enqueue_style()` calls are recorded — the adapter must early-return on any
  hook suffix that is not exactly `'woocommerce_page_spreadconnect'`.

- **AC-5 (Cache-busting version param):**
  GIVEN the CSS file exists on disk with a known mtime
  WHEN `wp_enqueue_style()` is invoked by `Hub\Assets::enqueue` on the hub page
  THEN the 4th argument (`$ver`) is a non-empty string equal to either
  `(string) filemtime( $css_path )` OR a plugin-version constant pulled from the main plugin
  file header (e.g. `'2.0.0'`); it is NEVER `false`, `null`, the empty string, or the WP
  default (which would suppress cache-busting).

- **AC-6 (BEM class coverage in the stylesheet):**
  GIVEN the stylesheet at `assets/css/spreadconnect-hub.css`
  WHEN the test reads the file with `file_get_contents()` and searches for selector substrings
  THEN the file contains rule blocks (i.e. `{ … }` after the selector) for ALL of the
  following BEM hooks already emitted by the Hub views:
  `.spreadconnect-hub`, `.spreadconnect-hub__content`, `.spreadconnect-hub__title`,
  `.spreadconnect-hub-nav`, `.spreadconnect-hub-nav__list`, `.spreadconnect-hub-nav__item`,
  `.spreadconnect-hub-nav__item.is-active`, `.spreadconnect-hub-nav__link`,
  `.spreadconnect-card`, `.spreadconnect-card__title`, `.spreadconnect-card__status`,
  `.spreadconnect-dashboard__cards` (grid container),
  `.spreadconnect-catalog__progress-bar`, `.spreadconnect-webhook-log__hmac.is-valid`,
  `.spreadconnect-webhook-log__hmac.is-invalid`,
  `.spreadconnect-failed-ops__modal`, `.spreadconnect-failed-ops__modal-backdrop`,
  `.spreadconnect-logs__filters`, `.spreadconnect-subscriptions__error-banner`,
  `.spreadconnect-step-result--success`, `.spreadconnect-step-result--partial`,
  `.spreadconnect-reveal-panel`, `.spreadconnect-reveal-panel__secret`.

- **AC-7 (No override of WP-Admin native classes):**
  GIVEN WP-Admin's native `.notice`, `.notice-error`, `.notice-warning`, `.notice-success`,
  `.notice-info`, `.button`, `.button-primary`, `.button-secondary`, `.form-table`, and
  `.wrap` rules must keep their core appearance
  WHEN the test scans the stylesheet for top-level rule blocks targeting any of those
  selectors as a standalone (non-descendant) rule
  THEN ZERO matches are found — these selectors may only appear when scoped inside a
  `.spreadconnect-hub …` ancestor (e.g. `.spreadconnect-hub .notice { margin-top: … }` is
  allowed; `.notice { background: … }` is forbidden), so the plugin never re-paints
  WordPress core notice/button/form chrome.

- **AC-8 (Responsive breakpoint at WP-Admin's mobile cutoff):**
  GIVEN WP-Admin collapses its own chrome at viewport width 782 px
  WHEN the test searches the stylesheet for media queries
  THEN the file contains at least one `@media (max-width: 782px)` (or `(max-width: 783px)`)
  block that re-flows the sidebar+content layout — verified by asserting the media-query
  body contains a rule for both `.spreadconnect-hub` and `.spreadconnect-hub-nav` (i.e. the
  two-column layout collapses to a single column on mobile).

### Deliverables

- [ ] `wordpress/plugins/spreadconnect-pod/assets/css/spreadconnect-hub.css` -- new stylesheet implementing the 8-screen layout from `wireframes.md` against the existing BEM hooks listed in AC-6
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Assets.php` -- new `final class Assets` (PSR-4 `SpreadconnectPod\Hub\Assets`) with `public static function enqueue( string $hook_suffix ): void` matching the slice-13 stateless-static "Adapter — Admin Page" pattern
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` -- one-line addition: `add_action( 'admin_enqueue_scripts', [ HubAssets::class, 'enqueue' ] );` inside the existing `init()` body (next to the slice-13 `admin_menu` hook), plus `use SpreadconnectPod\Hub\Assets as HubAssets;` import
- [ ] `tests/slices/pod-shop-mvp/SliceCssHubLayoutTest.php` -- new PHPUnit test class covering AC-1 through AC-8; uses Brain\Monkey to spy on `add_action` / `wp_enqueue_style` / `plugins_url`; reads the CSS file with `file_get_contents()` for AC-1, AC-6, AC-7, AC-8
