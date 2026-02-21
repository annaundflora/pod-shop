# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

POD Shop MVP — a headless WooCommerce + Next.js e-commerce shop for print-on-demand products fulfilled by Spreadshirt, with Pinterest tracking and Mollie payments. All user-facing UI is in German.

## Commands

### Backend (Docker — WordPress + WooCommerce)
```bash
docker compose up -d          # Start all services (MySQL, WordPress :8080, phpMyAdmin :8180)
docker compose down            # Stop services
docker compose logs wordpress  # View WP logs
```
The WP-CLI container runs `scripts/setup.sh` automatically on first boot (idempotent).

### Frontend (Next.js — in `frontend/`)
```bash
cd frontend
pnpm install                   # Install dependencies
pnpm dev                       # Dev server on :3000
pnpm build                     # Production build
pnpm test                      # Run all Vitest tests
npx vitest run tests/slices/pod-shop-mvp/slice-02  # Run a single test file (prefix match)
```

### PHP Tests (root level)
```bash
composer test                  # PHPUnit — runs tests/slices/pod-shop-mvp/
```

Plugin-level tests:
```bash
cd wordpress/plugins/spreadconnect-pod && vendor/bin/phpunit
cd wordpress/plugins/pinterest-capi && vendor/bin/phpunit
```

## Architecture

```
Browser → Next.js (localhost:3000)
            ↓ GraphQL (Apollo Client)
          WordPress (localhost:8080)
            ├── WPGraphQL + WooGraphQL
            ├── WooCommerce (cart, orders, payments via Mollie)
            ├── spreadconnect-pod plugin → Spreadshirt API (fulfillment)
            └── pinterest-capi plugin → Pinterest Conversions API v5
```

**Headless pattern:** WordPress is backend-only. The `headless-redirect.php` mu-plugin 301-redirects all frontend traffic to Next.js. The `cors-graphql.php` mu-plugin handles CORS for GraphQL.

**Two Apollo clients:** `lib/apollo/server-client.ts` for RSC (no session, `React.cache()` deduplication) and `lib/apollo/client.ts` for client components (with session token chain via `authLink` + `sessionLink`).

**WooCommerce session flow:** Token stored in localStorage (`woo-session-token`), injected as `woocommerce-session: Session <token>` header. On checkout, user is redirected to WP's checkout page with session token and Pinterest `event_id` as URL params.

**Cart resilience:** `CartProvider` keeps a localStorage backup (`pod-cart-backup`) in case the WooCommerce session is lost.

## Key Conventions

- **Next.js 16 App Router** with TypeScript strict mode, Tailwind CSS v4 (CSS-first config via `@theme` in `globals.css`)
- **Path alias:** `@/*` maps to `frontend/*`
- **Suspense + skeleton pattern:** All async RSC sections wrapped in `<Suspense fallback={<Skeleton />}>`
- **ISR on homepage:** `revalidate = 60` for featured products and categories
- **Mobile-first:** `--min-touch-target: 2.75rem` (44px), `touch-action: manipulation` — Pinterest traffic is predominantly mobile
- **Bilingual WooCommerce attributes:** `variant-utils.ts` handles both German (`pa_groesse`, `pa_farbe`) and English (`pa_size`, `pa_color`) attribute names
- **Pinterest consent gate:** All `pintrk()` calls check `localStorage['cookie-consent'] === 'accepted'` before firing; `event_id` used for client/server deduplication
- **Slice-based organization:** Both frontend (Vitest) and PHP (PHPUnit) tests are organized in numbered slice files matching the 7 implementation slices documented in `docs/features/pod-shop-mvp/slices/`
- **Custom WP plugins use PSR-4:** `SpreadconnectPod\` namespace autoloaded from `wordpress/plugins/spreadconnect-pod/includes/`
- **Spreadconnect API client:** 3x retry with exponential backoff + HTTP 429 rate-limit handling

## Environment Setup

Copy `.env.example` → `.env` (root) and `frontend/.env.local.example` → `frontend/.env.local`. Key variables:
- Root: MySQL creds, `WP_HOME`, WP admin creds
- Frontend: `NEXT_PUBLIC_GRAPHQL_URL`, `NEXT_PUBLIC_WP_URL`, `NEXT_PUBLIC_WC_CHECKOUT_URL`, `NEXT_PUBLIC_PINTEREST_TAG_ID`

## Route Slugs (German)

- `/kategorie/[slug]` — Category page
- `/produkt/[slug]` — Product detail page
- `/warenkorb` — Cart page
