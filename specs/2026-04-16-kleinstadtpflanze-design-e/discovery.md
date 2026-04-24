# Feature: Kleinstadtpflanze — Design E als Style übernehmen

**Epic:** --
**Issue:** -- _(ohne Issue nach User-Entscheidung; Traceability eingeschränkt)_
**Status:** Ready
**Wireframes:** N/A (reines Design-Token-Feature, keine UI-Strukturen)

---

## Problem & Solution

**Problem:**
- Das Projekt hat aktuell ein generisches Default-Theme (neutrale grüne Basis, Inter-Only, minimales Token-Set). Es existiert noch keine Marken-Identität für den Zielshop "Kleinstadtpflanze".
- Der User hat mit claude.ai/design **fünf Design-Varianten (A–E)** erprobt und sich für **Variante E** entschieden: konservativer, vertrauenswürdiger DTC-Shop mit warmen Erdtönen ("freundlicher deutscher Online-Shop, den die Oma auch bedienen kann").
- Ohne Token-Übernahme bleibt der aktuelle Look weit vom gewünschten Charakter entfernt.

**Solution:**
- Design-E-Tokens als **neues Theme `kleinstadtpflanze`** in die bestehende YAML-Theming-Engine überführen.
- Token-System im Default-Theme um die neuen Slots erweitern, damit der Deep-Merge konsistent bleibt.
- Typo (Work Sans + Source Serif 4) über `next/font` einbinden.
- **Phase 1 (dieses Feature): NUR Style-Tokens** — Components/Layout folgen separat später.

**Business Value:**
- Visueller Sprung vom generischen Template zum erkennbaren Kleinstadtpflanze-Branding.
- Klare Trennung zwischen Default-Baseline und Shop-spezifischer Identität (Multi-Shop-Pattern bleibt intakt).
- Vorbereitung für spätere Component/Layout-Übernahme aus Design E.

---

## Scope & Boundaries

| In Scope |
|----------|
| Neues Theme `themes/kleinstadtpflanze/theme.yaml` mit Design-E-Farben (Forest, Terracotta, Sale, Cream, Ink-Stufen, Line-Stufen), Radius-Werten und Shadow-Werten |
| Default-YAML (`themes/default/theme.yaml`) um fehlende Token-Slots erweitern (neutrale Defaults) |
| Farb-Konversion Hex → OKLCH (Pipeline-Pflicht) |
| Typografie: `lib/theme/fonts.ts` umbauen auf Work Sans (body) + Source Serif 4 (heading) via `next/font` |
| `app/globals.css` `@theme`-Block um neue Utility-Mappings erweitern (neue Token-Slots als Tailwind-Utilities nutzbar machen) |
| Globale Body-Defaults in `globals.css` angleichen (font-size 15px, line-height 1.55, antialiasing analog Design E) |
| Theme-Aktivierung via `NEXT_PUBLIC_THEME=kleinstadtpflanze` dokumentieren |
| Build-Test: `node scripts/generate-theme.mjs` validiert grün |

| Out of Scope |
|--------------|
| **Components/Layout aus Design E** — kommt als separates Feature ("Kleinstadtpflanze Layout-Übernahme") |
| **Component-Migration von hardcoded Werten auf neue Tokens** — nur bereits token-nutzende Komponenten profitieren automatisch |
| Motiv-Bibliothek (Siegel/Skyline/Typo/Botanik), POD-Visuals, Produkt-Mockups |
| Dark-Mode (Design E ist Light-Only) |
| Storybook-Updates für neue Tokens (wenn nötig, separates Housekeeping) |
| Update von `zweiter-shop` Theme (bleibt unberührt) |
| Änderungen in `themes/default/pages/*.yaml` (Page-Block-Configs) |

---

## Current State Reference

> Wird unverändert weitergenutzt:

