# Gate 1: Architecture Compliance Report (Re-Check)

**Gepruefte Architecture:** `specs/phase-0/2026-02-24-component-primitive-migration/architecture.md`
**Pruefdatum:** 2026-02-24
**Discovery:** `specs/phase-0/2026-02-24-component-primitive-migration/discovery.md`
**Wireframes:** Nicht vorhanden (reines Refactoring ohne UI-Aenderung -- laut Discovery bestaetigt)
**Vorheriger Check:** 2026-02-24 -- 3 BLOCKING Issues (Dependency Pinning)
**Dieser Check:** Re-Check nach Architecture-Fix

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 22 |
| WARNING | 1 |
| BLOCKING | 0 |

**Verdict:** APPROVED

---

## Previous Blocking Issues -- Resolution Status

| # | Previous Issue | Resolution in Architecture | Verified | Status |
|---|---------------|---------------------------|----------|--------|
| 1 | `@radix-ui/react-slot` = `"latest"` in package.json | Migration Map Slice 1 Row 1: "Pin alle 3 `latest` Dependencies auf aktuell installierte Versionen" + Constraints: "Dependency Pinning" mit `^1.2.4` | Architecture mandates pinning to `^1.2.4`. Integrations table documents `^1.2.4`. | RESOLVED |
| 2 | `@radix-ui/react-dialog` = `"latest"` in package.json | Migration Map Slice 1 Row 1: same row + Constraints: `^1.1.15` | Architecture mandates pinning to `^1.1.15`. Integrations table documents `^1.1.15`. | RESOLVED |
| 3 | `tw-animate-css` = `"latest"` in package.json | Migration Map Slice 1 Row 1: "resolved Version" + Constraints: "Dependency Pinning" | Architecture mandates pinning to resolved version. | RESOLVED |

**Verification Detail:**

Architecture Line 166 (Migration Map, first row):
> `frontend/package.json` | `@radix-ui/react-slot: "latest"`, `@radix-ui/react-dialog: "latest"`, `tw-animate-css: "latest"` | Gepinnte Versionen: `^1.2.4`, `^1.1.15`, resolved Version | Pin alle 3 "latest" Dependencies auf aktuell installierte Versionen. Verhindert Breaking Changes bei `pnpm install`. Erster Schritt vor jeder Component-Migration.

Architecture Line 211 (Constraints):
> Dependency Pinning | 3 Packages (`@radix-ui/react-slot`, `@radix-ui/react-dialog`, `tw-animate-css`) nutzen `"latest"` in package.json | Vor Migration pinnen auf aktuell installierte Versionen: `^1.2.4`, `^1.1.15`, resolved Version von tw-animate-css.

**npm verification (2026-02-24):**
- `@radix-ui/react-slot` latest on npm: 1.2.4 -- matches Architecture `^1.2.4`
- `@radix-ui/react-dialog` latest on npm: 1.1.15 -- matches Architecture `^1.1.15`
- `tw-animate-css` latest on npm: 1.4.0 -- lockfile confirms 1.4.0 installed

All 3 previous blocking issues are resolved in the Architecture. The pinning step is documented as the first row of Migration Map Slice 1 (prerequisite before component migration begins).

---

## A) Feature Mapping

