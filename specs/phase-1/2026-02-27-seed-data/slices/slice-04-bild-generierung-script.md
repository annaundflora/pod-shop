# Slice 4: Bild-Generierung Script erstellen

> **Slice 4 von 5** fur `Seed Data — 100+ POD-Produkte mit KI-generierten Bildern`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-03-motiv-definition.md` |
> | **Nächster:** | `slice-05-bild-import-seed.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-04-bild-generierung-script` |
| **Test** | `pnpm test tests/slices/seed-data/slice-04-bild-generierung-script.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-produktkatalog-definition", "slice-03-motiv-definition"]` |

**Erklarung:**
- **ID**: Eindeutiger Identifier (wird fur Commits und Evidence verwendet)
- **Test**: Vitest Unit Tests gegen die Script-Module (buildPrompt, Kategorie-Templates, Retry-Logik)
- **E2E**: `false` — Script-Tests, kein Browser
- **Dependencies**: Slice 1 liefert `product-catalog.json` mit befullten `motif`-Feldern (nach Slice 3)

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` enthalt `next` + `vitest` Dependencies. Script ist Node.js ESM (`.mjs`).

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/seed-data/slice-04-bild-generierung-script.test.ts` |
| **Integration Command** | `node --input-type=module --eval "import('./scripts/generate-images.mjs').then(m => console.log('Module OK'))" 2>&1` |
| **Acceptance Command** | `pnpm test tests/slices/seed-data/slice-04-bild-generierung-script.test.ts` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `mock_external` |

**Erklarung:**
- **Stack**: typescript-nextjs (Vitest fuer Unit Tests des Script-Moduls)
- **Test Command**: Vitest — testet `buildPrompt()`, `CATEGORY_TEMPLATES`, `withRetry()` als exportierte Funktionen
- **Mocking Strategy**: `mock_external` — Replicate API wird in Tests gemodkt, keine echten API-Calls

---

## Slice-Ubersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Produktkatalog-Definition | Approved | `slice-01-produktkatalog-definition.md` |
| 2 | Seed-Script Erweiterung | Pending | `slice-02-seed-script-erweiterung.md` |
| 3 | Motiv-Definition pro Produkt | Approved | `slice-03-motiv-definition.md` |
| 4 | Bild-Generierung Script | Ready | `slice-04-bild-generierung-script.md` |
| 5 | Bild-Import im Seed | Pending | `slice-05-bild-import-seed.md` |

---

## Kontext & Ziel

Der Shop benotigt 232 KI-generierte WebP-Bilder (2 Bilder pro Produkt x 110 Produkte + 1 Kategoriebild x 12 Kategorien) fur den Seed. Das Script `scripts/generate-images.mjs` wird einmalig vom Entwickler lokal ausgefuhrt und die generierten Bilder werden committed. Das Script liest `scripts/product-catalog.json` (Slice 1 + 3 befullte Motiv-Felder), komponiert Prompts aus Kategorie-Template + Motiv-Beschreibung, ruft Replicate Flux 2 Pro auf und speichert WebP-Dateien.

**Aktuelle Probleme:**
1. `scripts/generate-images.mjs` existiert noch nicht
2. `.env.example` enthalt einen echten Replicate API Key — muss durch Platzhalter ersetzt werden
3. Env-Var-Name-Konflikt: architecture.md nennt `REPLICATE_API_KEY`, die korrekte Var ist `REPLICATE_API_TOKEN` (laut Replicate SDK)

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Server Logic / Business Logic Flow

```
Developer Workflow (Phase 1):

generate-images.mjs
  ├── Read product catalog (scripts/product-catalog.json)
  ├── Build prompts (motif + category template + style params)
  ├── Replicate API (Flux 2 Pro, concurrent batch, max 5 parallel)
  │   └── replicate.run("black-forest-labs/flux-2-pro", { input })
  │       → output ist FileOutput-Objekt
  │       → output.url() → URL des generierten Bildes
  │       → fs.writeFile(path, output) → direkt schreiben
  └── Save: wordpress/uploads/products/{cat-slug}/{slug}-N.webp
       Kategorie-Bilder: wordpress/uploads/products/{cat-slug}/category-{slug}.webp
```

### 1. Architektur-Impact

| Layer | Anderungen |
|-------|------------|
| `scripts/generate-images.mjs` | NEU — Batch-Bildgenerierung via Replicate SDK |
| `.env.example` | Pflichtfeld `REPLICATE_API_TOKEN` hinzufugen, echten Key ersetzen |
| `wordpress/uploads/products/` | Wird vom Script erstellt (mkdir -p Pattern) |

### 2. Datenfluss

```
scripts/product-catalog.json (Slice 1 + 3 Output)
  ↓ JSON.parse(readFileSync())
generate-images.mjs
  ↓ buildPrompt(product, categoryTemplate)
  ↓ {kategorie-template} + {motif-description} + {kamera-parameter}
replicate.run("black-forest-labs/flux-2-pro", { input })
  ↓ FileOutput-Objekt
  ↓ fs.writeFile(outputPath, output)
wordpress/uploads/products/{cat-slug}/{product-slug}-{1|2}.webp
wordpress/uploads/products/{cat-slug}/category-{cat-slug}.webp
```

### 3. Replicate API Integration (KORREKTE Methode)

**WICHTIG:** Das Script verwendet `replicate.run()` aus dem `replicate` npm SDK (v1.4.0). Das ist KEIN manuelles Polling via HTTP-Requests. Die architecture.md beschreibt falschlicherweise manuelles Polling — die korrekte Methode ist:

```javascript
import Replicate from "replicate";
import fs from "node:fs";

const replicate = new Replicate({
  auth: process.env.REPLICATE_API_TOKEN,  // REPLICATE_API_TOKEN, nicht REPLICATE_API_KEY
});

const input = {
  prompt: buildPrompt(product, template),
  resolution: "1 MP",
  aspect_ratio: "4:5",        // Portrait fur Lifestyle-Fotos
  input_images: [],
  output_format: "webp",
  output_quality: 80,
  safety_tolerance: 2
};

const output = await replicate.run("black-forest-labs/flux-2-pro", { input });
// output ist ein FileOutput-Objekt
await fs.promises.writeFile(outputPath, output);  // Direkt schreiben
```

### 4. Prompt-Komposition