- **YAML-Theming-Pipeline** (`frontend/scripts/generate-theme.mjs`): lädt Default + optional Shop-YAML, deep-merge, validiert OKLCH-Format, generiert `app/generated-theme.css`
- **Theme-Engine-Konventionen** (`CLAUDE.md` §Theming Engine): OKLCH-only, YAML → `--theme-*` → `@theme`-Mapping → Tailwind-Utility
- **Multi-Shop-Strategie**: `NEXT_PUBLIC_THEME` env selektiert Shop-Override, Default ist Fallback
- **Existierende Token-Slots** in Default-YAML: `primary`, `primary-hover`, `accent`, `surface`, `surface-elevated`, `text-primary`, `text-secondary`, `border`, `error`, `success`, `warning`, `overlay`, `radius.card`, `radius.button`, `shadows.card`, `shadows.card-hover`, `fonts.heading`, `fonts.body`
- **Existierende `@theme`-Mappings** in `app/globals.css`: Farben, Radius, Shadows, Font-Variables, Touch-Target-Spacing, Slide-Up-Animation
- **201 Token-Nutzungen in 40 Komponenten-Dateien** (Grep auf `bg-primary|bg-accent|bg-surface|text-text-primary|border-border|rounded-card|shadow-card|...`) — diese bekommen den neuen Look automatisch
- **Komponenten mit hardcoded Werten** (nicht explizit gezählt): Migration ist laut `CLAUDE.md` laufender Prozess ("always use Tailwind theme token utilities instead of hardcoded values")

---

## UI Patterns

### Reused Patterns

| Pattern Type | Component | Usage in this Feature |
|--------------|-----------|------------------------|
| YAML-basierte Token-Definition | `frontend/themes/*/theme.yaml` | Neues Shop-Theme folgt der existierenden Struktur |
| Deep-Merge-Override | `scripts/generate-theme.mjs#deepMerge` | Kleinstadtpflanze-YAML überschreibt nur Deltas zu Default |
| OKLCH-Validierung | `scripts/generate-theme.mjs#validateConfig` | Alle Farben werden als OKLCH geschrieben, keine Pipeline-Änderung nötig |
| `@theme`-Tailwind-Bridge | `app/globals.css` | Neue CSS-Vars werden analog zu bestehenden als Utility-Klassen freigegeben |
| `next/font`-Integration | `lib/theme/fonts.ts` | Work Sans + Source Serif 4 ersetzen Inter im gleichen Muster |

### New Patterns

| Pattern Type | Description | Rationale |
|--------------|-------------|-----------|
| Zweite Font-Familie mit display/heading-Split | Source Serif 4 (heading) + Work Sans (body) | Aktuell nur 1 Font (Inter für beides). Design E erfordert echten Serif/Sans-Kontrast. |
| Mehrstufige Ink-Token-Hierarchie | `text-primary` / `text-secondary` / `text-tertiary` / `text-muted` | Aktuell nur zwei Ebenen (primary/secondary). Design E nutzt 4 Ink-Ebenen für Info-Hierarchie (Labels, Meta, Disabled). |
| Sekundärer Border-Token | `border` + `border-strong` | Design E nutzt 1.5px-Borders (Inputs, Pills) in einer kräftigeren Linie (`#D4D0C6`) zur Abgrenzung von dezenten Hairlines (`#E4E1DA`). |
| Alternative Surface | `surface` + `surface-elevated` + `surface-alt` | Warmer Cream-Hintergrund (`#F7F5F1`) für Section-Backgrounds, Cards, Accents — nicht mit `surface-elevated` (neutraler Grauton) vermischbar. |
| Brand-Dark-Token | `primary` + `primary-dark` | Explizite Hover-Farbe für den Brand (`#204430`) — aktuell nur `primary-hover` mit Alpha-Shift gedacht, Design E hat expliziten Wert. |
| Pill-Radius-Utility | `rounded-pill` → `999px` | Design E nutzt Pill-Shapes für Tags/Badges. Aktuell nur `rounded-card` + `rounded-button`. |
| Sale-Variante von Error | `sale` als eigenes Token oder Mapping auf `error` | Design E hat einen DTC-typischen Sale-Rot (`#C0392B`) semantisch getrennt von Fehler-Status. Entscheidung: auf `error` mappen (siehe Open Question Q1). |

---

## Token Mapping: Design E → Projekt-Slots

