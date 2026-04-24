# Slim Spec: Kleinstadtpflanze — Design E als Style uebernehmen

**Discovery:** specs/2026-04-16-kleinstadtpflanze-design-e/discovery.md
**Architecture:** --
**Wireframes:** -- (N/A laut Discovery — reines Design-Token-Feature)

---

## Test-Strategy

| Key | Value |
|-----|-------|
| **Stack** | TypeScript / Next.js 16 (App Router, Tailwind v4 CSS-first) |
| **Test Command** | `cd frontend && pnpm test` |
| **E2E Command** | -- |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | http://localhost:3000 |
| **Mocking Strategy** | no_mocks (Token-Pipeline deterministisch) |
| **Theme-Build-Hook** | `cd frontend && node scripts/generate-theme.mjs` nach jedem YAML-Change |

---

## Dependencies

```
Slice 1: Token-System-Erweiterung      -> (none)
Slice 2: Typografie-Integration        -> (none, parallel zu Slice 1)
Slice 3: Kleinstadtpflanze-Theme-YAML  -> Slice 1
Slice 4: Body-Defaults                 -> (none, independent Polish)
```

---

## Slice 1: Token-System im Default-Theme + `@theme`-Block erweitern

**Goal:** Default-YAML und `app/globals.css` um die neuen Design-E-Slots (`text-tertiary`, `text-muted`, `border-strong`, `surface-alt`, `primary-dark`, `radius.card-lg`, `radius.hero`, `radius.pill`, `shadows.sticky`) erweitern, damit Kleinstadtpflanze-Overrides spaeter per Deep-Merge konsistent greifen koennen. Default-Theme bleibt visuell unveraendert (neutrale Defaults).
**Depends on:** --

### Acceptance Criteria

- **AC-1:** GIVEN `frontend/themes/default/theme.yaml` wurde um neue Slots ergaenzt
  WHEN `cd frontend && node scripts/generate-theme.mjs` ausgefuehrt wird
  THEN Exit-Code ist `0` UND `frontend/app/generated-theme.css` enthaelt im `:root`-Block die CSS-Variablen `--theme-color-text-tertiary`, `--theme-color-text-muted`, `--theme-color-border-strong`, `--theme-color-surface-alt`, `--theme-color-primary-dark`, `--theme-radius-card-lg`, `--theme-radius-hero`, `--theme-radius-pill`, `--theme-shadow-sticky`.

- **AC-2:** GIVEN alle neuen Farb-Slots in `themes/default/theme.yaml`
  WHEN `generate-theme.mjs` die Validierung durchlaeuft
  THEN kein Farb-Wert wird abgelehnt, d.h. jeder Wert matcht `oklch(L C H)` oder `oklch(L C H / A)` (Pipeline-Pflicht laut Business Rule).

- **AC-3:** GIVEN `frontend/app/globals.css` wurde um die neuen Tailwind-Utility-Mappings erweitert
  WHEN ein Template-Snippet mit den Klassen `text-text-tertiary`, `text-text-muted`, `border-strong`, `bg-surface-alt`, `bg-primary-dark`, `rounded-card-lg`, `rounded-hero`, `rounded-pill`, `shadow-sticky` in der Dev-Umgebung geladen wird
  THEN Tailwind JIT generiert fuer jede Klasse eine entsprechende CSS-Regel mit `var(--theme-*)` als Wert UND `pnpm build` laeuft ohne Fehler durch.

- **AC-4:** GIVEN das Default-Theme ist aktiv (`NEXT_PUBLIC_THEME=default`)
  WHEN die Homepage im Browser geladen wird
  THEN die bisherigen Farben, Radien und Shadows sind unveraendert (keine visuelle Regression fuer bestehende Token-Nutzungen) UND `radius.pill` liefert als Default `999px`.

- **AC-5:** GIVEN das `zweiter-shop`-Theme ist aktiv (`NEXT_PUBLIC_THEME=zweiter-shop`), das die neuen Slots NICHT ueberschreibt
  WHEN `generate-theme.mjs` laeuft
  THEN Deep-Merge faellt fuer jeden neuen Slot auf den Default-Wert zurueck UND kein Build-Bruch entsteht.

### Deliverables

- [ ] `frontend/themes/default/theme.yaml` -- neue Slots (`text-tertiary`, `text-muted`, `border-strong`, `surface-alt`, `primary-dark`, `radius.card-lg`, `radius.hero`, `radius.pill`, `shadows.sticky`) mit neutralen OKLCH-Defaults ergaenzt
- [ ] `frontend/app/globals.css` -- `@theme`-Block um korrespondierende Tailwind-Utility-Mappings (`--color-text-tertiary`, `--color-text-muted`, `--color-border-strong`, `--color-surface-alt`, `--color-primary-dark`, `--radius-card-lg`, `--radius-hero`, `--radius-pill`, `--shadow-sticky`) erweitert
- [ ] `frontend/app/generated-theme.css` -- regeneriert (Output der Pipeline, nicht manuell editiert)