**Formel:** `{kategorie-template} + {motif-description} + {kamera-parameter}`

Konkret baut `buildPrompt(product, categoryTemplate, imageIndex)`:
1. Startet mit dem Kategorie-Template (Crop, Person/Objekt, Setting)
2. Fugt Motiv-Beschreibung aus `product.motif` ein (an der Stelle `{MOTIF_PLACEHOLDER}`)
3. Fuer Bild #2: Leichte Variation im Template (andere Perspektive/Setting-Variation)
4. Kamera-Parameter sind bereits im Kategorie-Template enthalten (85mm, f/2.0-f/2.8)

### 5. Kategorie-Templates (12 Kategorien)

Basierend auf den Referenz-Prompts aus `seed-data-prompts/prompts.md`:

| Kategorie | Crop | Setting | Kamera |
|-----------|------|---------|--------|
| `t-shirts` | Torso-Crop: Kopf bis Mitte Oberschenkel | Warmes Wohnzimmer, Sofa, Stehlampe | 85mm, f/2.0-f/2.8 |
| `hoodies` | Lips-to-Hips | Outdoor-Cafe, Steinmauer, Grünpflanzen | 85mm, f/2.0-f/2.8 |
| `sweatshirts` | Lips-to-Hips | Wohnzimmer-Indoor, Couch, Holzregal | 85mm, f/2.0-f/2.8 |
| `tanktops` | Schulter-bis-Hufte | Outdoor-Garten, Sonnenlicht | 85mm, f/2.0-f/2.8 |
| `langarmshirts` | Torso + Armel | Indoor warm, Fenster, Morgenlicht | 85mm, f/2.0-f/2.8 |
| `taschen` | 45-Grad-Winkel auf neutraler Oberflache ODER uber Schulter | Marmor-Counter, Holztisch | 50mm, f/2.8 |
| `muetzen-caps` | Kopf-Crop, seitlich oder frontal | Outdoor, naturlicher Hintergrund | 85mm, f/2.0 |
| `tassen` | 45-Grad-Winkel auf Holztisch | Morgen-Kaffeesetting, warme Schatten | 50mm, f/2.8 |
| `poster-kunstdrucke` | Frontal, leicht schrag, gerahmt | Wohnzimmer-Wand, Weisswand-Nische | 50mm, f/4.0 |
| `kissen` | Frontal, Print sichtbar | Sofa/Bett Lifestyle-Setting | 50mm, f/2.8 |
| `handyhuellen` | In Hand gehalten, Designseite sichtbar | Naturlicher Hintergrund, Holztisch | 50mm, f/2.8 |
| `buttons-anstecker` | Nahaufnahme, flach auf Stoff | Jeansjakken-Revers, Pinboard | 50mm, f/4.0 |

### 6. Concurrency-Control (Promise-Pool)

Das Script nutzt ein Promise-Pool-Pattern mit max 5 parallelen Requests:

```javascript
async function runWithPool(tasks, poolSize) {
  const results = [];
  const executing = [];
  for (const task of tasks) {
    const p = task().then(r => {
      executing.splice(executing.indexOf(p), 1);
      return r;
    });
    results.push(p);
    executing.push(p);
    if (executing.length >= poolSize) {
      await Promise.race(executing);
    }
  }
  return Promise.allSettled(results);
}
```

### 7. Retry-Logik (Exponential Backoff)

```javascript
async function withRetry(fn, maxRetries = 3) {
  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      return await fn();
    } catch (err) {
      const isRateLimit = err?.response?.status === 429 || err?.message?.includes('429');
      if (!isRateLimit || attempt === maxRetries - 1) throw err;
      const delayMs = Math.pow(2, attempt) * 1000; // 1s, 2s, 4s
      console.warn(`Rate limit hit, retrying in ${delayMs}ms...`);
      await new Promise(r => setTimeout(r, delayMs));
    }
  }
}
```

### 8. Idempotenz

```javascript
// Vor jeder Bildgenerierung:
if (fs.existsSync(outputPath)) {
  stats.skipped++;
  console.log(`  Skipping (exists): ${outputPath}`);
  continue;
}
```

### 9. Externe Services / APIs

| Service | Zweck | Integration |
|---------|-------|-------------|
| Replicate API | Flux 2 Pro Bildgenerierung | `replicate` npm SDK v1.4.0 |

**Konfiguration:**
- Environment-Variable: `REPLICATE_API_TOKEN` (via `.env` Root-Level, dotenv-Parsing im Script)
- Modell: `black-forest-labs/flux-2-pro`
- Auflösung: `1 MP` (≈1024×1024)
- Format: `webp`, Quality: 80
- Aspect Ratio: `4:5` fur Kleidungs-Lifestyle-Fotos; `1:1` fur Produktobjekte (Tassen, Poster, Kissen, Taschen, Handyhullen, Mutzen)

**Error Handling:**
- 429 Rate Limit: Exponential backoff 1s → 2s → 4s, max 3 Retries
- Sonstige Fehler: Skip mit Warning, weitermachen, Summary zahlt Failed
- Fehlender Token: Exit(1) mit klarer Fehlermeldung vor Start

### 10. Abhangigkeiten

| Paket | Typ | Verwendung |
|-------|-----|-----------|
| `replicate` | npm (Root-Level) | Replicate SDK fur Flux 2 Pro |
| `dotenv` | npm (Root-Level) | `.env` lesen fur `REPLICATE_API_TOKEN` |
| `node:fs` | Built-in | Dateisystem-Operationen |
| `node:path` | Built-in | Pfad-Konstruktion |

**Neue Root-Level Pakete (nicht im Frontend):** Das Script lauft als reines Node.js Script — `replicate` und `dotenv` werden im Root `package.json` installiert (nicht in `frontend/`).

---

## Acceptance Criteria

1) GIVEN `REPLICATE_API_TOKEN` ist nicht gesetzt, WHEN `node scripts/generate-images.mjs` ausgefuhrt wird, THEN bricht das Script mit Exit-Code 1 und der Fehlermeldung "Error: REPLICATE_API_TOKEN is not set. Add it to your .env file." ab.

2) GIVEN ein gultig gesetzter API-Token, WHEN das Script startet, THEN erstellt es die Verzeichnisstruktur `wordpress/uploads/products/{cat-slug}/` fur alle 12 Kategorien (mkdir -p, keine Fehler bei bereits existierenden Verzeichnissen).