> **Single Source of Truth** für das Farb-/Radius-/Shadow-Mapping. Hex-Werte stammen aus `tokens-e.css`. OKLCH-Werte werden bei Implementation per deterministischem Converter (z.B. Culori) berechnet.

### Farben

| Design-E-Token | Hex | Projekt-Slot (neu/existing) | Semantik |
|----------------|-----|------------------------------|----------|
| `--e-brand` | `#2E5A3E` | `primary` (override) | CTA, Brand-Akzente, Logo-Mark |
| `--e-brand-d` | `#204430` | `primary-hover` (override) + `primary-dark` (NEU) | Button-Hover, Active-Border |
| `--e-accent` | `#C97E3C` | `accent` (override) | Sekundäre CTAs (Newsletter-Button, "New"-Badge, Sale-Akzente) |
| `--e-sale` | `#C0392B` | `error` (override, Semantik-Reuse) | Rabatt-Preise, Sale-Badges, Destructive Actions |
| `--e-ok` | `#3A8152` | `success` (override) | Verfügbarkeits-Dot, Verified-Badges |
| `--e-bg` | `#FFFFFF` | `surface` (override, bleibt) | Page-Background |
| `--e-alt` | `#F7F5F1` | `surface-alt` (NEU) | Section-Backgrounds, Cards mit warmem Feel, Cream-Areas |
| `--e-ink` | `#1F2322` | `text-primary` (override) | Haupt-Textfarbe (fast-schwarz mit grünem Stich) |
| `--e-ink-70` | `#4A4F4D` | `text-secondary` (override) | Sekundärtext, Meta |
| `--e-ink-50` | `#7A807D` | `text-tertiary` (NEU) | Small Caps, Category-Meta, inaktive Nav-Items |
| `--e-ink-30` | `#B5B8B5` | `text-muted` (NEU) | Disabled-Text, Placeholder, durchgestrichene Werte |
| `--e-line` | `#E4E1DA` | `border` (override) | Standard-Hairlines, Card-Borders, Trenner |
| `--e-line-2` | `#D4D0C6` | `border-strong` (NEU) | 1.5px-Borders auf Inputs, Pills, Selectoren |

> `surface-elevated` bleibt unverändert (neutraler Default, nicht Teil von Design E).
> `warning` und `overlay` bleiben unverändert (Design E liefert keine äquivalenten Werte).

### Radius

| Design-E-Wert | Frequenz | Projekt-Slot | OKLCH-freier Wert |
|---------------|----------|--------------|-------------------|
| `8px` | Buttons, Inputs, Tags, Kleine Cards | `radius.button` (override) | `0.5rem` |
| `10px` | Produkt-Cards, PDP-Main, Editorial | `radius.card` (override) | `0.625rem` |
| `12px` | Hero-Cards, Newsletter, USP | `radius.card-lg` (NEU) | `0.75rem` |
| `14px` | Hero A (primary Hero) | `radius.hero` (NEU) | `0.875rem` |
| `999px` | Pills, Tags (uppercase) | `radius.pill` (NEU) | `999px` |

### Shadows

| Design-E-Wert | Context | Projekt-Slot |
|---------------|---------|--------------|
| `0 4px 12px rgba(0,0,0,.04)` | Category-Hover | `shadows.card` (override) |
| `0 6px 18px rgba(0,0,0,.06)` | Product-Card-Hover | `shadows.card-hover` (override) |
| `0 12px 30px rgba(0,0,0,.12)` | Tweaks-Panel (ignorierbar) | -- (Design-Tool-Artefakt) |
| `0 -4px 16px rgba(0,0,0,.06)` | Sticky-ATC (kommt später mit Component) | `shadows.sticky` (NEU, vorbereitet) |

---

## Typography Plan

### Ziel

| Rolle | Design-E-Font | Weights | Projekt-Slot (`theme.yaml`) | `lib/theme/fonts.ts` |
|-------|---------------|---------|------------------------------|-----------------------|
| Body | Work Sans | 400, 500, 600, 700 | `fonts.body` | `bodyFont = Work_Sans({ weight: ['400','500','600','700'] })` |
| Heading/Display | Source Serif 4 | 600, 700 | `fonts.heading` | `headingFont = Source_Serif_4({ weight: ['600','700'] })` |

