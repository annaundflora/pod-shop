# Gate 2: Slice 04 Compliance Report

**Gepruefter Slice:** `specs/phase-1/2026-02-27-seed-data/slices/slice-04-bild-generierung-script.md`
**Pruefdatum:** 2026-02-27
**Architecture:** `specs/phase-1/2026-02-27-seed-data/architecture.md`
**Wireframes:** N/A (kein UI-Feature)
**Discovery:** `specs/phase-1/2026-02-27-seed-data/discovery.md`
**Referenz-Slices:** `slice-01-produktkatalog-definition.md`, `slice-03-motiv-definition.md`

---

## Summary

| Status | Count |
|--------|-------|
| OK | 48 |
| Warning | 0 |
| Blocking | 0 |

**Verdict:** APPROVED

---

## 0) Inhaltliche Pruefung

### Spezifisch gepruefte Kernfragen (User-Anforderungen)

| Frage | Befund | Status |
|-------|--------|--------|
| Import-Pfad in test_spec: `../../../../scripts/generate-images.mjs` (4 Ebenen)? | Ja — Zeile 291: `from '../../../../scripts/generate-images.mjs'` — 4 Ebenen korrekt | OK |
| Kein Fallback-Widerspruch (kein REPLICATE_API_KEY Fallback-Text)? | Ja — Slice verwendet ausschliesslich `REPLICATE_API_TOKEN`. Constraints-Abschnitt erklaert Korrektur, enthalt keinen Fallback-Satz | OK |
| `replicate.run()` korrekt verwendet (kein manuelles Polling)? | Ja — `await replicate.run("black-forest-labs/flux-2-pro", { input })`, kein manuelles POST/GET | OK |
| 8 ACs im GIVEN/WHEN/THEN Format? | Ja — exakt 8 ACs, alle GIVEN/WHEN/THEN | OK |

### Import-Pfad Verifikation

Test-Datei: `frontend/tests/slices/seed-data/slice-04-bild-generierung-script.test.ts`

Aufloesung von `../../../../scripts/generate-images.mjs` (4 Ebenen):
- `../` = `frontend/tests/slices/`
- `../../` = `frontend/tests/`
- `../../../` = `frontend/`
- `../../../../` = Projekt-Root (`E:/WebDev/pod-shop/`)
- Finaler Pfad: `E:/WebDev/pod-shop/scripts/generate-images.mjs` — korrekt

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes — Exit-Code 1 + exakter Fehlermeldungstext angegeben | Yes — REPLICATE_API_TOKEN nicht gesetzt | Yes — Script ausfuehren | Yes — Exit-Code 1 + exakter String pruefbar | OK |
| AC-2 | Yes | Yes — alle 12 Kategorien, mkdir -p, kein Fehler bei Existenz | Yes — gueltiger Token gesetzt | Yes — Script startet | Yes — Verzeichnisse zaehlen, kein Exception | OK |
| AC-3 | Yes | Yes — 220 Produktbilder + 12 Kategoriebilder = 232, exakte Pfad-Konvention | Yes — Script laeuft gegen alle 110 Produkte, vollstaendig | Yes — Script vollstaendig | Yes — 232 Dateien zaehlen | OK |
| AC-4 | Yes | Yes — kein API-Call, kein Ueberschreiben, "skipped" im Summary-Counter | Yes — Datei existiert bereits am Ausgabepfad | Yes — Script erneut ausfuehren | Yes — Summary-Counter pruefbar, Datei-Timestamp unveraendert | OK |
| AC-5 | Yes | Yes — 1s/2s/4s Delays explizit, Console Warning pro Warteintervall | Yes — Replicate gibt HTTP 429 zurueck | Yes — Fehler empfangen | Yes — Delays via vi.useFakeTimers() pruefbar, Warning-Log pruefbar | OK |
| AC-6 | Yes | Yes — einzeln ueberspringen, Fortsetzung, "failed" Counter | Yes — nicht-429-Fehler tritt auf | Yes — Fehler auftritt | Yes — Summary-Counter "failed" pruefbar, restliche Bilder fortgesetzt | OK |
| AC-7 | Yes | Yes — buildPrompt() Funktion, product.motif als Teilstring enthalten | Yes — Produkt mit befuelltem motif-Feld, buildPrompt(product, template, 1) | Yes — buildPrompt() aufrufen | Yes — String-Containment maschinell pruefbar (toContain) | OK |
| AC-8 | Yes | Yes — exaktes Format "Summary: X generated, Y skipped, Z failed" | Yes — Script vollstaendig abgearbeitet | Yes — Zusammenfassung ausgegeben | Yes — String-Pattern pruefbar via Regex oder contains | OK |

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | API korrekt? | Status |
|--------------|----------------|---------------------|---------------------|--------------|--------|
| Example 1: Script-Skeleton `scripts/generate-images.mjs` | Yes | Yes — `replicate`, `node:fs`, `node:path`, `node:url` existieren | Yes — Named Exports: `buildPrompt`, `buildCategoryPrompt`, `CATEGORY_TEMPLATES`, `withRetry`, `runWithPool` | Yes — `replicate.run()`, ENV `REPLICATE_API_TOKEN` | OK |
| Example 2: `buildPrompt()` Beschreibung | N/A — Referenz auf Example 1 + Formel-Diagramm | N/A | Yes — `(product, categoryTemplate, imageIndex)` | N/A | OK |
| Example 3: `.env.example` Update | N/A — Shell-Konfiguration | Yes — .env Syntax korrekt | N/A | N/A | OK |
| Example 4: `runWithPool()` | Yes | N/A | Yes — `(tasks, poolSize)`, returns `Promise.allSettled` | N/A | OK |
| Example 5: `withRetry()` | Yes | N/A | Yes — `(fn, maxRetries = 3)`, 429-Erkennung via `.response.status`, `.status`, `.message` | N/A | OK |
| test_spec Import | Yes | Yes — 4-Ebenen-Pfad aufgeloest zu Projekt-Root/scripts/ | N/A | N/A | OK |