3) GIVEN das Script lauft gegen alle 110 Produkte, WHEN es vollstandig ist, THEN wurden 220 Produktbilder (je 2 pro Produkt) + 12 Kategoriebilder unter den korrekten Pfaden gespeichert: `wordpress/uploads/products/{cat-slug}/{product-slug}-1.webp`, `wordpress/uploads/products/{cat-slug}/{product-slug}-2.webp`, `wordpress/uploads/products/{cat-slug}/category-{cat-slug}.webp`.

4) GIVEN ein Produktbild existiert bereits unter dem korrekten Pfad, WHEN das Script erneut ausgefuhrt wird, THEN wird dieses Bild ubersprungen (kein API-Call, kein Uberschreiben) und der Summary zahlt es als "skipped".

5) GIVEN Replicate gibt HTTP 429 (Rate Limit) zuruck, WHEN das Script den Fehler empfangt, THEN wartet es 1 Sekunde (Versuch 1), 2 Sekunden (Versuch 2), 4 Sekunden (Versuch 3) vor erneutem Versuch (Exponential Backoff), und loggt jede Wartephase als Console Warning.

6) GIVEN eine Replicate-Prediction schlagt fehl (nicht-429-Fehler), WHEN der Fehler auftritt, THEN wird dieses einzelne Bild ubersprungen, das Script setzt die Verarbeitung der restlichen Bilder fort, und der Summary zahlt es als "failed".

7) GIVEN ein beliebiges Produkt mit befulltem `motif`-Feld, WHEN `buildPrompt(product, template, 1)` aufgerufen wird, THEN enthalt der erzeugte Prompt sowohl den Kategorie-spezifischen Template-Text als auch die `product.motif` Beschreibung als integrierten Teilstring.

8) GIVEN das Script vollstandig abgearbeitet hat, WHEN die Zusammenfassung ausgegeben wird, THEN zeigt sie genau drei Zahlen: Anzahl generierter Bilder, Anzahl ubersprungener Bilder (bereits vorhanden), und Anzahl fehlgeschlagener Bilder, im Format "Summary: X generated, Y skipped, Z failed".

---

## Testfalle

### Test-Datei

`tests/slices/seed-data/slice-04-bild-generierung-script.test.ts`