| Discovery Feature | Architecture Section | Migration Map | Tests Addressed | Status |
|-------------------|---------------------|---------------|-----------------|--------|
| Button Composition (11 Patterns) | Scope, Composition Architecture, Migration Map | 11 Patterns in 9 Dateien (add-to-cart, checkout, quantity-stepper x2, remove-item, size-selector, hamburger, error-close, gallery-thumbnails) | Ja -- "Vitest Tests: Variant-Rendering, Disabled-State, ARIA-Attribute" | PASS |
| Button-als-Link Composition (4 Patterns) | Scope, Migration Map | 4 Patterns in 3 Dateien (hero CTA, empty-cart CTA, filter-chips aktiv/inaktiv) | Ja | PASS |
| Card Composition (5 Patterns) | Scope, Migration Map | 5 Patterns in 5 Dateien (product-card, cart-item-row, cart-summary, category-showcase, produkt-beschreibung) | Ja | PASS |
| Badge Composition (1 Pattern) | Scope, Migration Map | 1 Pattern (cart-icon) | Ja | PASS |
| Accessibility-Audit | Scope, Security Section | ARIA Labels, role Attribute, Keyboard Nav pro Component dokumentiert | Ja -- "mind. 1 Test pro migriertem Pattern" | PASS |
| Vitest Tests (bestehende anpassen) | Scope, Error Handling | "Test-Selector anpassen (data-testid, role, data-slot)" | Ja | PASS |
| Vitest Tests (neue schreiben) | Scope | "Vitest Tests: Variant-Rendering, Disabled-State, ARIA-Attribute" | Ja | PASS |
| Storybook 10 Setup | Scope, Stack Choices, Migration Map Slice 2 | `.storybook/main.ts`, `.storybook/preview.ts`, package.json Scripts | Ja | PASS |
| Stories: shadcn/ui Primitives (7) | Scope, Migration Map Slice 2 | `stories/ui/` -- Button, Card, Input, Badge, Skeleton, Dialog, Sheet | Ja | PASS |
| Stories: Layout Components (5) | Scope, Migration Map Slice 2 | `stories/layout/` -- Header, Footer, MobileMenu, CartIcon, CookieConsentBanner | Ja | PASS |
| Stories: Product Components (6) | Scope, Migration Map Slice 2 | `stories/product/` -- ProductCard, ProductCardSkeleton, AddToCartButton, ImageGallery, VariantSelectorSize, VariantSelectorColor | Ja | PASS |
| Stories: Cart Components (4) | Scope, Migration Map Slice 2 | `stories/cart/` -- CartItemRow, CartSummary, QuantityStepper, EmptyCart | Ja | PASS |
| Stories: Category Component (1) | Scope, Migration Map Slice 2 | `stories/category/` -- FilterChips | Ja | PASS |
| Stories: Block Components (4) | Scope, Migration Map Slice 2 | `stories/blocks/` -- HeroBlock, ProductGridBlock, CategoryShowcaseBlock, UspBarBlock | Ja | PASS |
| Out of Scope: variant-selector-color.tsx | Scope, Out of Scope | Explizit ausgeschlossen -- korrekt | -- | PASS |
| Out of Scope: Gallery-Dots | Scope, Out of Scope | Explizit ausgeschlossen -- korrekt | -- | PASS |
| Out of Scope: Footer-Links | Scope, Out of Scope | Explizit ausgeschlossen -- korrekt | -- | PASS |
| Out of Scope: Keine neuen Variants | Scope, Out of Scope | Explizit ausgeschlossen -- korrekt | -- | PASS |

---

## B) Constraint Mapping