### Integration

- `fonts.ts` wird komplett ersetzt (Inter → Work Sans + Source Serif 4).
- YAML-Font-Felder bekommen dokumentarischen Wert (werden laut `CLAUDE.md` aktuell nicht dynamisch gelesen — Font-Wahl ist hart in `fonts.ts`).
- Google-Fonts-Subsets: `latin` (deutschsprachig, Umlaute + Eszett).
- `display: swap` für beide, identisch zur aktuellen Inter-Integration.
- Variable Fonts erlaubt, aber Weight-Liste explizit (Design-E-Referenz).

### Fallback-Kaskade

- Body: `--font-body, "Work Sans", "Inter", system-ui, sans-serif`
- Heading: `--font-heading, "Source Serif 4", "Source Serif Pro", Georgia, serif`

---

## User Flow

**N/A** — reines Design-Token-Feature. Keine nutzerseitigen Flows, keine State-Übergänge.

**Error Paths:** Siehe Risks-Liste unten.

---

## UI Layout & Context

**N/A** — keine neuen Screens oder Bereiche. Änderungen greifen global über alle bestehenden Seiten, die Theme-Tokens konsumieren (Homepage, `/kategorie/[slug]`, `/produkt/[slug]`, `/warenkorb`, `/suche`, 404-Page, Collection-Header, Order-Confirmation etc.).

---

## UI Components & States

**N/A** — es werden keine neuen interaktiven Elemente eingeführt. Bestehende Komponenten, die Theme-Utilities nutzen (z.B. `bg-primary`, `text-text-primary`, `rounded-card`), adaptieren sich automatisch.

---

## Feature State Machine

**N/A** — Tokens sind stateless Design-Konstanten. Kein Zustandsmodell.

---

## Business Rules

- Jede Farbe in `theme.yaml` muss OKLCH-Format haben (`oklch(L C H)` oder `oklch(L C H / A)`) — sonst bricht die Pipeline (`generate-theme.mjs#validateConfig`).
- Kleinstadtpflanze-YAML enthält NUR Deltas zu Default (Deep-Merge-Prinzip aus `CLAUDE.md`).
- Nach jeder `theme.yaml`-Änderung: `node scripts/generate-theme.mjs` ausführen (oder über `pnpm build`/`pnpm dev`-Hook, falls vorhanden).
- `NEXT_PUBLIC_THEME=kleinstadtpflanze` muss im Build-Environment gesetzt sein, sonst greift Default.

---

## Data

**N/A** — keine persistierten Daten, kein GraphQL/DB-Schema.

---

## Implementation Slices

> Testbare, deploybare Inkremente. Jeder Slice liefert einen abgeschlossenen User-Value.

### Dependencies

```
Slice 1 (Token-System-Erweiterung)
   │
   ├── Slice 3 (Kleinstadtpflanze-Theme-YAML + Aktivierung)
   │
Slice 2 (Typografie) — parallel zu Slice 1
   │
Slice 4 (Body-Defaults) — independent, letzter Polish
```

### Slices