<test_spec>
```typescript
// tests/slices/seed-data/slice-04-bild-generierung-script.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'

// Die testbaren Funktionen werden als Named Exports aus dem Script exportiert.
// Das Script hat am Ende: export { buildPrompt, CATEGORY_TEMPLATES, withRetry, buildCategoryPrompt }
// Diese Exports sind NUR fuer Tests — das Script hat einen "if (process.argv[1] === fileURLToPath(import.meta.url))" Guard

import { buildPrompt, CATEGORY_TEMPLATES, withRetry, buildCategoryPrompt } from '../../../../scripts/generate-images.mjs'

const MOCK_PRODUCT = {
  slug: 'sunset-boulevard-tee',
  name: 'Sunset Boulevard Tee',
  category: 't-shirts',
  type: 'variable-size-color',
  motif: 'centered black line-art print: Mediterranean scene with arched white buildings, a small dome, terracotta balcony with potted plants; clean thin contour lines only, no fill colors, white background'
}

const MOCK_TASSE = {
  slug: 'matcha-morning-tasse',
  name: 'Matcha Morning Tasse',
  category: 'tassen',
  type: 'simple',
  motif: 'centered front-panel mug print: overhead view of a matcha latte in a ceramic cup with a simple leaf latte-art pattern; flat vector style, soft sage green and cream palette'
}

describe('slice-04: Bild-Generierung Script — Unit Tests', () => {

  describe('CATEGORY_TEMPLATES', () => {
    it('should have templates for all 12 categories', () => {
      const expectedCategories = [
        't-shirts', 'hoodies', 'sweatshirts', 'tanktops', 'langarmshirts',
        'taschen', 'muetzen-caps', 'tassen', 'poster-kunstdrucke',
        'kissen', 'handyhuellen', 'buttons-anstecker'
      ]
      expectedCategories.forEach(cat => {
        expect(CATEGORY_TEMPLATES, `Missing template for category: ${cat}`).toHaveProperty(cat)
        expect(typeof CATEGORY_TEMPLATES[cat]).toBe('string')
        expect(CATEGORY_TEMPLATES[cat].length).toBeGreaterThan(50)
      })
    })

    it('should include camera parameters in clothing templates', () => {
      const clothingCategories = ['t-shirts', 'hoodies', 'sweatshirts', 'tanktops', 'langarmshirts']
      clothingCategories.forEach(cat => {
        expect(
          CATEGORY_TEMPLATES[cat],
          `Template for ${cat} missing 85mm camera parameter`
        ).toMatch(/85mm/)
      })
    })

    it('should include crop instructions in clothing templates', () => {
      expect(CATEGORY_TEMPLATES['t-shirts']).toMatch(/crop|torso|thigh/i)
      expect(CATEGORY_TEMPLATES['hoodies']).toMatch(/lips|hips|crop/i)
      expect(CATEGORY_TEMPLATES['sweatshirts']).toMatch(/lips|hips|crop/i)
    })
  })

  describe('buildPrompt()', () => {
    it('should include the product motif in the generated prompt', () => {
      const prompt = buildPrompt(MOCK_PRODUCT, CATEGORY_TEMPLATES['t-shirts'], 1)
      expect(prompt).toContain('Mediterranean scene')
      expect(prompt).toContain('clean thin contour lines only')
    })

    it('should include the category template in the generated prompt', () => {
      const template = CATEGORY_TEMPLATES['t-shirts']
      const prompt = buildPrompt(MOCK_PRODUCT, template, 1)
      // The prompt should contain key phrases from the t-shirts template
      expect(prompt.length).toBeGreaterThan(template.length)
    })

    it('should return different prompts for image index 1 vs 2', () => {
      const template = CATEGORY_TEMPLATES['t-shirts']
      const prompt1 = buildPrompt(MOCK_PRODUCT, template, 1)
      const prompt2 = buildPrompt(MOCK_PRODUCT, template, 2)
      // Image 2 should have a variation marker or different perspective
      expect(prompt1).not.toBe(prompt2)
    })

    it('should build a prompt for simple products (tassen) correctly', () => {
      const prompt = buildPrompt(MOCK_TASSE, CATEGORY_TEMPLATES['tassen'], 1)
      expect(prompt).toContain('matcha')
      expect(prompt).toContain('sage green')
    })

    it('should produce a prompt with reasonable length (50-2000 chars)', () => {
      const prompt = buildPrompt(MOCK_PRODUCT, CATEGORY_TEMPLATES['t-shirts'], 1)
      expect(prompt.length).toBeGreaterThan(50)
      expect(prompt.length).toBeLessThanOrEqual(2000)
    })
  })

  describe('buildCategoryPrompt()', () => {
    it('should build a category image prompt for a given category slug', () => {
      const prompt = buildCategoryPrompt('t-shirts')
      expect(typeof prompt).toBe('string')
      expect(prompt.length).toBeGreaterThan(50)
    })

    it('should include category-specific visual elements', () => {
      const tshirtPrompt = buildCategoryPrompt('t-shirts')
      const tassenPrompt = buildCategoryPrompt('tassen')
      expect(tshirtPrompt).not.toBe(tassenPrompt)
    })
  })

  describe('withRetry()', () => {
    beforeEach(() => {
      vi.useFakeTimers()
    })

    it('should return result on first successful attempt', async () => {
      const mockFn = vi.fn().mockResolvedValue('success')
      const result = await withRetry(mockFn, 3)
      expect(result).toBe('success')
      expect(mockFn).toHaveBeenCalledTimes(1)
    })

    it('should retry on 429 rate limit error and succeed on second attempt', async () => {
      const rate429Error = Object.assign(new Error('rate limit'), { response: { status: 429 } })
      const mockFn = vi.fn()
        .mockRejectedValueOnce(rate429Error)
        .mockResolvedValueOnce('success after retry')

      const resultPromise = withRetry(mockFn, 3)
      // Advance timer for the 1s backoff
      await vi.advanceTimersByTimeAsync(1000)
      const result = await resultPromise

      expect(result).toBe('success after retry')
      expect(mockFn).toHaveBeenCalledTimes(2)
    })

    it('should throw after maxRetries exceeded on persistent rate limit', async () => {
      const rate429Error = Object.assign(new Error('rate limit'), { response: { status: 429 } })
      const mockFn = vi.fn().mockRejectedValue(rate429Error)

      const resultPromise = withRetry(mockFn, 3)
      // Advance through all backoff delays: 1s + 2s + 4s
      await vi.advanceTimersByTimeAsync(7000)

      await expect(resultPromise).rejects.toThrow('rate limit')
      expect(mockFn).toHaveBeenCalledTimes(3)
    })

    it('should NOT retry on non-rate-limit errors', async () => {
      const genericError = new Error('Model not found')
      const mockFn = vi.fn().mockRejectedValue(genericError)

      await expect(withRetry(mockFn, 3)).rejects.toThrow('Model not found')
      expect(mockFn).toHaveBeenCalledTimes(1)
    })

    it('should use exponential backoff delays: 1s, 2s, 4s', async () => {
      const delays: number[] = []
      const originalSetTimeout = global.setTimeout

      const rate429Error = Object.assign(new Error('rate limit'), { response: { status: 429 } })
      const mockFn = vi.fn()
        .mockRejectedValueOnce(rate429Error)
        .mockRejectedValueOnce(rate429Error)
        .mockResolvedValueOnce('success')

      const resultPromise = withRetry(mockFn, 3)
      await vi.advanceTimersByTimeAsync(1000)
      await vi.advanceTimersByTimeAsync(2000)
      const result = await resultPromise

      expect(result).toBe('success')
      expect(mockFn).toHaveBeenCalledTimes(3)
    })
  })
})
```
</test_spec>

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent gepruft. Unvollstandige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-produktkatalog-definition | `scripts/product-catalog.json` | JSON-Datei | EXISTS, parsable, `products[].motif` vorhanden |
| slice-03-motiv-definition | `product.motif` Felder befüllt | Data | Alle 110 `motif`-Felder sind non-empty strings in `product-catalog.json` |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `wordpress/uploads/products/{cat-slug}/{product-slug}-1.webp` | Datei | slice-05-bild-import-seed | Existiert im Dateisystem, gultiges WebP |
| `wordpress/uploads/products/{cat-slug}/{product-slug}-2.webp` | Datei | slice-05-bild-import-seed | Existiert im Dateisystem, gultiges WebP |
| `wordpress/uploads/products/{cat-slug}/category-{cat-slug}.webp` | Datei | slice-05-bild-import-seed | Existiert im Dateisystem, gultiges WebP, 12 Dateien |

### Integration Validation Tasks

- [ ] `scripts/product-catalog.json` existiert mit befüllten `motif`-Feldern (alle non-empty)
- [ ] `wordpress/uploads/products/` Verzeichnis wird vom Script erstellt
- [ ] Alle 232 WebP-Dateien existieren nach Script-Ausfuhrung
- [ ] Dateinamen-Konvention stimmt: `{product-slug}-1.webp`, `{product-slug}-2.webp`, `category-{cat-slug}.webp`

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind **PFLICHT-Deliverables**.
> Der Gate 2 Compliance Agent pruft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| Vollstandiger Script-Skeleton | Code Example 1 | YES | Komplettes `scripts/generate-images.mjs` |
| `buildPrompt()` Funktion | Code Example 2 | YES | Prompt-Komposition mit image index Variation |
| `CATEGORY_TEMPLATES` Map | Code Example 3 | YES | Alle 12 Kategorien mit vollen Templates |
| `runWithPool()` Concurrency | Code Example 4 | YES | Promise-Pool, max 5 parallel |
| `withRetry()` Retry-Logik | Code Example 5 | YES | Exponential backoff 1s/2s/4s |

### Code Example 1: Vollstandiger Script-Skeleton `scripts/generate-images.mjs`