| Constraint | Source | Architecture | Status |
|------------|--------|--------------|--------|
| Visuell identisch nach Migration | Discovery: Business Rules | Constraints: "Button CVA Variants matchen bereits"; NFRs: "Visual Regression: 0 sichtbare Aenderungen" | PASS |
| 44px Touch Targets | Discovery: Business Rules | Constraints: "shadcn/ui Button size=default = 44px, size=icon = 44px"; QuantityStepper 32px dokumentiert als Ausnahme | PASS |
| asChild Single-Child Regel | Discovery: Error Paths | Constraints: "Radix Slot akzeptiert nur exakt 1 Kind-Element -- verifiziert in Codebase" | PASS |
| Gallery-Dots Ausnahme (10px) | Discovery: Business Rules | Out of Scope -- korrekt | PASS |
| Filter-Chips rounded-full Override | Discovery: UI Patterns | Constraints: "className Override: rounded-full -- tailwind-merge ueberschreibt CVA-Base" | PASS |
| Product Card group CSS Klasse | Discovery: UI Patterns (hover scale) | Constraints: "group muss via className auf Card/Link bleiben" | PASS |
| Cart Badge kompakte Sizing | Discovery: UI Components | Constraints: "className Override fuer kompakte Sizing" | PASS |
| Keine neuen Variants | Discovery: Business Rules | Scope: "Neue shadcn/ui Variants" in Out of Scope | PASS |
| Test-Stabilitaet (151+ Tests gruen) | Discovery: Business Rules | NFRs: "Alle 151+ Tests gruen nach Migration" | PASS |
| Accessibility-Mindeststandard | Discovery: Business Rules | Security Section: Radix ARIA + manuelle Ergaenzungen dokumentiert | PASS |
| Storybook Theme Tokens | Discovery: Business Rules | Migration Map: "preview.ts importiert globals.css + generated-theme.css" | PASS |
| Storybook Mock Data | Discovery: Business Rules | Stack Choices: "Static Props + Context Decorators"; Risks: "CartContext-Mocking" | PASS |
| Storybook Story-Titel Pattern | Discovery: Business Rules | Stack Choices: "dedizierter stories/ Ordner" -- Ordnerstruktur folgt Category/Component Pattern | PASS |
| Card asChild Support fehlt | Discovery: UI Components (product-card, category-showcase) | Risks: "Card hat kein asChild Support -- High Likelihood"; Mitigation: "Card-Primitive erweitern: Slot Import + asChild Prop" | PASS |
| Storybook ESM-Only | Architecture: Constraints | "Node.js 20.16+ required -- Projekt nutzt Node.js 22.x" | PASS |
| Dependency Pinning (NEU) | Previous Compliance Check | Constraints: "Dependency Pinning" + Migration Map Row 1: pin 3 `"latest"` deps | PASS |

---

## C) Realistic Data Check

### Codebase Evidence

Dieses Feature betrifft keine Datenbank-Typen. Relevante "Datentypen" sind:
- **Component Props:** TypeScript Interfaces (compile-time, kein Runtime-Risiko)
- **CSS Custom Properties:** `var(--radius-button)`, `var(--shadow-card)` etc. (String-Werte, keine Laengenbeschraenkung)
- **className Strings:** Tailwind-Klassen via `cn()` (String-Concatenation, keine Laengenbeschraenkung)
- **CVA Variant Enums:** Endliche String-Unions (type-safe via TypeScript)

```
# Existierende Patterns in components/ui/:
button.tsx:  6 Variants (default, destructive, outline, ghost, link, secondary)
             4 Sizes (default, sm, lg, icon)
             Props: asChild (boolean), isLoading (boolean)
card.tsx:    2 Variants (default, interactive)
             6 Compound Components (Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter)
             KEIN asChild Support (nutzt <div>, nicht Radix Slot)
badge.tsx:   4 Variants (default, secondary, destructive, outline)
             Render-Element: <span>
```

### External API Analysis

N/A -- Reines Frontend-Refactoring ohne externe API-Aufrufe.

### Data Type Verdicts

| Field / Prop | Arch Type | Evidence | Verdict | Issue |
|--------------|-----------|----------|---------|-------|
| Button `variant` | CVA String Union (6 Werte) | Codebase: `button.tsx` -- alle 6 Variants vorhanden | PASS | -- |
| Button `size` | CVA String Union (4 Werte) | Codebase: `button.tsx` -- alle 4 Sizes vorhanden | PASS | -- |
| Button `isLoading` | boolean Prop | Codebase: `button.tsx` -- `isLoading?: boolean` | PASS | -- |
| Button `asChild` | boolean Prop | Codebase: `button.tsx` -- `asChild?: boolean`, Radix Slot Import | PASS | -- |
| Card `variant` | CVA String Union (2 Werte) | Codebase: `card.tsx` -- `default`, `interactive` | PASS | -- |
| Card `asChild` | FEHLT | Codebase: `card.tsx` -- kein `asChild` Prop, kein Slot Import | PASS (als Risk erkannt) | Architecture dokumentiert Risk + Mitigation korrekt |
| Badge `variant` | CVA String Union (4 Werte) | Codebase: `badge.tsx` -- alle 4 Variants vorhanden | PASS | -- |
| className Override via `cn()` | String (tailwind-merge) | Codebase: `tailwind-merge@^3.5.0` installiert | PASS | -- |
| QuantityStepper Button Size | `w-8 h-8` className Override | Button default ist 44px, Override auf 32px dokumentiert | PASS | -- |
| Gallery Thumbnail Size | `w-18 h-18` className Override | Button icon ist 44px, Override auf 72px dokumentiert | PASS | -- |
| Badge compact Sizing | className Override fuer `min-w-[1.125rem] h-[1.125rem]` | Badge default hat `px-2.5 py-0.5`, Override dokumentiert | PASS | -- |