**Tiefenpruefung Code Example 1 (kritische Aspekte):**

| Aspekt | Wert im Code | Korrekt? |
|--------|-------------|---------|
| Replicate API Methode | `replicate.run("black-forest-labs/flux-2-pro", { input })` | Yes |
| ENV-Var-Name | `process.env.REPLICATE_API_TOKEN` (konsistent, kein Fallback auf _KEY) | Yes |
| Concurrency | `const MAX_CONCURRENCY = 5` | Yes |
| Idempotenz | `if (fs.existsSync(outputPath)) { stats.skipped++ }` | Yes |
| 12 CATEGORY_TEMPLATES | Alle 12 Schluessel vorhanden: t-shirts, hoodies, sweatshirts, tanktops, langarmshirts, taschen, muetzen-caps, tassen, poster-kunstdrucke, kissen, handyhuellen, buttons-anstecker | Yes |
| Named Exports | `export async function withRetry`, `export async function runWithPool`, `export const CATEGORY_TEMPLATES`, `export function buildPrompt`, `export function buildCategoryPrompt` | Yes |
| main()-Guard | `if (process.argv[1] === fileURLToPath(import.meta.url))` | Yes |
| Summary-Format | `console.log(\`Summary: ${stats.generated} generated, ${stats.skipped} skipped, ${stats.failed} failed\`)` | Yes — passt exakt zu AC-8 |

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | Passend — Vitest laeuft im `frontend/`-Kontext | OK |
| Commands vollstaendig | 3 (Test Command, Integration Command, Acceptance Command) | 3 (unit, integration, acceptance) | OK |
| Start-Command | `cd frontend && pnpm dev` | Passend zu Next.js Stack | OK |
| Health-Endpoint | `http://localhost:3000/api/health` | Passend zu Next.js auf Port 3000 | OK |
| Mocking-Strategy | `mock_external` | Definiert — Replicate API wird gemodkt, keine echten API-Calls | OK |

---

## A) Architecture Compliance

### Schema Check

Slice-04 erstellt keine Datenbank-Eintraege. Es generiert Dateisystem-Artefakte (WebP-Bilder). DB-Schema-Check entfaellt.