```javascript
// scripts/generate-images.mjs
// Batch-Bildgenerierung via Replicate Flux 2 Pro
// Aufruf: node scripts/generate-images.mjs
// Voraussetzung: REPLICATE_API_TOKEN in .env (Root-Level)

import Replicate from 'replicate'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { readFileSync } from 'node:fs'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

// .env lesen (dotenv nicht zwingend notig — native dotenv parsing)
function loadDotenv() {
  const envPath = path.resolve(__dirname, '../.env')
  if (!fs.existsSync(envPath)) return
  const lines = readFileSync(envPath, 'utf-8').split('\n')
  for (const line of lines) {
    const trimmed = line.trim()
    if (!trimmed || trimmed.startsWith('#')) continue
    const eqIdx = trimmed.indexOf('=')
    if (eqIdx === -1) continue
    const key = trimmed.slice(0, eqIdx).trim()
    const val = trimmed.slice(eqIdx + 1).trim().replace(/^["']|["']$/g, '')
    if (!process.env[key]) process.env[key] = val
  }
}

loadDotenv()

// --- ENV CHECK ---
if (!process.env.REPLICATE_API_TOKEN) {
  console.error('Error: REPLICATE_API_TOKEN is not set. Add it to your .env file.')
  process.exit(1)
}

const replicate = new Replicate({
  auth: process.env.REPLICATE_API_TOKEN,
})

// --- KATALOG LADEN ---
const catalogPath = path.resolve(__dirname, 'product-catalog.json')
const catalog = JSON.parse(readFileSync(catalogPath, 'utf-8'))

const UPLOADS_BASE = path.resolve(__dirname, '../wordpress/uploads/products')
const MAX_CONCURRENCY = 5

// --- STATISTIK ---
const stats = { generated: 0, skipped: 0, failed: 0 }

// --- RETRY MIT EXPONENTIAL BACKOFF ---
export async function withRetry(fn, maxRetries = 3) {
  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      return await fn()
    } catch (err) {
      const isRateLimit =
        err?.response?.status === 429 ||
        err?.message?.includes('429') ||
        err?.status === 429
      if (!isRateLimit || attempt === maxRetries - 1) throw err
      const delayMs = Math.pow(2, attempt) * 1000 // 1000, 2000, 4000
      console.warn(`  Rate limit (429), retrying in ${delayMs / 1000}s... (attempt ${attempt + 1}/${maxRetries})`)
      await new Promise(r => setTimeout(r, delayMs))
    }
  }
}

// --- PROMISE-POOL (max N parallel) ---
export async function runWithPool(tasks, poolSize) {
  const results = []
  const executing = new Set()
  for (const task of tasks) {
    const p = Promise.resolve().then(() => task()).finally(() => executing.delete(p))
    results.push(p)
    executing.add(p)
    if (executing.size >= poolSize) {
      await Promise.race(executing)
    }
  }
  return Promise.allSettled(results)
}

// --- KATEGORIE-TEMPLATES ---
export const CATEGORY_TEMPLATES = {
  't-shirts': `Photorealistic lifestyle product photo, torso crop from the very top of the head down to just below mid-thigh. Young woman standing slightly angled, soft smile, shoulder-length wavy hair, natural makeup, small hoop earrings. Outfit: crewneck T-shirt with the following centered chest print: {MOTIF}. Light wash denim shorts or jeans. Props: holding a clear glass of iced coffee or a small leather shoulder bag. Setting: cozy living room, beige sofa background, warm table lamp, framed wall art, background softly blurred. Lighting: warm soft indoor light, golden lamp glow, gentle shadows, natural skin tones. Camera at eye level, 85mm lens, f/2.0-f/2.8 shallow depth of field, high detail fabric texture and realistic print placement on folds. No text, no logos, no watermark.`,

  'hoodies': `Photorealistic lifestyle product photo of a young woman seated at a small outdoor café table, cozy European street vibe. STRICT CROP: frame is from lips to hips only. Top edge cuts across mouth and chin (lips visible), bottom edge ends at hips/waistline. Do NOT show eyes, nose, forehead, full head, or legs. Outfit: slightly oversized hoodie with ribbed collar and cuffs, realistic fabric texture and folds. Centered chest print: {MOTIF}. Light blue jeans waistband visible at bottom. Props: round wooden café table partially visible, white ceramic coffee cup on table. Background: soft blurred stone wall and doorway, hint of greenery. Lighting: soft natural daylight, gentle warm highlights, no harsh shadows. 85mm lens, f/2.0-f/2.8, sharp focus on hoodie print and fabric texture. No watermarks, no logos.`,

  'sweatshirts': `Photorealistic lifestyle product photo, medium shot from lips to hips of a young woman standing indoors. STRICT CROP: lips to hips only, top edge cuts across mouth/chin (lips visible), bottom edge at waistband. She gently holds the sweatshirt collar with both hands (fingers visible), relaxed pose. Outfit: slightly oversized crewneck sweatshirt with ribbed collar and cuffs. Centered chest print: {MOTIF}. Light blue high-waisted jeans and a black leather belt. Background: cozy modern living room, blurred beige sofa with cushions, leafy plant on the left, wooden sideboard on the right with a white table lamp. Soft natural daylight from the left, gentle shadows, 50mm lens look, f/2.8. Sharp focus on print and fabric. No text, no logos, no watermark.`,

  'tanktops': `Photorealistic lifestyle product photo, shoulder-to-hips crop of a young woman outdoors on a sunny day. Top edge just below the chin, bottom edge at waistband. Outfit: fitted tank top with the following small centered chest print: {MOTIF}. High-waisted light denim shorts or athletic leggings. Hair pulled back loosely, natural sporty-casual styling. Setting: outdoor garden or park, soft dappled sunlight, green foliage background blurred. Props: holding a water bottle or sunglasses casually. Lighting: bright natural outdoor light, warm afternoon sun, slight rim lighting. 85mm lens, f/2.0-f/2.8, high detail fabric texture. No text, no logos, no watermark.`,

  'langarmshirts': `Photorealistic lifestyle product photo, lips-to-hips crop of a young woman standing indoors by a large window in morning light. Top edge cuts across chin (lips visible), bottom edge at waistband. 3/4 back or side view, head turned slightly toward window. Outfit: fitted crewneck long-sleeve shirt with full sleeve visible, centered chest print: {MOTIF}. Light blue jeans, waistband visible. Props: holding a warm beige ceramic mug near the window frame. Background: sheer curtain, warm sunlight from left, hint of indoor plant, wooden furniture, soft blurred neutral interior. Lighting: warm natural window light, golden highlights, soft shadows. 85mm lens, f/2.0-f/2.8. Sharp focus on shirt print and fabric. No watermarks, no logos.`,

  'taschen': `Photorealistic product photo of a canvas tote bag positioned at a 45-degree angle on a light marble counter surface, OR draped over one shoulder with a relaxed lifestyle pose (lips-to-hips crop). The bag's front panel is fully visible and centered, showing the complete print design: {MOTIF}. Natural cotton canvas texture, soft structured shape, stable carry handles. Bag color: natural ecru/off-white canvas unless the print specifies otherwise. Lighting: soft diffused daylight from the left, gentle shadows on the surface, minimal background clutter. Setting: minimalist kitchen counter with marble surface, or airy loft space. 50mm lens, f/2.8, sharp focus on bag print and fabric texture. No busy backgrounds. No text, no logos, no watermark other than the design.`,

  'muetzen-caps': `Photorealistic lifestyle product photo of a young woman, head crop (chin to just above crown, generous margins). She is wearing the cap/beanie which features the following design: {MOTIF}. Natural slightly side-angled pose, soft expression, hair either tucked in or naturally framing the face. Setting: outdoor casual — neutral concrete wall, autumn park, or urban street background, softly blurred. Lighting: natural soft daylight, even lighting, no harsh shadows on the cap embroidery/print. 85mm lens, f/2.0, sharp focus on cap design. No text, no logos, no watermark.`,

  'tassen': `Photorealistic product photo of a ceramic mug positioned at a 45-degree angle on a warm wooden table surface. The mug's front panel faces the camera showing the complete print design: {MOTIF}. The mug contains coffee or tea (liquid visible at top, slight steam optional). A small wooden coaster underneath. Soft morning light setting with warm golden tones. Props: a few coffee beans or a sprig of cinnamon beside the mug for styling. Background: blurred warm kitchen interior, wooden textures. Lighting: soft directional window light from the left, warm shadows, high detail ceramic glaze and print texture. 50mm lens, f/2.8. No text, no logos, no watermark other than mug design.`,

  'poster-kunstdrucke': `Photorealistic interior lifestyle photo of a framed art print hanging on a clean white wall in a modern living room. The poster/print inside the frame shows: {MOTIF}. The frame is a simple thin black or natural wood frame, slightly angled (5-10 degrees from straight-on) for a natural room vibe. The print fills the frame completely. Setting: minimal Scandinavian-style living room, white wall, small section of a neutral sofa or plant visible on the side, soft indirect light. Lighting: even soft ambient room light, no harsh reflections on the glass/frame. 50mm lens, f/4.0, sharp focus on print. No text, no logos, no watermark outside the print content.`,

  'kissen': `Photorealistic lifestyle photo of a decorative throw pillow propped upright on a light beige linen sofa or bed, print panel facing the camera. The pillow's front shows the complete design: {MOTIF}. Square pillow shape (45x45cm implied), natural fabric sides visible. Setting: cozy Scandinavian living room, soft neutral tones, blurred background with throw blanket and another plain pillow. Lighting: soft diffused daylight from the left, gentle shadows, warm neutral tones. 50mm lens, f/2.8, sharp focus on pillow print and fabric texture. No text, no logos, no watermark.`,

  'handyhuellen': `Photorealistic lifestyle photo of a smartphone case held in a young woman's hand, the back design panel facing the camera. The case features: {MOTIF}. The phone is held at a natural angle in one hand (no screen visible, screen faces away from camera). Hand crop only — wrist to fingertips. Setting: casual natural background — marble surface, wooden table, or outdoor natural light. Lighting: soft natural light, gentle shadow from hand, warm neutral tones. 50mm lens, f/2.8, sharp focus on case design. No screen content, no text, no logos, no watermark other than case design.`,

  'buttons-anstecker': `Photorealistic close-up product photo of a round button/pin badge measuring approximately 3.8cm diameter, featuring: {MOTIF}. The button is pinned to the lapel of a denim jacket, or lying flat on a natural linen fabric surface. Metal pin mechanism visible on the back if second product image. Lighting: soft diffused light, gentle shadow, crisp focus on button print. 50mm lens, f/4.0, shallow depth of field on the fabric surface. No text, no logos, no watermark other than button design.`
}