---

## D) External Dependencies

### D1) Dependency Version Check

**Projekt-Typ:** Existing Project (package.json vorhanden)

| Dependency | Arch Version | Pinning File | Pinned? | "Latest"? | Actual Latest (npm) | Status |
|------------|-------------|--------------|---------|-----------|---------------------|--------|
| shadcn/ui (Button, Card, Badge) | "Keine eigene Version (Copy-Paste)" | N/A (lokale Dateien) | N/A | N/A | N/A | PASS |
| @radix-ui/react-slot | ^1.2.4 (Arch mandates pinning) | package.json (currently "latest") | Arch mandates fix | Currently "latest" | 1.2.4 | PASS -- Arch mandates pinning in Slice 1, Step 1 |
| @radix-ui/react-dialog | ^1.1.15 (Arch mandates pinning) | package.json (currently "latest") | Arch mandates fix | Currently "latest" | 1.1.15 | PASS -- Arch mandates pinning in Slice 1, Step 1 |
| tw-animate-css | resolved version (Arch mandates pinning) | package.json (currently "latest") | Arch mandates fix | Currently "latest" | 1.4.0 | PASS -- Arch mandates pinning in Slice 1, Step 1 |
| class-variance-authority | ^0.7.0 | package.json | JA | Nein | 0.7.x | PASS |
| tailwind-merge | ^3.5.0 | package.json | JA | Nein | 3.x | PASS |
| clsx | ^2.0.0 | package.json | JA | Nein | 2.x | PASS |
| storybook | 10.2.x | NEU (Slice 2) | N/A (neu) | Nein | 10.2.12 | PASS |
| @storybook/nextjs-vite | 10.2.x | NEU (Slice 2) | N/A (neu) | Nein | 10.2.12 | PASS |
| @storybook/react | 10.2.x | NEU (Slice 2) | N/A (neu) | Nein | 10.2.12 | PASS |
| @storybook/addon-essentials | 10.2.x (Arch claim) | NEU (Slice 2) | N/A (neu) | Nein | 8.6.14 (latest on npm) | WARNING -- see below |
| Next.js | ^16.1.6 | package.json | JA | Nein | 16.x | PASS |
| Vitest | ^3.0.0 | package.json | JA | Nein | 3.x | PASS |
| Tailwind CSS | ^4.0.0 | package.json | JA | Nein | 4.x | PASS |

### D2) External APIs & Services

N/A -- Reines Frontend-Refactoring ohne externe API-Aufrufe oder Services.

---

## E) Migration Map Completeness Check

**Trigger:** Scope enthaelt Migration ("Composition Pattern: Custom Components aus shadcn/ui Primitives zusammenbauen").

| Check | Result | Status |
|-------|--------|--------|
| Migration Map Section vorhanden? | Ja -- "Migration Map" Section mit Slice 1 + Slice 2 | PASS |
| Dateien statt Verzeichnisse? | Ja -- Slice 1: 16 Zeilen (1x package.json + 15 Component-Dateien), Slice 2: 8 Zeilen (2 Config + 6 Story-Verzeichnisse) | PASS |
| Dependency-Pinning als erster Schritt? | Ja -- `frontend/package.json` ist Row 1 in Slice 1: "Erster Schritt vor jeder Component-Migration" | PASS |
| Anzahl Zeilen Slice 1 vs. Discovery | Discovery: 15 UI-Patterns in 13 Dateien. Migration Map: 15 Component-Dateien + 1 package.json = 16 Zeilen. Unique Component-Dateien = 13 (einige Dateien haben mehrere Patterns: cart-summary 2x, quantity-stepper 2x, filter-chips 2x). Korrekt. | PASS |
| Anzahl Zeilen Slice 2 vs. Discovery | Discovery: Storybook Setup + 7 Story-Kategorien. Migration Map: 8 Zeilen (2 Config + 6 Story-Verzeichnisse). | PASS |
| Target Pattern konkret genug? | Ja -- jede Zeile hat spezifisches Target (z.B. `<Button variant="ghost" size="icon">`, `<Card variant="interactive" asChild>`, gepinnte Versionen `^1.2.4`) | PASS |
| Specific Changes dokumentiert? | Ja -- jede Zeile hat "Specific Changes" Spalte mit konkreten Import-Anweisungen und Entfernungs-Anweisungen | PASS |
| Alle Migration-Dateien existieren? | Ja -- alle 15 Component-Dateien verifiziert im Filesystem (ls -1 erfolgreich) | PASS |