| Arch Field | Arch Type | Slice Spec | Status | Issue |
|------------|-----------|------------|--------|-------|
| `prompt` | `string` (PredictionInput, bis 2000 chars) | `string`, AC-5-Test prueft `length <= 2000` | OK | — |
| `aspect_ratio` | `"1:1"` (architecture.md nennt als Beispiel-Wert) | `"4:5"` fuer Kleidung, `"1:1"` fuer Produktobjekte | OK | Sinnvolle Erweiterung, architecture.md gibt kein erschoepfendes Spektrum vor |
| `output_format` | `"webp"` | `output_format: 'webp'` | OK | — |
| `resolution` | ~1MP (aus architecture.md NFR) | `"1 MP"` | OK | Uebereinstimmend |
| Produktbild-Pfad | `wordpress/uploads/products/{cat-slug}/{slug}-{1|2}.webp` | Identisch in Code + ACs | OK | — |
| Kategoriebild-Pfad | `category-{slug}.webp` | `category-{cat-slug}.webp` | OK | Identisch |

### API Check

| Endpoint | Arch Method | Slice Method | Status | Issue |
|----------|-------------|--------------|--------|-------|
| Replicate Bildgenerierung | POST `/v1/models/black-forest-labs/flux-2-pro/predictions` + GET-Polling | `replicate.run("black-forest-labs/flux-2-pro", { input })` via SDK | OK | SDK kapselt Polling intern; Slice dokumentiert explizit als korrekte Methode gegenueber architecture.md |
| Modell-ID | `black-forest-labs/flux-2-pro` | `"black-forest-labs/flux-2-pro"` | OK | — |
| Authentifizierung | Bearer-Token via ENV | `auth: process.env.REPLICATE_API_TOKEN` | OK | ENV-Var-Name SDK-konform korrigiert |

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| Token nie in Versionskontrolle | `.env` gitignore'd, `.env.example` nur Placeholder | Code Example 3: explizites Deliverable `.env.example` mit `REPLICATE_API_TOKEN=your_replicate_api_token_here` | OK |
| Exit bei fehlendem Token | "Script exits with error message" | `process.exit(1)` + Meldung "Error: REPLICATE_API_TOKEN is not set. Add it to your .env file." | OK |
| Script laeuft lokal, nicht in Docker | Seed darf keinen Replicate-Aufruf haben | Script-Scope: lokale Ausfuehrung, Bilder werden committed | OK |

---

## B) Wireframe Compliance

**N/A** — Discovery-Dokument definiert explizit: "Wireframes: -- (kein UI-Feature)". Slice-04 ist ein reines Node.js Script ohne UI-Komponenten.

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| `scripts/product-catalog.json` | slice-01-produktkatalog-definition | "Requires From Other Slices" Tabelle | OK |
| `product.motif` Felder (alle 110 non-empty) | slice-03-motiv-definition | "Requires From Other Slices" Tabelle | OK |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| `wordpress/uploads/products/{cat-slug}/{product-slug}-1.webp` | slice-05-bild-import-seed | "Provides To Other Slices" Tabelle, Interface: "Existiert im Dateisystem, gueltiges WebP" | OK |
| `wordpress/uploads/products/{cat-slug}/{product-slug}-2.webp` | slice-05-bild-import-seed | "Provides To Other Slices" Tabelle | OK |
| `wordpress/uploads/products/{cat-slug}/category-{cat-slug}.webp` (12 Dateien) | slice-05-bild-import-seed | "Provides To Other Slices" Tabelle, Interface: "12 Dateien" | OK |

### Consumer-Deliverable-Traceability

Slice-04 liefert Dateisystem-Artefakte (WebP-Bilder). Consumer ist slice-05-bild-import-seed, ein Pending-Slice. Keine bestehende Page als Consumer. Page-Traceability entfaellt.

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-------------------|--------------|--------|
| 220 Produktbilder (WebP) | slice-05-bild-import-seed (PHP-Script) | Ja — Slice-04 Deliverable | slice-04 | OK |
| 12 Kategoriebilder (WebP) | slice-05-bild-import-seed (PHP-Script) | Ja — Slice-04 Deliverable | slice-04 | OK |