// --- PROMPT AUFBAUEN ---
export function buildPrompt(product, categoryTemplate, imageIndex) {
  const motif = product.motif || 'abstract geometric pattern in clean black linework'
  let template = categoryTemplate.replace('{MOTIF}', motif)

  // Bild 2: leichte Variation (andere Perspektive / Setting)
  if (imageIndex === 2) {
    const variations = {
      't-shirts': 'Alternative angle: model slightly turned 45 degrees, outdoor setting near a bright window or in a café, same crop and lighting principles.',
      'hoodies': 'Alternative setting: standing pose, indoor cozy apartment background instead of outdoor café, lips-to-hips crop maintained.',
      'sweatshirts': 'Alternative pose: arms at sides naturally, slightly different indoor setting (bookshelf or kitchen counter background), same crop.',
      'tanktops': 'Alternative: indoor bright studio or bedroom setting near a window with morning light, same shoulder-to-hips crop.',
      'langarmshirts': 'Alternative: facing more toward camera, coffee shop or cozy indoor setting instead of window, same crop.',
      'taschen': 'Alternative: bag photographed flat-lay from directly above on a textured linen surface, or hanging from a coat hook on a white wall.',
      'muetzen-caps': 'Alternative: 3/4 side profile rather than front, slightly different background (indoor café or studio background).',
      'tassen': 'Alternative: mug photographed from directly above (overhead shot), showing print on side and liquid surface inside, on a linen placemat.',
      'poster-kunstdrucke': 'Alternative: print leaning against a wall on a wooden shelf or bookcase, with small decorative objects beside it, not hung on wall.',
      'kissen': 'Alternative: pillow styled on a bed with white linen, morning light from a window, slightly different angle.',
      'handyhuellen': 'Alternative: case lying flat on a marble or wooden surface, slightly angled, design visible, no hand.',
      'buttons-anstecker': 'Alternative: multiple buttons arranged in a small cluster on a denim surface, showing the design from a slightly elevated angle.',
    }
    const variation = variations[product.category] || 'Slightly different angle and background setting, same lighting and crop principles.'
    template += ` ${variation}`
  }

  return template
}