---

## Slice 2: Typografie-Integration (Work Sans + Source Serif 4)

**Goal:** `lib/theme/fonts.ts` von Inter-Only auf Work Sans (body, 400/500/600/700) + Source Serif 4 (heading, 600/700) via `next/font/google` umstellen. CSS-Variable-Exports (`--font-heading`, `--font-body`) bleiben namentlich erhalten, damit bestehende `@theme`-Bridge weiter greift.
**Depends on:** --

### Acceptance Criteria

- **AC-1:** GIVEN `frontend/lib/theme/fonts.ts` exportiert die Namen `headingFont` und `bodyFont`
  WHEN die Datei geladen wird
  THEN `bodyFont` ist ein `Work_Sans`-Aufruf von `next/font/google` mit `subsets: ['latin']`, `weight: ['400','500','600','700']`, `variable: '--font-body'`, `display: 'swap'` UND `headingFont` ist ein `Source_Serif_4`-Aufruf mit `subsets: ['latin']`, `weight: ['600','700']`, `variable: '--font-heading'`, `display: 'swap'`.

- **AC-2:** GIVEN der Dev-Server laeuft mit `pnpm dev`
  WHEN die Homepage im Browser geladen wird und auf `<body>` bzw. `<html>` inspiziert wird
  THEN die Klassen-Attribute enthalten sowohl die Work-Sans-Variable (`--font-body`) als auch die Source-Serif-4-Variable (`--font-heading`) UND `getComputedStyle(document.body).fontFamily` liefert eine Kette, die mit `"Work Sans"` beginnt.

- **AC-3:** GIVEN Slice 2 ist gemerged
  WHEN ein `<h1>` im DOM gerendert wird, das die Tailwind-Utility `font-heading` nutzt
  THEN `getComputedStyle(h1).fontFamily` enthaelt `"Source Serif 4"` (oder ueber `display: swap`-Fallback `"Source Serif Pro"` / `Georgia` / `serif`).

- **AC-4:** GIVEN die Font-Dokumentation liegt in YAML
  WHEN `frontend/themes/default/theme.yaml` geladen wird
  THEN `fonts.body == "Work Sans"` UND `fonts.heading == "Source Serif 4"` (dokumentarisch, Laufzeit-Effekt kommt aus `fonts.ts`).

- **AC-5:** GIVEN `pnpm build` wird nach dem Font-Umbau ausgefuehrt
  WHEN der Build durchlaeuft
  THEN Exit-Code ist `0`, es erscheint kein `next/font`-Fehler bezueglich nicht-verfuegbarer Font-Weights, UND kein Import von `Inter` ist mehr in `lib/theme/fonts.ts` vorhanden.

### Deliverables

- [ ] `frontend/lib/theme/fonts.ts` -- komplett ersetzt: Work Sans (body) + Source Serif 4 (heading) via `next/font/google`
- [ ] `frontend/themes/default/theme.yaml` -- `fonts.heading` und `fonts.body` dokumentarisch auf neue Namen gesetzt

---

## Slice 3: Kleinstadtpflanze-Theme-YAML + Aktivierung

**Goal:** Neues Theme `themes/kleinstadtpflanze/theme.yaml` mit Design-E-Overrides (Farben OKLCH-konvertiert, Radius-Werte, Shadows, Font-Namen dokumentarisch) anlegen und via `NEXT_PUBLIC_THEME=kleinstadtpflanze` aktivieren. Default-Theme bleibt unberuehrt, Page-Configs werden NICHT ueberschrieben (greifen ueber 3-Tier-Fallback aus `themes/default/pages/*.yaml`).
**Depends on:** Slice 1

### Acceptance Criteria

- **AC-1:** GIVEN `frontend/themes/kleinstadtpflanze/theme.yaml` existiert
  WHEN die Datei geparst wird
  THEN sie enthaelt ausschliesslich Deltas zu Default (Deep-Merge-Prinzip) UND folgende Overrides sind gesetzt: `colors.primary` (Forest entsprechend `#2E5A3E` in OKLCH), `colors.primary-hover` und `colors.primary-dark` (`#204430` in OKLCH), `colors.accent` (Terracotta entsprechend `#C97E3C`), `colors.error` (Sale `#C0392B`), `colors.success` (`#3A8152`), `colors.surface-alt` (Cream `#F7F5F1`), `colors.text-primary` (`#1F2322`), `colors.text-secondary` (`#4A4F4D`), `colors.text-tertiary` (`#7A807D`), `colors.text-muted` (`#B5B8B5`), `colors.border` (`#E4E1DA`), `colors.border-strong` (`#D4D0C6`).