| # | Name | Scope | Testability | Dependencies |
|---|------|-------|-------------|--------------|
| 1 | Token-System-Erweiterung | Default-YAML um neue Slots (`text-tertiary`, `text-muted`, `border-strong`, `surface-alt`, `primary-dark`, `radius.card-lg`, `radius.hero`, `radius.pill`, `shadows.sticky`) mit neutralen Defaults ergänzen. `app/globals.css` `@theme`-Block um passende Tailwind-Utility-Mappings erweitern (`text-text-tertiary`, `text-text-muted`, `border-strong`, `bg-surface-alt`, `bg-primary-dark`, `rounded-card-lg`, `rounded-hero`, `rounded-pill`, `shadow-sticky`). | `pnpm build` grün, `node scripts/generate-theme.mjs` ohne Fehler, neue Utility-Klassen in `generated-theme.css` als `--theme-*` vorhanden, Tailwind-JIT erkennt sie in Playground-Test. | -- |
| 2 | Typografie-Integration | `lib/theme/fonts.ts` neu: `Work_Sans` (body, 400/500/600/700) + `Source_Serif_4` (heading, 600/700) via `next/font/google`. `variable`-Exports unverändert (`--font-heading`, `--font-body`). YAML-Fonts-Felder dokumentarisch auf die neuen Namen setzen. | Dev-Server startet, `<h1>` rendert mit Serif, Body mit Sans, keine FOIT/FOUT-Regression, Lighthouse CLS ≤ alter Wert. | -- |
| 3 | Kleinstadtpflanze-Theme-YAML + Aktivierung | `themes/kleinstadtpflanze/theme.yaml` anlegen: Overrides für alle Design-E-Token (Farben OKLCH-konvertiert, Radius, Shadows, Fonts-Namen dokumentarisch). `.env.local` / Deployment-Env um `NEXT_PUBLIC_THEME=kleinstadtpflanze` ergänzen. Validierung: Page-Block-Configs bleiben aus `themes/default/pages/*.yaml` geladen (Pages nicht überschrieben). | `NEXT_PUBLIC_THEME=kleinstadtpflanze pnpm dev`: Homepage zeigt Forest-Grün statt aktuelles Grün-Primary; Accent ist Terracotta statt Lila; Border-Farbe ist `#E4E1DA`-äquivalent; Cards mit `bg-surface-alt` sind Cream. | Slice 1 |
| 4 | Body-Defaults aus Design E | `app/globals.css` `@layer base`: body `font-size: 15px`, `line-height: 1.55` (ersetzt/ergänzt Tailwind-Defaults). Optional `-webkit-font-smoothing: antialiased` (bereits gesetzt, prüfen). Touch-Target `--min-touch-target` bleibt. | Visuelle Regression: Body-Text wirkt eine Spur ruhiger (15px statt Browser-Default 16px), Line-Height etwas enger. Keine Regressionen im responsive Verhalten. | -- |

### Recommended Order

1. **Slice 1:** Token-System-Erweiterung — Grundlage für Slice 3, Default-Theme bleibt lauffähig, keine User-Facing-Änderung. Low Risk, High Enablement.
2. **Slice 2:** Typografie-Integration — parallel einspielbar (unabhängig von Slice 1), betrifft NUR `fonts.ts` + YAML-Dokumentation. Visueller Effekt sofort sichtbar auch mit Default-Theme (Sans ist "Work Sans" statt Inter).
3. **Slice 3:** Kleinstadtpflanze-Theme-YAML + Aktivierung — der "große Knopf". Nach Slice 1+2 wird das Design plötzlich sichtbar.
4. **Slice 4:** Body-Defaults — finaler Feinschliff, niedrigste Priorität.

### Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Hardcoded Hex/RGB-Werte in bestehenden Komponenten ignorieren neue Tokens (partieller Look) | CLAUDE.md dokumentiert Migration-Pattern. Nach Phase 1 gezielten Audit-Slice für Top-10-Komponenten (Header, Footer, Product-Card, Hero, Newsletter). **Nicht Teil dieses Features.** |
| OKLCH-Konversion von Hex ist approximativ | Deterministischer Converter (Culori / color-js) nutzen; Referenz-Werte in Kommentar neben YAML-Eintrag dokumentieren. |
| Font-Weight 600/700 für Source Serif 4 nicht in Google Fonts verfügbar | Vor Implementation prüfen; Fallback auf "Source Serif Pro" (verfügbar). |
| Build-Zeit steigt durch 2. Google Font | Marginal; `next/font` subset-optimiert. Vernachlässigbar. |
| `NEXT_PUBLIC_THEME`-Switch wirkt erst bei erneutem Build (Pipeline läuft pre-Next) | Deploy-Docs / README updaten: "nach Theme-Switch: `node scripts/generate-theme.mjs` + restart". |
| `zweiter-shop` Theme hat nicht alle neuen Slots (Deep-Merge fängt ab) | Default liefert Fallback-Werte → kein Bruch. Getestet über `NEXT_PUBLIC_THEME=zweiter-shop` in Slice 1. |

---

## Context & Research