// --- KATEGORIE-BILD PROMPT ---
export function buildCategoryPrompt(categorySlug) {
  const categoryImagePrompts = {
    't-shirts': `Photorealistic lifestyle product photo collage feel: two young women wearing different graphic T-shirts, standing together in a cozy outdoor café setting, warm afternoon light, laughing naturally, tops clearly visible and well-lit. Photorealistic, 85mm lens look, f/2.8, warm lifestyle photography.`,
    'hoodies': `Photorealistic lifestyle photo: person wearing an oversized hoodie, lips-to-hips crop, cozy outdoor autumn setting with fallen leaves, warm golden afternoon light. 85mm lens, f/2.0.`,
    'sweatshirts': `Photorealistic lifestyle photo: young woman wearing a stylish sweatshirt, lips-to-hips crop, sitting at a wooden café table with a coffee cup, warm indoor lighting. 50mm lens, f/2.8.`,
    'tanktops': `Photorealistic lifestyle photo: young woman wearing a tank top, shoulder-to-hips crop, outdoor sunny park setting, bright natural light, fresh and sporty vibe. 85mm lens, f/2.0.`,
    'langarmshirts': `Photorealistic lifestyle photo: person wearing a long-sleeve shirt, lips-to-hips crop, standing near a large window with morning light, cozy interior with plants and books. 85mm lens, f/2.0.`,
    'taschen': `Photorealistic product lifestyle photo: three canvas tote bags with different patterns arranged on a light marble surface, overhead 45-degree angle, soft diffused daylight. 50mm lens, f/2.8.`,
    'muetzen-caps': `Photorealistic lifestyle photo: young woman wearing a casual cap, head-and-shoulders crop, smiling softly, outdoor autumn street background, warm natural light. 85mm lens, f/2.0.`,
    'tassen': `Photorealistic product photo: three ceramic mugs with different prints arranged on a warm wooden table, morning coffee setting with steam, soft golden light. 50mm lens, f/2.8.`,
    'poster-kunstdrucke': `Photorealistic interior lifestyle photo: three framed art prints in different styles hanging on a white Scandinavian-style wall, minimal living room context visible, soft ambient light. 35mm lens, f/4.0.`,
    'kissen': `Photorealistic lifestyle photo: three decorative throw pillows with different prints arranged on a beige linen sofa, cozy living room styling, natural warm light. 50mm lens, f/2.8.`,
    'handyhuellen': `Photorealistic lifestyle photo: three phone cases with different designs laid flat on a marble surface at a slight angle, soft natural studio light, overhead perspective. 50mm lens, f/2.8.`,
    'buttons-anstecker': `Photorealistic close-up photo: six round button badges with different colorful designs scattered on a textured denim surface, soft directional light, macro detail visible. 50mm macro, f/4.0.`,
  }
  return categoryImagePrompts[categorySlug] || `Photorealistic product lifestyle photo for category: ${categorySlug}. Clean minimal background, soft natural light. 50mm lens, f/2.8.`
}

// --- EINZELNES BILD GENERIEREN ---
async function generateImage(prompt, outputPath, label) {
  if (fs.existsSync(outputPath)) {
    console.log(`  Skipping (exists): ${path.basename(outputPath)}`)
    stats.skipped++
    return
  }

  try {
    await withRetry(async () => {
      const input = {
        prompt,
        resolution: '1 MP',
        aspect_ratio: ['t-shirts', 'hoodies', 'sweatshirts', 'tanktops', 'langarmshirts', 'muetzen-caps'].includes(
          label.split('/')[0]
        ) ? '4:5' : '1:1',
        input_images: [],
        output_format: 'webp',
        output_quality: 80,
        safety_tolerance: 2,
      }

      const output = await replicate.run('black-forest-labs/flux-2-pro', { input })
      await fs.promises.writeFile(outputPath, output)
    }, 3)

    stats.generated++
  } catch (err) {
    console.warn(`  FAILED: ${path.basename(outputPath)} — ${err.message}`)
    stats.failed++
  }
}

// --- HAUPTPROGRAMM ---
async function main() {
  console.log(`POD Shop Image Generator`)
  console.log(`Products: ${catalog.products.length}, Categories: 12`)
  console.log(`Estimated images: ${catalog.products.length * 2 + 12} (${catalog.products.length * 2} product + 12 category)`)
  console.log(`Max concurrency: ${MAX_CONCURRENCY}`)
  console.log('')

  // Ausgabe-Verzeichnisse erstellen
  const catSlugs = [...new Set(catalog.products.map(p => p.category))]
  for (const slug of catSlugs) {
    await fs.promises.mkdir(path.join(UPLOADS_BASE, slug), { recursive: true })
  }

  // Alle Aufgaben sammeln
  const tasks = []
  let totalCount = 0

  for (const product of catalog.products) {
    const catDir = path.join(UPLOADS_BASE, product.category)
    const template = CATEGORY_TEMPLATES[product.category]
    if (!template) {
      console.warn(`No template for category: ${product.category}`)
      continue
    }

    for (let i = 1; i <= 2; i++) {
      const outputPath = path.join(catDir, `${product.slug}-${i}.webp`)
      const prompt = buildPrompt(product, template, i)
      const current = ++totalCount
      const label = `${product.category}/${product.slug}-${i}`
      tasks.push(async () => {
        process.stdout.write(`Generating image ${current}/${catalog.products.length * 2 + 12}: ${label}\n`)
        await generateImage(prompt, outputPath, label)
      })
    }
  }

  // Kategorie-Bilder
  for (const catSlug of catSlugs) {
    const outputPath = path.join(UPLOADS_BASE, catSlug, `category-${catSlug}.webp`)
    const prompt = buildCategoryPrompt(catSlug)
    const current = ++totalCount
    tasks.push(async () => {
      process.stdout.write(`Generating image ${current}/${totalCount}: category-${catSlug}\n`)
      await generateImage(prompt, outputPath, catSlug)
    })
  }

  // Mit Promise-Pool ausfuhren
  await runWithPool(tasks, MAX_CONCURRENCY)

  // Summary
  console.log('')
  console.log(`Summary: ${stats.generated} generated, ${stats.skipped} skipped, ${stats.failed} failed`)
}