### AC-Deliverable-Konsistenz

Kein AC referenziert Pages. Alle 8 ACs beschreiben Script-Verhalten, Funktions-Outputs oder Dateisystem-Zustand.

| AC # | Referenced Page | In Deliverables? | Status |
|------|-----------------|-------------------|--------|
| 1–8 | Keine Pages referenziert | N/A | OK |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| Script-Skeleton `scripts/generate-images.mjs` | Code Example 1 | Yes — vollstaendiges ausfuehrbares Script (~250 Zeilen, alle Funktionen implementiert) | Yes — replicate.run(), Modell-ID, webp | OK |
| `buildPrompt()` Beschreibung + Formel | Code Example 2 | Yes — Formel-Diagramm + Verweis auf Example 1 | Yes | OK |
| `.env.example` Update | Code Example 3 | Yes — Shell-Snippet mit `REPLICATE_API_TOKEN=your_replicate_api_token_here` + Kommentar-Link | Yes | OK |
| `runWithPool()` | Code Example 4 | Yes — vollstaendig, Set-basierter Pool, `Promise.allSettled` | Yes | OK |
| `withRetry()` | Code Example 5 | Yes — vollstaendig, Exponential Backoff 1s/2s/4s, korrekte 429-Erkennung | Yes | OK |

---

## E) Build Config Sanity Check

**N/A** — Slice-04 hat keine Build-Config-Deliverables. Das Script ist ein reines Node.js ESM-Script ohne Build-Schritt.

| Pruef-Aspekt | Requirement | Vorhanden? | Status |
|--------------|-------------|------------|--------|
| Build-Config Deliverable | Kein Build-Config-Deliverable | N/A | N/A |
| process.env Replacement | Kein IIFE/UMD Build | N/A | N/A |
| CSS Build Plugin | Kein CSS-Framework | N/A | N/A |

---

## F) Test Coverage

| Acceptance Criteria | Test Definiert | Test Typ | Status |
|--------------------|----------------|---------|--------|
| AC-1: Exit-Code 1 + Fehlermeldung bei fehlendem Token | Nicht als expliziter Unit-Test in test_spec (erfordert process.exit Mock) | Integration-Command abdeckend | OK |
| AC-2: Verzeichnisstruktur anlegen | Nicht in test_spec (Dateisystem-Seiteneffekt, akzeptabel) | Integration | OK |
| AC-3: 232 Bilder mit korrekten Pfaden | Nicht in test_spec (erfordert echte API, akzeptabel) | Integration | OK |
| AC-4: Idempotenz / skip existing | Logik trivial (`existsSync` Guard), kein expliziter Unit-Test | Code Review | OK |
| AC-5: Exponential Backoff 1s/2s/4s | 5 Unit-Tests fuer `withRetry()` mit `vi.useFakeTimers()` | Unit | OK |
| AC-6: Non-429-Fehler kein Retry | `withRetry()` Test "should NOT retry on non-rate-limit errors" | Unit | OK |
| AC-7: `buildPrompt()` = Template + motif | 5 Unit-Tests: motif-Containment, Template-Erweiterung, Index-Variation, Simple-Produkt, Laenge | Unit | OK |
| AC-8: Summary-Format | Format im Code explizit (`console.log("Summary: X generated, Y skipped, Z failed")`), kein dedizierter Test | Code Review | OK |
| CATEGORY_TEMPLATES vollstaendig (12 Kategorien) | "should have templates for all 12 categories" + Camera-Parameter-Test + Crop-Test | Unit | OK |
| `buildCategoryPrompt()` | "should build a category image prompt", "should include category-specific visual elements" | Unit | OK |

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| Bild-Generierung | Replicate Flux 2 Pro, Modell `black-forest-labs/flux-2-pro` | Yes | Yes — `replicate.run("black-forest-labs/flux-2-pro")` | OK |
| Bild-Generierung | 2 Bilder pro Produkt (verschiedene Winkel) | Yes | Yes — Loop `for (let i = 1; i <= 2; i++)`, Index-Variation in `buildPrompt()` | OK |
| Bild-Generierung | 1 Kategoriebild pro Kategorie (12 Stk.) | Yes | Yes — `buildCategoryPrompt()` + separate Task-Schleife | OK |
| Bild-Generierung | Speicherort `{cat-slug}/{slug}-{1|2}.webp` + `category-{slug}.webp` | Yes | Yes — Pfad-Konstruktion in `generateImage()` korrekt | OK |
| Prompt-Strategie | Lifestyle-Stil, Crop auf Produkt, Kamera-Parameter 85mm/f2.0-2.8 | Yes | Yes — alle 12 CATEGORY_TEMPLATES enthalten Crop-Anweisung und Kamera-Angaben | OK |
| Concurrency | Max 5 parallel | Yes | Yes — `MAX_CONCURRENCY = 5`, `runWithPool(tasks, MAX_CONCURRENCY)` | OK |
| Rate Limiting | Exponential backoff 1s/2s/4s bei 429 | Yes | Yes — `withRetry()` mit korrekten Delays | OK |
| Idempotenz | Skip wenn Bild vorhanden | Yes | Yes — `fs.existsSync(outputPath)` Guard | OK |
| Data | Gesamt ~232 Bilder (110x2 + 12) | Yes | Yes — Script-Logik: 220 Produktbilder + 12 Kategoriebilder | OK |
| Business Rules | Script laeuft lokal vor Docker-Seed | Yes | Yes — Scope-Abgrenzung explizit dokumentiert | OK |
| Aspect Ratio | 4:5 fuer Kleidung, 1:1 fuer Produkte | Yes | Yes — Kategorie-Liste in `generateImage()` steuert aspect_ratio | OK |