### Similar Patterns in Codebase

| Feature | Location | Relevant because |
|---------|----------|------------------|
| `zweiter-shop` Theme (nur 3 Color-Overrides + heading-Font) | `frontend/themes/zweiter-shop/theme.yaml` | Existierendes Muster für Shop-Override; zeigt minimalen Override-Scope |
| Theme-Pipeline Deep-Merge | `frontend/scripts/generate-theme.mjs` | Ermöglicht, dass Kleinstadtpflanze-YAML nur Deltas enthält |
| `@theme`-Bridge für neue Tokens | `frontend/app/globals.css` (Zeilen 5–43) | Vorlage für Token-Slot-Erweiterung in Slice 1 |
| Frontend-Theming-Slices (historisch) | `frontend/tests/slices/frontend-theming/slice-01..05` | Existierende Test-Patterns für Token-Einführung (Unit-Tests prüfen CSS-Variable-Generierung) |

### Web Research / External

| Source | Finding |
|--------|---------|
| Design-E-Bundle (`claude.ai/design`, heruntergeladen via `api.anthropic.com/v1/design/h/Oj_sBCqoHKkIy82VGtyVvg`) | Tar-Gzip mit 5 Design-Varianten (A–E); für Feature relevant: `tokens-e.css`, `Kleinstadtpflanze Shop E.html`, `src-e/everything-e.jsx`, `chats/chat1.md` |
| Chat-Transkript `chat1.md` | User-Intent für Variante E: "konservativer, vertrauenswürdiger deutscher Online-Shop, den die Oma auch bedienen kann". Sichtbare Rabattpreise, Trust-Badges, FAQ-Accordion, grüne Brand-Farbe. Variante E = fünfter Entwurf nach A (neotraditional), B (brutalist), C (friendly indie), D (quiet editorial). |
| Google Fonts Verfügbarkeit | Work Sans (400/500/600/700) und Source Serif 4 (600/700) sind im Google-Font-Index; `next/font/google` unterstützt beide nativ. |
| CLAUDE.md §Theming Engine | Deep-Merge-Semantik, OKLCH-Pflicht, Fonts-in-YAML-aber-hardcoded-Konvention dokumentiert |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| 1 | Soll `sale` (`#C0392B`) ein eigenes Token bekommen oder auf `error` mappen? | A) Eigenes Token `sale` / B) Reuse `error` | B (Reuse) — semantisch äquivalent, vermeidet Redundanz; UI unterscheidet Sale vs. Error im Kontext (Badge vs. Toast), nicht in Farbe | B (implizit in Scope-Entscheidung "Mapping wo sinnvoll") |
| 2 | Werden `radius.card-lg` (12px) und `radius.hero` (14px) tatsächlich genutzt, solange Components noch Default haben? | A) Schon jetzt einführen / B) Erst in Phase 2 mit Components | A — User entschied "Token-System erweitern" + "Tailwind-Utility-Erweiterungen". Vollständigkeit jetzt vermeidet spätere Sprünge in `globals.css`. | A (abgedeckt durch Slice 1 Scope) |
| 3 | Font-Weight-Umfang: Source Serif 4 auch mit 400/500 für Body-Serif-Einsätze laden? | A) Nur 600/700 (Design-E-Nutzung) / B) Plus 400/500 on-top | A — Design E nutzt Serif AUSSCHLIESSLICH für Headings; 400/500 wäre Vorrats-Overload (Performance-Kosten) | A (in Slice 2 dokumentiert) |
| 4 | Dark-Mode-Vorbereitung (z.B. `text-primary-dark` als `@media (prefers-color-scheme: dark)`-Variante)? | A) Keine Dark-Vorbereitung / B) Platzhalter-Slot | A — Design E ist explizit Light-Only; Out-of-Scope gesetzt | A |
| 5 | Shadow-Werte: Aktuelle Hex-Farbe `oklch(0 0 0 / 0.08)` vs. Design-E `rgba(0,0,0,.04)` — angleichen? | A) OKLCH bleibt (Pipeline-Pflicht) mit angepasstem Alpha / B) `rgba(...)` schreiben (Pipeline-Problem) | A — OKLCH mit angepasstem Alpha-Wert (0.04, 0.06). Pipeline-konform. | A (abgedeckt in Slice 1 + 3) |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-04-16 | External fetch | Design-Bundle von `api.anthropic.com/v1/design/h/Oj_sBCqoHKkIy82VGtyVvg` geladen (2.1 MB gzip), entpackt nach `/tmp/design-e/pod-shop/`. Enthält 5 Varianten, README, 1 Chat-Transkript. |
| 2026-04-16 | Codebase | Theme-Engine (`scripts/generate-theme.mjs`) akzeptiert beliebige Keys innerhalb `colors`/`fonts`/`radius`/`shadows` — KEINE Script-Änderung für neue Slots nötig. Nur Validierung prüft OKLCH-Format für Farben. |
| 2026-04-16 | Codebase | `app/globals.css` `@theme`-Block muss manuell erweitert werden, damit Tailwind neue Utilities generiert. |
| 2026-04-16 | Codebase | `lib/theme/fonts.ts` hardcoded auf Inter — YAML-Font-Werte werden NICHT dynamisch gelesen. Font-Wechsel = Code-Change in `fonts.ts`. |
| 2026-04-16 | Codebase | 201 Token-Nutzungen in 40 Component-Files gefunden (`bg-primary|text-text-primary|rounded-card|shadow-card|...`). Diese profitieren automatisch vom neuen Theme. |
| 2026-04-16 | External | Design E: Work Sans (400/500/600/700) + Source Serif 4 (600/700), beide via Google Fonts verfügbar. |
| 2026-04-16 | Chat-Transkript | Variante E ist bewusster Kontrast zu A–D: konservativer, "Oma-tauglich", DTC-Standard mit Trust-Bar, Utility-Bar, sichtbaren Rabatten, grüner Brand-Farbe. |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| 1 | Gibt es ein GitHub Issue für diese Design-Übernahme? (Ja-Nr/URL / Neu anlegen / Ohne) | Ohne Issue — User lehnt Issue-Erstellung ab. Traceability eingeschränkt akzeptiert. Branch ohne Issue-ID-Präfix. |
| 2 | Wohin soll Design E landen — Default überschreiben / neues Theme `kleinstadtpflanze` / `zweiter-shop` ersetzen? | **Neues Theme `kleinstadtpflanze`**. Default bleibt generische Baseline. Kleinstadtpflanze-YAML enthält nur Overrides. |
| 3 | Wie gehen wir mit neuen E-Farbebenen um (ink-70/50/30, line-2, alt, sale, ok, brand-dark)? | **Token-System erweitern**. Neue Slots: `text-tertiary`, `text-muted`, `border-strong`, `surface-alt`, `primary-dark`. `sale` → Reuse `error`, `ok` → Reuse `success`, `brand-dark` → eigener Slot als `primary-dark`. Erfordert Updates in Default-YAML + `globals.css` `@theme` + (ggf.) Pipeline. |
| 4 | Fonts: Work Sans + Source Serif 4 strikt übernehmen? | **1:1 via `next/font`**. `fonts.ts` wird umgebaut (Work Sans body, Source Serif 4 heading). YAML-Felder werden dokumentarisch aktualisiert. |
| 5 | Welche Tokens zählen in Phase 1 als "Style"? | **Plus Tailwind-Utility-Erweiterungen**. YAML-Tokens + body-Basis-CSS-Defaults + neue Utilities (`rounded-pill`, `border-strong`, `bg-surface-alt`, etc.). Kein Skip-Option. |
| 6 | Wie Default-YAML zu neuen Slots — erweitern / nur in Kleinstadtpflanze / erst bei Component-Migration? | **Default-YAML mit neuen Slots ergänzen** (neutrale Defaults). Deep-Merge-Pattern bleibt konsistent. Kleinstadtpflanze überschreibt nur Deltas. |
| 7 | (Sign-Off-Bestätigung des Gesamt-Scopes via Plan-Zusammenfassung) | **Ja** — Scope + Entscheidungen bestätigt, weitergehen zur Discovery-Dokumentation. |