// Script nur ausfuhren wenn direkt aufgerufen (nicht bei import in Tests)
if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main().catch(err => {
    console.error('Fatal error:', err.message)
    process.exit(1)
  })
}
```

### Code Example 2: `buildPrompt()` Funktion (extrahiert fur Ubersicht)

Siehe vollstandiger Script-Skeleton oben — `buildPrompt()` ist als Named Export implementiert.

**Prompt-Formel:**
```
{categoryTemplate (mit {MOTIF} Placeholder)}
  ↓ .replace('{MOTIF}', product.motif)
  ↓ + Variation-Suffix fur imageIndex === 2
= Fertiger Replicate-Prompt (max. 2000 Zeichen)
```

### Code Example 3: `.env.example` Update (Pflicht-Deliverable)

Die Datei `.env.example` muss den echten API Key durch einen Platzhalter ersetzen und `REPLICATE_API_TOKEN` korrekt benennen:

```bash
# Replicate.com — Image Generation (Flux 2 Pro)
# Get your token at: https://replicate.com/account/api-tokens
REPLICATE_API_TOKEN=your_replicate_api_token_here
```

**Hinweis:** Die bestehende Zeile `REPLICATE_API_KEY=r8_Yx8fazn2oI3Wt2BwPCHYIbI7teTJSdO1jL22v` wird:
1. Umbenannt von `REPLICATE_API_KEY` zu `REPLICATE_API_TOKEN`
2. Den echten Key durch `your_replicate_api_token_here` ersetzt

### Code Example 4: `runWithPool()` Concurrency-Control

```javascript
// Promise-Pool: max N parallele Promises
// Verwende Promise.race() um auf den nachsten freien Slot zu warten
export async function runWithPool(tasks, poolSize) {
  const results = []
  const executing = new Set()

  for (const task of tasks) {
    const p = Promise.resolve().then(() => task()).finally(() => executing.delete(p))
    results.push(p)
    executing.add(p)
    if (executing.size >= poolSize) {
      await Promise.race(executing)
    }
  }
  return Promise.allSettled(results)
}
```

### Code Example 5: `withRetry()` Retry-Logik

```javascript
// Exponential backoff bei 429-Fehlern
// Delays: 1s (attempt 0), 2s (attempt 1), 4s (attempt 2)
// Nicht-429-Fehler werden sofort weitergeworfen
export async function withRetry(fn, maxRetries = 3) {
  for (let attempt = 0; attempt < maxRetries; attempt++) {
    try {
      return await fn()
    } catch (err) {
      const isRateLimit =
        err?.response?.status === 429 ||
        err?.message?.includes('429') ||
        err?.status === 429
      if (!isRateLimit || attempt === maxRetries - 1) throw err
      const delayMs = Math.pow(2, attempt) * 1000 // 1000ms, 2000ms, 4000ms
      console.warn(`  Rate limit (429), retrying in ${delayMs / 1000}s...`)
      await new Promise(r => setTimeout(r, delayMs))
    }
  }
}
```

---

## Constraints & Hinweise

**Betrifft:**
- `scripts/generate-images.mjs` — Neue Datei, Node.js ESM
- `.env.example` — Echten Key entfernen, Variablen-Namen korrigieren
- Root-Level Abhangigkeiten (`replicate` npm Paket)

**Env-Var-Name-Korrektur:**
- `architecture.md` nennt fehlerhaft `REPLICATE_API_KEY`
- Korrekt laut Replicate SDK Dokumentation: `REPLICATE_API_TOKEN`

**Abgrenzung:**
- Das Script generiert Bilder und speichert sie — es importiert sie NICHT in WordPress (das ist Slice 5)
- Das Script lauft LOKAL vor `docker compose up` — NICHT im Docker-Container
- Die generierten Bilder werden committed — das Script muss nur einmalig ausgefuhrt werden
- Keine Abhangigkeit vom Next.js-Frontend; das Script ist ein reines Node.js Tool

**Aspect-Ratio-Entscheidung:**
- `4:5` (Portrait, 1080×1350) fur Kleidungs-Kategorie (t-shirts, hoodies, sweatshirts, tanktops, langarmshirts, muetzen-caps) — Lifestyle-Fotos mit Person
- `1:1` (Square, 1024×1024) fur alle anderen Kategorien (taschen, tassen, poster-kunstdrucke, kissen, handyhuellen, buttons-anstecker) — Produkt-fokussiert

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollstandig
- [x] Sicherheits-/Privacy-Aspekte bedacht (REPLICATE_API_TOKEN via .env, echter Key aus .env.example entfernt)
- [x] Kein UI — keine UX/Copy notwendig

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Scripts
- [ ] `scripts/generate-images.mjs` — Vollstandiges Node.js ESM Script: Env-Check, Katalog laden, Prompts bauen, Replicate `run()` aufrufen, Concurrency-Pool (max 5), Retry (exponential backoff), Idempotenz-Check, Progress-Anzeige, Summary. Exports: `buildPrompt`, `buildCategoryPrompt`, `CATEGORY_TEMPLATES`, `withRetry`, `runWithPool` fur Testbarkeit.

### Config
- [ ] `.env.example` — `REPLICATE_API_KEY` Zeile: echten API-Key durch Platzhalter `your_replicate_api_token_here` ersetzen, Variable umbenennen zu `REPLICATE_API_TOKEN`

### Tests
- [ ] `tests/slices/seed-data/slice-04-bild-generierung-script.test.ts` — Vitest-Tests fur `buildPrompt()` (motif integration, length, index variation), `CATEGORY_TEMPLATES` (12 Kategorien, Camera-Parameter), `withRetry()` (success, 429-retry, max-retry-exceeded, non-429-no-retry, backoff-delays), `buildCategoryPrompt()` (string output, uniqueness)
<!-- DELIVERABLES_END -->

---

## Links

- Architecture: `specs/phase-1/2026-02-27-seed-data/architecture.md`
- Discovery: `specs/phase-1/2026-02-27-seed-data/discovery.md`
- Referenz-Prompts: `seed-data-prompts/prompts.md`
- Approved Slice 1: `specs/phase-1/2026-02-27-seed-data/slices/slice-01-produktkatalog-definition.md`
- Approved Slice 3: `specs/phase-1/2026-02-27-seed-data/slices/slice-03-motiv-definition.md`
- Replicate SDK: https://github.com/replicate/replicate-javascript