- **AC-2:** GIVEN die Radius- und Shadow-Overrides
  WHEN `themes/kleinstadtpflanze/theme.yaml` geparst wird
  THEN `radius.button == "0.5rem"`, `radius.card == "0.625rem"`, `radius.card-lg == "0.75rem"`, `radius.hero == "0.875rem"`, `radius.pill == "999px"` UND `shadows.card` basiert auf `0 4px 12px <OKLCH-Schwarz mit Alpha 0.04>`, `shadows.card-hover` auf `0 6px 18px <OKLCH-Schwarz mit Alpha 0.06>`, `shadows.sticky` auf `0 -4px 16px <OKLCH-Schwarz mit Alpha 0.06>`.

- **AC-3:** GIVEN alle Farben im Kleinstadtpflanze-YAML
  WHEN `node scripts/generate-theme.mjs` mit `NEXT_PUBLIC_THEME=kleinstadtpflanze` laeuft
  THEN Exit-Code ist `0`, Validierung (`validateConfig`) akzeptiert jede Farbe als OKLCH, UND `generated-theme.css` enthaelt die gemergeten Kleinstadtpflanze-Werte (nicht Default-Werte) in `--theme-color-primary`, `--theme-color-accent`, `--theme-color-border`, `--theme-color-surface-alt` etc.

- **AC-4:** GIVEN `NEXT_PUBLIC_THEME=kleinstadtpflanze pnpm dev` laeuft
  WHEN die Homepage im Browser geladen wird
  THEN ein Element mit Klasse `bg-primary` hat eine Forest-Gruen-Farbe (OKLCH-Aequivalent zu `#2E5A3E`), ein Element mit `bg-accent` hat Terracotta, ein Element mit `border-border` hat die Linienfarbe (OKLCH-Aequivalent zu `#E4E1DA`), UND ein Element mit `bg-surface-alt` hat den Cream-Hintergrund (`#F7F5F1`-Aequivalent).

- **AC-5:** GIVEN das Kleinstadtpflanze-Theme ist aktiv
  WHEN `/` (Homepage) geladen wird
  THEN die Page-Block-Komposition stammt weiterhin aus `themes/default/pages/home.yaml` (kein `themes/kleinstadtpflanze/pages/home.yaml` existiert) UND die Homepage rendert ohne 404 oder Missing-Config-Fehler.

- **AC-6:** GIVEN die Env-Dokumentation
  WHEN `frontend/.env.local.example` geoeffnet wird
  THEN `kleinstadtpflanze` ist in der Kommentarliste verfuegbarer Themes aufgefuehrt (neben `default`, `zweiter-shop`).

### Deliverables

- [ ] `frontend/themes/kleinstadtpflanze/theme.yaml` -- neu: Overrides aller Design-E-Farben (OKLCH), Radius-Werte, Shadows, `fonts`-Namen dokumentarisch
- [ ] `frontend/.env.local.example` -- Theme-Liste im Kommentar um `kleinstadtpflanze` erweitert

---

## Slice 4: Body-Defaults aus Design E (15px / 1.55)

**Goal:** Globale Body-Basis in `app/globals.css` an Design-E-Lesefluss anpassen: `font-size: 15px`, `line-height: 1.55`. `antialiased` und Touch-Target-Setup bleiben erhalten.
**Depends on:** --

### Acceptance Criteria

- **AC-1:** GIVEN `frontend/app/globals.css` enthaelt einen `@layer base`-Block fuer `body`
  WHEN die Datei kompiliert wird
  THEN die generierte CSS-Regel fuer `body` setzt explizit `font-size: 15px` UND `line-height: 1.55`.

- **AC-2:** GIVEN der Dev-Server laeuft mit aktivem Kleinstadtpflanze- oder Default-Theme
  WHEN die Homepage im Browser geladen wird
  THEN `getComputedStyle(document.body).fontSize` liefert `"15px"` UND `getComputedStyle(document.body).lineHeight` liefert einen Wert, der `15 * 1.55 = 23.25px` entspricht (Browser-gerundet, z.B. `"23.25px"` oder `"23.2px"`).

- **AC-3:** GIVEN die Anpassungen sind aktiv
  WHEN `h1`, `h2`, `h3` gerendert werden
  THEN die bestehenden Heading-Styles (`text-wrap: balance`, `font-semibold`, `tracking-tight`) bleiben unveraendert UND Headings erben nicht die `15px`-Groesse (Tailwind-Heading-Utilities ueberschreiben).

- **AC-4:** GIVEN die bestehenden Body-Settings
  WHEN `body` inspiziert wird
  THEN die existierenden Apply-Klassen `antialiased`, `text-text-primary`, `bg-surface` sind weiterhin auf dem Element aktiv (keine Regression der bestehenden Styles).

- **AC-5:** GIVEN `--min-touch-target: 2.75rem` ist weiter gesetzt
  WHEN eine Mobile-Interaktion auf einem `<button>` oder `<a>` getestet wird
  THEN `touch-action: manipulation` greift weiterhin (keine Regression fuer Pinterest-Mobile-Traffic).

### Deliverables

- [ ] `frontend/app/globals.css` -- `@layer base { body { ... } }` um `font-size: 15px` und `line-height: 1.55` erweitert (ohne bestehende `@apply antialiased text-text-primary bg-surface` zu entfernen)