---

## Warnings

### Warning 1: @storybook/addon-essentials Version Discrepancy

**Category:** Dependency
**Severity:** WARNING (nicht BLOCKING)

**Architecture says:**
> `@storybook/addon-essentials` | 10.2.x

**Evidence:**
```
npm view @storybook/addon-essentials version  =>  8.6.14
npm view @storybook/addon-essentials dist-tags =>  latest: 8.6.14, v8: 8.6.17
```
Es existiert KEINE 10.x Version von `@storybook/addon-essentials` auf npm. Die hoechste Version ist 8.6.14 (latest) bzw. 9.0.0-alpha.12 (next).

**Warum nur WARNING und nicht BLOCKING:**
Storybook 10 hat die Package-Struktur umgebaut. Der `storybook` Core (10.2.12) und `@storybook/nextjs-vite` (10.2.12) existieren als 10.x. Die Storybook 10 Install-Dokumentation empfiehlt `npm create storybook@latest` das die korrekten Packages automatisch installiert. Es ist moeglich, dass Essentials in Storybook 10 in den Core integriert wurden oder unter anderem Package-Namen laufen.

**Empfehlung:**
Architecture sollte die `@storybook/addon-essentials` Zeile in der Integrations-Tabelle korrigieren: entweder auf `8.6.x` aendern (falls weiterhin als separates Package noetig) oder einen Hinweis ergaenzen, dass die Installation via `npx storybook@latest init` die korrekten Versionen automatisch aufloest. Die Migration Map Slice 2 sollte als konkreten Schritt `npx storybook@latest init` verwenden statt manuelle Package-Installation.

---

## Blocking Issues

Keine.

---

## Recommendations

1. **[WARNING]** `@storybook/addon-essentials` Version in Integrations-Tabelle korrigieren -- 10.2.x existiert nicht auf npm. Entweder auf `8.6.x` korrigieren oder Hinweis ergaenzen dass `npx storybook@latest init` die korrekte Version automatisch aufloest.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 1

**Previous Blocking Issues:** 3 -- alle 3 RESOLVED

**Next Steps:**
- [ ] Optional: `@storybook/addon-essentials` Version in Architecture korrigieren (Warning, nicht blockierend)
- [ ] Architecture ist bereit fuer Slice-Planung

---

## Sources

- [@radix-ui/react-slot - npm](https://www.npmjs.com/package/@radix-ui/react-slot) -- Version 1.2.4 confirmed via `npm view`
- [storybook - npm](https://www.npmjs.com/package/storybook) -- Storybook 10.2.12 confirmed as current stable
- [@storybook/nextjs-vite - npm](https://www.npmjs.com/package/@storybook/nextjs-vite) -- 10.2.12 confirmed as current stable
- [@storybook/addon-essentials - npm](https://www.npmjs.com/package/@storybook/addon-essentials) -- 8.6.14 is latest (no 10.x exists)
- [tw-animate-css - npm](https://www.npmjs.com/package/tw-animate-css) -- Version 1.4.0 confirmed via lockfile + `npm view`
- [Storybook Install Docs](https://storybook.js.org/docs/get-started/install) -- Installation via `npm create storybook@latest`
- [Storybook Migration Guide](https://storybook.js.org/docs/releases/migration-guide) -- ESM-Only requirement, addon changes