---

## Blocking Issues Summary

Keine Blocking Issues gefunden.

---

## Recommendations

1. **Optional:** Expliziter Unit-Test fuer AC-1 (Exit-Code bei fehlendem Token) mithilfe von `vi.spyOn(process, 'exit').mockImplementation(() => { throw new Error('exit') })` + `vi.stubEnv('REPLICATE_API_TOKEN', '')`. Nicht blockierend, da Integration-Command die Ausfuehrbarkeit des Moduls prueft.

2. **Optional:** Expliziter Unit-Test fuer AC-8 (Summary-Format) via `vi.spyOn(console, 'log')` mit Regex `Summary: \d+ generated, \d+ skipped, \d+ failed`. Nicht blockierend, da Format im Code klar definiert ist.

3. **Optional:** Die aspect_ratio-Logik in `generateImage()` (Zeile 678-680) bestimmt `4:5` vs. `1:1` via `label.split('/')[0]`. Ein Unit-Test der sicherstellt, dass `muetzen-caps` als `4:5`-Kategorie erkannt wird, waere sinnvoll — diese Einordnung ist nicht intuitiv offensichtlich. Nicht blockierend.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 0

**Begruendung:**
- Alle 8 ACs sind im GIVEN/WHEN/THEN-Format, spezifisch, testbar und messbar
- Der Import-Pfad in der test_spec ist korrekt: `../../../../scripts/generate-images.mjs` (4 Ebenen von `frontend/tests/slices/seed-data/` zum Projekt-Root)
- Kein Fallback-Widerspruch: `REPLICATE_API_TOKEN` wird konsistent und ausschliesslich verwendet
- `replicate.run()` wird korrekt eingesetzt, kein manuelles Polling
- Alle 5 Code Examples sind vollstaendig und ausfuehrbar
- Integration Contract vollstaendig (Inputs + Outputs mit Consumer-Angaben)
- DELIVERABLES_START/END Block vorhanden mit 3 Deliverables (Script, Config, Tests)
- Test-Strategy mit allen 3 Commands, Stack, Mocking-Strategy vollstaendig
- Architecture-Compliance: Modell-ID, API-Methode, Datei-Pfade, Security-Anforderungen alle erfullt

**Naechste Schritte:**
- Slice kann direkt zur Implementierung weitergegeben werden
- Kein Re-Check erforderlich

VERDICT: APPROVED
