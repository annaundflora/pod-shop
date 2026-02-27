# Slice 1: Produktkatalog-Definition erstellen

> **Slice 1 von 5** für `Seed Data — 100+ POD-Produkte mit KI-generierten Bildern`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | — |
> | **Nächster:** | `slice-02-seed-script-erweiterung.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-01-produktkatalog-definition` |
| **Test** | `pnpm test tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `[]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit Tests gegen die JSON-Datei
- **Dependencies**: Keine — erster Slice, keine Vorbedingungen

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` enthält `next` + `vitest` Dependencies.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts` |
| **Integration Command** | `node -e "require('./scripts/product-catalog.json')"` |
| **Acceptance Command** | `pnpm test tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: typescript-nextjs (Next.js 16 + Vitest)
- **Test Command**: Vitest Tests lesen `scripts/product-catalog.json` direkt ein und prüfen Struktur + Daten
- **Integration Command**: Node.js JSON-Parse-Check als Smoke Test
- **Mocking Strategy**: `no_mocks` — Tests laufen gegen die statische JSON-Datei, kein Mocking nötig

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Produktkatalog-Definition | Ready | `slice-01-produktkatalog-definition.md` |
| 2 | Seed-Script Erweiterung | Pending | `slice-02-seed-script-erweiterung.md` |
| 3 | Motiv-Definition pro Produkt | Pending | `slice-03-motiv-definition.md` |
| 4 | Bild-Generierung Script | Pending | `slice-04-bild-generierung.md` |
| 5 | Bild-Import im Seed | Pending | `slice-05-bild-import.md` |

---

## Kontext & Ziel

Der Shop hat derzeit 3 Demo-Produkte in 2 Kategorien ohne Produktbilder, Featured-Markierungen oder Reviews. Das Ziel dieses Slices ist die Erstellung der zentralen Datenbasis `scripts/product-catalog.json` mit allen 110 handkuratierten POD-Produkten in 11 Unterkategorien (Buttons & Anstecker als leere Kategorie ohne Produkte) unter 3 Parent-Kategorien.

Diese JSON-Datei ist der gemeinsame Datenkern für:
- `scripts/seed-products.php` (Slice 2) — liest Katalog via `json_decode(file_get_contents())`
- `scripts/generate-images.mjs` (Slice 4) — liest Katalog für Prompt-Generierung
- Produktkatalog enthält `"motif": ""` Platzhalter, der in Slice 3 befüllt wird

**Aktuelle Probleme:**
1. `seed-products.php` hat 3 hardcoded Produkte ohne JSON-Datenbasis
2. Keine Kategorie-Hierarchie (Parent/Child) vorhanden
3. Kein Spreadconnect-ID-Schema definiert

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Server Logic / Data Flow

```
Developer Workflow:

1) generate-images.mjs
   Read product catalog (scripts/product-catalog.json)
     → Build prompts (catalog + category template + motif)
       → Replicate API → Download WebP
         → Save to wordpress/uploads/products/{category-slug}/{product-slug}-{1|2}.webp

2) docker compose up -d → setup.sh → mock-data.sh → seed-products.php
   json_decode(file_get_contents('/scripts/product-catalog.json'))
     → Create parent categories → Create child categories
       → Create products (variable/color-only/simple)
         → Set featured flag → Import images → Create reviews
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `scripts/product-catalog.json` | NEU — 110 Produkte + 15 Kategorien als JSON (Buttons & Anstecker: Kategorie vorhanden, 0 Produkte) |
| `scripts/seed-products.php` | Slice 2 — liest diesen Katalog |
| `scripts/generate-images.mjs` | Slice 4 — liest diesen Katalog |

### 2. Datenfluss

```
product-catalog.json (Slice 1 Output)
  ↓
seed-products.php (Slice 2) liest JSON
  ↓ json_decode(file_get_contents('/scripts/product-catalog.json'))
WooCommerce DB (110 Produkte, 15 Kategorien inkl. leerer Buttons-Kategorie)
  ↓
generate-images.mjs (Slice 4) liest JSON
  ↓ import catalog from '../scripts/product-catalog.json'
Replicate API → WebP Bilder
```

### 3. JSON-Schema Details

```
product-catalog.json
├── $schema_version: "1.0"
├── categories: Category[]
│   ├── name: string          (DE, z.B. "T-Shirts")
│   ├── slug: string          (z.B. "t-shirts")
│   ├── parent: string|null   (null für Parent-Kategorien, slug des Parents)
│   └── description: string   (DE, 1-2 Sätze)
└── products: Product[]
    ├── name: string           (DE/EN kreativ gemischt)
    ├── slug: string           (URL-safe, kebab-case, eindeutig)
    ├── description: string    (HTML, <p>-Tags, DE)
    ├── short_description: string  (DE, 1 Satz)
    ├── price: string          (z.B. "24.99", ohne Währungssymbol)
    ├── category: string       (slug der Unter-Kategorie)
    ├── type: string           ("variable-size-color" | "variable-color" | "simple")
    ├── spreadconnect_id: string  (Format: "demo-{category}-{nnn}")
    ├── featured: boolean      (true für ~10 Produkte)
    └── motif: string          (leer "" — wird in Slice 3 befüllt)
```

### 4. Produkt-Typ-Regeln (aus architecture.md Business Rules)

| Typ | Kategorien | Attribute | Variationen |
|-----|------------|-----------|-------------|
| `variable-size-color` | T-Shirts, Hoodies, Sweatshirts, Tanktops, Langarmshirts | `pa_groesse` (S/M/L/XL/XXL) + `pa_farbe` (Schwarz/Weiß/Grau/Navy) | 20 (5×4) |
| `variable-color` | Taschen, Mützen & Caps, Kissen | `pa_farbe` (Schwarz/Weiß/Grau/Navy) | 4 |
| `simple` | Tassen, Poster & Kunstdrucke, Handyhüllen | — | — |

### 5. SKU- und Spreadconnect-ID-Schema

| Feld | Format | Beispiel |
|------|--------|---------|
| `slug` | `{descriptor}-{product-type}` (kebab-case) | `sunset-boulevard-tee`, `cozy-bear-hoodie` |
| `spreadconnect_id` | `demo-{cat-prefix}-{nnn}` (nnn = dreistellig, nullpadded) | `demo-tshirt-001`, `demo-hoodie-012` |

**Kategorie-Prefixes für Spreadconnect-IDs:**

| Kategorie | Prefix |
|-----------|--------|
| t-shirts | `tshirt` |
| hoodies | `hoodie` |
| sweatshirts | `sweat` |
| tanktops | `tank` |
| langarmshirts | `longsleeve` |
| taschen | `bag` |
| muetzen-caps | `cap` |
| buttons-anstecker | `button` (Kategorie vorhanden, keine Produkte in Slice 1) |
| tassen | `mug` |
| poster-kunstdrucke | `poster` |
| kissen | `pillow` |
| handyhuellen | `case` |

---

## Acceptance Criteria

1) GIVEN `scripts/product-catalog.json` existiert, WHEN ein Entwickler `node -e "require('./scripts/product-catalog.json')"` ausführt, THEN parst die Datei ohne Fehler (valid JSON)

2) GIVEN der Katalog, WHEN die Produkte pro Kategorie gezählt werden, THEN stimmen die Totals mit der Spezifikation überein: T-Shirts=20, Hoodies=12, Sweatshirts=10, Tanktops=8, Langarmshirts=8, Taschen=10, Mützen=8, Buttons=0, Tassen=10, Poster=10, Kissen=6, Handyhüllen=8 (Gesamt: exakt 110)

3) GIVEN der Katalog, WHEN die `featured`-Felder geprüft werden, THEN haben 8–12 Produkte `"featured": true`

4) GIVEN ein Produkt mit Typ `variable-size-color`, WHEN sein `type`-Feld geprüft wird, THEN ist der Wert exakt `"variable-size-color"`

5) GIVEN alle Produkte, WHEN die `spreadconnect_id`-Felder geprüft werden, THEN folgen alle dem Pattern `demo-{category}-{nnn}` mit ausschließlich eindeutigen Werten (keine Duplikate)

6) GIVEN alle Produkte, WHEN die `slug`-Felder auf Eindeutigkeit geprüft werden, THEN teilen keine zwei Produkte den gleichen Slug

7) GIVEN der Katalog, WHEN alle `motif`-Felder geprüft werden, THEN existiert das Feld in jedem Produkt (darf leer string `""` sein)

8) GIVEN alle Kategorien, WHEN `parent`-Felder geprüft werden, THEN haben die 3 Parent-Kategorien `"parent": null` und alle 12 Unter-Kategorien einen gültigen Parent-Slug

---

## Testfälle

### Test-Datei

`tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts`

<test_spec>
```typescript
// tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts
import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const catalogPath = resolve(__dirname, '../../../../scripts/product-catalog.json')
const catalog = JSON.parse(readFileSync(catalogPath, 'utf-8'))

type Category = {
  name: string
  slug: string
  parent: string | null
  description: string
}

type Product = {
  name: string
  slug: string
  description: string
  short_description: string
  price: string
  category: string
  type: 'variable-size-color' | 'variable-color' | 'simple'
  spreadconnect_id: string
  featured: boolean
  motif: string
}

describe('Produktkatalog-Definition (slice-01)', () => {

  describe('JSON-Struktur', () => {
    it('should have a categories array', () => {
      expect(Array.isArray(catalog.categories)).toBe(true)
    })

    it('should have a products array', () => {
      expect(Array.isArray(catalog.products)).toBe(true)
    })

    it('should have at least 15 categories (3 parent + 12 child)', () => {
      expect(catalog.categories.length).toBeGreaterThanOrEqual(15)
    })

    it('should have exactly 110 products', () => {
      expect(catalog.products.length).toBe(110)
    })
  })

  describe('Kategorie-Hierarchie', () => {
    it('should have exactly 3 parent categories with parent: null', () => {
      const parents = catalog.categories.filter((c: Category) => c.parent === null)
      expect(parents.length).toBe(3)
    })

    it('should have parent categories: Kleidung, Accessoires, Wohnen & Geschenke', () => {
      const parentSlugs = catalog.categories
        .filter((c: Category) => c.parent === null)
        .map((c: Category) => c.slug)
      expect(parentSlugs).toContain('kleidung')
      expect(parentSlugs).toContain('accessoires')
      expect(parentSlugs).toContain('wohnen-geschenke')
    })

    it('should have exactly 12 child categories with non-null parent', () => {
      const children = catalog.categories.filter((c: Category) => c.parent !== null)
      expect(children.length).toBe(12)
    })

    it('should have all child categories reference valid parent slugs', () => {
      const parentSlugs = new Set(
        catalog.categories
          .filter((c: Category) => c.parent === null)
          .map((c: Category) => c.slug)
      )
      const children = catalog.categories.filter((c: Category) => c.parent !== null)
      children.forEach((child: Category) => {
        expect(parentSlugs.has(child.parent as string), `Child '${child.slug}' has unknown parent '${child.parent}'`).toBe(true)
      })
    })

    it('should include all 12 required sub-categories', () => {
      const childSlugs = catalog.categories
        .filter((c: Category) => c.parent !== null)
        .map((c: Category) => c.slug)
      const required = [
        't-shirts', 'hoodies', 'sweatshirts', 'tanktops', 'langarmshirts',
        'taschen', 'muetzen-caps', 'buttons-anstecker',
        'tassen', 'poster-kunstdrucke', 'kissen', 'handyhuellen'
      ]
      required.forEach(slug => {
        expect(childSlugs, `Missing required category: ${slug}`).toContain(slug)
      })
    })
  })

  describe('Produktverteilung nach Kategorie', () => {
    const countByCategory = (slug: string) =>
      catalog.products.filter((p: Product) => p.category === slug).length

    it('should have 20 T-Shirts', () => {
      expect(countByCategory('t-shirts')).toBe(20)
    })

    it('should have 12 Hoodies', () => {
      expect(countByCategory('hoodies')).toBe(12)
    })

    it('should have 10 Sweatshirts', () => {
      expect(countByCategory('sweatshirts')).toBe(10)
    })

    it('should have 8 Tanktops', () => {
      expect(countByCategory('tanktops')).toBe(8)
    })

    it('should have 8 Langarmshirts', () => {
      expect(countByCategory('langarmshirts')).toBe(8)
    })

    it('should have 10 Taschen', () => {
      expect(countByCategory('taschen')).toBe(10)
    })

    it('should have 8 Mützen & Caps', () => {
      expect(countByCategory('muetzen-caps')).toBe(8)
    })

    it('should have 0 Buttons & Anstecker products (category exists but no products in v1.0)', () => {
      expect(countByCategory('buttons-anstecker')).toBe(0)
    })

    it('should have 10 Tassen', () => {
      expect(countByCategory('tassen')).toBe(10)
    })

    it('should have 10 Poster & Kunstdrucke', () => {
      expect(countByCategory('poster-kunstdrucke')).toBe(10)
    })

    it('should have 6 Kissen', () => {
      expect(countByCategory('kissen')).toBe(6)
    })

    it('should have 8 Handyhüllen', () => {
      expect(countByCategory('handyhuellen')).toBe(8)
    })
  })

  describe('Produkt-Typen', () => {
    const clothing = ['t-shirts', 'hoodies', 'sweatshirts', 'tanktops', 'langarmshirts']
    const colorOnly = ['taschen', 'muetzen-caps', 'kissen']
    const simple = ['tassen', 'poster-kunstdrucke', 'handyhuellen']

    it('should assign variable-size-color to clothing categories', () => {
      const clothingProducts = catalog.products.filter((p: Product) => clothing.includes(p.category))
      clothingProducts.forEach((p: Product) => {
        expect(p.type, `Product '${p.name}' in category '${p.category}' should be variable-size-color`).toBe('variable-size-color')
      })
    })

    it('should assign variable-color to color-only categories', () => {
      const colorProducts = catalog.products.filter((p: Product) => colorOnly.includes(p.category))
      colorProducts.forEach((p: Product) => {
        expect(p.type, `Product '${p.name}' in category '${p.category}' should be variable-color`).toBe('variable-color')
      })
    })

    it('should assign simple to simple-product categories', () => {
      const simpleProducts = catalog.products.filter((p: Product) => simple.includes(p.category))
      simpleProducts.forEach((p: Product) => {
        expect(p.type, `Product '${p.name}' in category '${p.category}' should be simple`).toBe('simple')
      })
    })
  })

  describe('Featured-Markierung', () => {
    it('should have 8-12 featured products', () => {
      const featuredCount = catalog.products.filter((p: Product) => p.featured === true).length
      expect(featuredCount).toBeGreaterThanOrEqual(8)
      expect(featuredCount).toBeLessThanOrEqual(12)
    })
  })

  describe('Spreadconnect-IDs', () => {
    it('should have all spreadconnect_ids matching pattern demo-{category}-{nnn}', () => {
      const pattern = /^demo-[a-z]+-\d{3}$/
      catalog.products.forEach((p: Product) => {
        expect(
          pattern.test(p.spreadconnect_id),
          `Product '${p.name}' has invalid spreadconnect_id: '${p.spreadconnect_id}'`
        ).toBe(true)
      })
    })

    it('should have unique spreadconnect_ids', () => {
      const ids = catalog.products.map((p: Product) => p.spreadconnect_id)
      const uniqueIds = new Set(ids)
      expect(uniqueIds.size).toBe(ids.length)
    })
  })

  describe('Slug-Eindeutigkeit', () => {
    it('should have unique product slugs', () => {
      const slugs = catalog.products.map((p: Product) => p.slug)
      const uniqueSlugs = new Set(slugs)
      expect(uniqueSlugs.size).toBe(slugs.length)
    })

    it('should have slugs in kebab-case format', () => {
      const kebabPattern = /^[a-z0-9]+(-[a-z0-9]+)*$/
      catalog.products.forEach((p: Product) => {
        expect(
          kebabPattern.test(p.slug),
          `Product slug '${p.slug}' is not valid kebab-case`
        ).toBe(true)
      })
    })
  })

  describe('Pflichtfelder', () => {
    it('should have motif field on every product (may be empty string)', () => {
      catalog.products.forEach((p: Product) => {
        expect(p).toHaveProperty('motif')
        expect(typeof p.motif).toBe('string')
      })
    })

    it('should have all required fields on every product', () => {
      const requiredFields = ['name', 'slug', 'description', 'short_description', 'price', 'category', 'type', 'spreadconnect_id', 'featured', 'motif']
      catalog.products.forEach((p: Product) => {
        requiredFields.forEach(field => {
          expect(p, `Product '${(p as any).name || '?'}' missing field '${field}'`).toHaveProperty(field)
        })
      })
    })
  })

  describe('Preisbereiche', () => {
    const priceRanges: Record<string, [number, number]> = {
      't-shirts': [19.99, 34.99],
      'hoodies': [39.99, 54.99],
      'sweatshirts': [34.99, 49.99],
      'tanktops': [17.99, 24.99],
      'langarmshirts': [24.99, 34.99],
      'taschen': [14.99, 29.99],
      'muetzen-caps': [19.99, 29.99],
      'tassen': [12.99, 19.99],
      'poster-kunstdrucke': [9.99, 24.99],
      'kissen': [24.99, 34.99],
      'handyhuellen': [14.99, 19.99],
    }

    Object.entries(priceRanges).forEach(([category, [min, max]]) => {
      it(`should have prices in range €${min}–€${max} for ${category}`, () => {
        const products = catalog.products.filter((p: Product) => p.category === category)
        products.forEach((p: Product) => {
          const price = parseFloat(p.price)
          expect(
            price >= min && price <= max,
            `Product '${p.name}' price €${price} outside range €${min}–€${max}`
          ).toBe(true)
        })
      })
    })
  })
})
```
</test_spec>

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| — | — | — | Kein Slice benötigt, erster Slice ohne Dependencies |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `scripts/product-catalog.json` | JSON-Datei | Slice 2 (`seed-products.php`) | `json_decode(file_get_contents('/scripts/product-catalog.json'))` → PHP-Array mit `categories[]` + `products[]` |
| `scripts/product-catalog.json` | JSON-Datei | Slice 3 (Motiv-Definition) | Fügt `motif`-Felder pro Produkt ein — setzt leere `""` Platzhalter voraus |
| `scripts/product-catalog.json` | JSON-Datei | Slice 4 (`generate-images.mjs`) | `import catalog from '../scripts/product-catalog.json'` → `catalog.products[]` mit `motif`-Feld für Prompt-Generierung |

### Integration Validation Tasks

- [ ] `scripts/product-catalog.json` existiert und parst ohne Fehler
- [ ] Alle 110 Produkte haben `motif: ""` Platzhalter (Slice 3 kann diesen befüllen)
- [ ] Alle Category-Slugs stimmen mit den Seeds in Slice 2 überein
- [ ] Produkt-Typen (`variable-size-color`, `variable-color`, `simple`) sind korrekt zugeordnet, damit Slice 2 die richtigen WooCommerce-Produkttypen erstellt

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind **PFLICHT-Deliverables**.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| JSON-Schema (vollständige Struktur) | Schema-Dokumentation | YES | Kommentar-Block am Anfang der Datei |
| T-Shirt Beispiel-Produkt | Code Example 1 | YES | variable-size-color |
| Hoodie Beispiel-Produkt | Code Example 2 | YES | variable-size-color, featured |
| Taschen Beispiel-Produkt | Code Example 3 | YES | variable-color |
| Tassen Beispiel-Produkt | Code Example 4 | YES | simple |
| Poster Beispiel-Produkt | Code Example 5 | YES | simple |

### Code Example: Vollständige JSON-Dateistruktur (Ausschnitt)

Das vollständige `scripts/product-catalog.json` muss exakt diese Struktur haben:

```json
{
  "_comment": "POD Shop Product Catalog v1.0 — 110 handkuratierte Produkte in 11 aktiven Produktkategorien + Buttons & Anstecker (Kategorie vorhanden, keine Produkte in v1.0). Dieses JSON ist die gemeinsame Datenbasis für seed-products.php (Slice 2) und generate-images.mjs (Slice 4). Das 'motif'-Feld wird in Slice 3 befüllt. Produkt-Typen: variable-size-color (Kleidung: 5 Groessen x 4 Farben), variable-color (Taschen/Muetzen/Kissen: 4 Farben), simple (Tassen/Poster/Handyhuellen). Spreadconnect-IDs: demo-{cat-prefix}-{nnn}. SKUs im Format {cat-prefix}-{nnn}.",
  "categories": [
    {
      "name": "Kleidung",
      "slug": "kleidung",
      "parent": null,
      "description": "Hochwertige Print-on-Demand Kleidung — von klassischen T-Shirts bis zu gemütlichen Hoodies, gefertigt auf Bestellung."
    },
    {
      "name": "Accessoires",
      "slug": "accessoires",
      "parent": null,
      "description": "Stylische Accessoires mit einzigartigen Prints — Taschen, Mützen und mehr für jeden Anlass."
    },
    {
      "name": "Wohnen & Geschenke",
      "slug": "wohnen-geschenke",
      "parent": null,
      "description": "Personalisierte Wohnaccessoires und Geschenkideen — von Tassen bis Postern, jedes Stück ein Unikat."
    },
    {
      "name": "T-Shirts",
      "slug": "t-shirts",
      "parent": "kleidung",
      "description": "Klassische und trendige T-Shirts in Premium-Qualität. Jedes Shirt wird individuell mit deinem Design bedruckt."
    },
    {
      "name": "Hoodies",
      "slug": "hoodies",
      "parent": "kleidung",
      "description": "Kuschelige Hoodies für die gemütlichen Momente. Warm, weich und mit deinem persönlichen Design."
    },
    {
      "name": "Sweatshirts",
      "slug": "sweatshirts",
      "parent": "kleidung",
      "description": "Vielseitige Sweatshirts für casual Style. Perfekt für entspannte Tage und lässige Outfits."
    },
    {
      "name": "Tanktops",
      "slug": "tanktops",
      "parent": "kleidung",
      "description": "Leichte Tanktops für warme Tage und sportliche Looks. Mit einzigartigen Prints für deinen Stil."
    },
    {
      "name": "Langarmshirts",
      "slug": "langarmshirts",
      "parent": "kleidung",
      "description": "Langarmshirts für kühlere Tage — vielseitig kombinierbar und individuell bedruckt."
    },
    {
      "name": "Taschen",
      "slug": "taschen",
      "parent": "accessoires",
      "description": "Stylische Tragetaschen und Totes mit einzigartigen Designs. Praktisch und modisch zugleich."
    },
    {
      "name": "Mützen & Caps",
      "slug": "muetzen-caps",
      "parent": "accessoires",
      "description": "Trendige Kopfbedeckungen mit individuellen Prints und Stickereien. Das i-Tüpfelchen für jedes Outfit."
    },
    {
      "name": "Buttons & Anstecker",
      "slug": "buttons-anstecker",
      "parent": "accessoires",
      "description": "Kleine aber feine Statements — Buttons und Anstecker mit witzigen Motiven und Sprüchen."
    },
    {
      "name": "Tassen",
      "slug": "tassen",
      "parent": "wohnen-geschenke",
      "description": "Dein Lieblingsgetränk schmeckt aus deiner eigenen Tasse am besten. Mit individuellen Motiven bedruckt."
    },
    {
      "name": "Poster & Kunstdrucke",
      "slug": "poster-kunstdrucke",
      "parent": "wohnen-geschenke",
      "description": "Kunstvolle Poster und Drucke für dein Zuhause. Jedes Motiv ein kleines Kunstwerk für deine Wände."
    },
    {
      "name": "Kissen",
      "slug": "kissen",
      "parent": "wohnen-geschenke",
      "description": "Dekorative Kissen mit einzigartigen Designs. Gemütlichkeit und Stil für dein Sofa oder Bett."
    },
    {
      "name": "Handyhüllen",
      "slug": "handyhuellen",
      "parent": "wohnen-geschenke",
      "description": "Schütze dein Smartphone mit Stil. Individuelle Handyhüllen mit deinen Lieblingsmotiven."
    }
  ],
  "products": [
    {
      "name": "Sunset Boulevard Tee",
      "slug": "sunset-boulevard-tee",
      "description": "<p>Ein weiches Premium-T-Shirt mit stimmungsvollem Sunset-Print. Gefertigt aus 100% gekämmter Baumwolle (180g/m²), individuell auf Bestellung gedruckt.</p><p>Material: 100% Baumwolle, 180g/m². Print-on-Demand gefertigt über Spreadconnect.</p>",
      "short_description": "Premium T-Shirt mit Sunset-Motiv. 5 Größen, 4 Farben.",
      "price": "24.99",
      "category": "t-shirts",
      "type": "variable-size-color",
      "spreadconnect_id": "demo-tshirt-001",
      "featured": true,
      "motif": ""
    },
    {
      "name": "Wildflower Tee",
      "slug": "wildflower-tee",
      "description": "<p>Luftiges T-Shirt mit botanischem Wildblumen-Design — für alle Naturliebhaberinnen. 100% Bio-Baumwolle, GOTS-zertifiziert (200g/m²).</p><p>Material: 100% Bio-Baumwolle, 200g/m². Print-on-Demand gefertigt über Spreadconnect.</p>",
      "short_description": "Bio-Baumwoll T-Shirt mit Wildblumen-Print. 5 Größen, 4 Farben.",
      "price": "29.99",
      "category": "t-shirts",
      "type": "variable-size-color",
      "spreadconnect_id": "demo-tshirt-002",
      "featured": false,
      "motif": ""
    },
    {
      "name": "Stay Cozy Hoodie",
      "slug": "stay-cozy-hoodie",
      "description": "<p>Der perfekte Hoodie für gemütliche Abende — mit weichem Innenfleece und liebevollem Stay-Cozy-Schriftzug. Känguru-Tasche und Kapuze mit Tunnelzug.</p><p>Material: 80% Baumwolle, 20% Polyester, 320g/m². Print-on-Demand gefertigt über Spreadconnect.</p>",
      "short_description": "Kuscheliger Hoodie mit 'Stay Cozy'-Print. 5 Größen, 4 Farben.",
      "price": "44.99",
      "category": "hoodies",
      "type": "variable-size-color",
      "spreadconnect_id": "demo-hoodie-001",
      "featured": true,
      "motif": ""
    },
    {
      "name": "Botanical Dreams Tote",
      "slug": "botanical-dreams-tote",
      "description": "<p>Geräumige Canvas-Tasche mit botanischem Illustration-Print. Perfekt für Einkauf, Strand oder tägliche Abenteuer. Stabiles Baumwollgewebe, langlebige Tragebänder.</p><p>Material: 100% Baumwoll-Canvas, 220g/m². Maße: 38×42 cm, Henkellänge 70 cm. Print-on-Demand gefertigt über Spreadconnect.</p>",
      "short_description": "Canvas-Tote mit botanischem Print. 4 Farben.",
      "price": "19.99",
      "category": "taschen",
      "type": "variable-color",
      "spreadconnect_id": "demo-bag-001",
      "featured": true,
      "motif": ""
    },
    {
      "name": "Matcha Morning Tasse",
      "slug": "matcha-morning-tasse",
      "description": "<p>Starte deinen Morgen mit Stil — diese Keramiktasse mit liebevollem Matcha-Latte-Motiv macht jeden Schluck besonders. Spülmaschinenfest und mikrowellengeeignet.</p><p>Material: Hochwertiges Porzellan, 325ml Fassungsvermögen. Spülmaschinenfest. Print-on-Demand gefertigt über Spreadconnect.</p>",
      "short_description": "Keramiktasse mit Matcha-Morning-Motiv. 325ml, spülmaschinenfest.",
      "price": "14.99",
      "category": "tassen",
      "type": "simple",
      "spreadconnect_id": "demo-mug-001",
      "featured": true,
      "motif": ""
    },
    {
      "name": "Moon Phases Poster",
      "slug": "moon-phases-poster",
      "description": "<p>Hochwertiger Kunstdruck mit stimmungsvollem Mondphasen-Motiv. Ideal für Wohnzimmer, Schlafzimmer oder als Geschenk. In verschiedenen Formaten erhältlich.</p><p>Material: Premium-Fotopapier, 250g/m², seidenmatt. Format: A3 (29,7×42 cm). Print-on-Demand gefertigt über Spreadconnect.</p>",
      "short_description": "Kunstdruck Mondphasen-Motiv auf Premium-Fotopapier, A3.",
      "price": "14.99",
      "category": "poster-kunstdrucke",
      "type": "simple",
      "spreadconnect_id": "demo-poster-001",
      "featured": false,
      "motif": ""
    }
  ]
}
```

**Hinweis:** Das obige JSON-Beispiel zeigt 6 Produkte als Referenz. Die vollständige `scripts/product-catalog.json` muss alle 110 Produkte enthalten. Die Kategorie `buttons-anstecker` ist in der `categories`-Liste vorhanden, enthält aber keine Produkte (für zukünftige Erweiterung reserviert). Die vollständige Produktliste mit allen 110 Einträgen ist das eigentliche Deliverable.

### Vollständige Produktliste — alle 110 Produkte

Die `scripts/product-catalog.json` muss alle 110 Produkte wie folgt verteilt enthalten. Die Kategorie `buttons-anstecker` ist in der `categories`-Liste vorhanden, enthält aber keine Produkte (für zukünftige Erweiterung reserviert):

**T-Shirts (20 Produkte, €19,99–€34,99, type: variable-size-color):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `sunset-boulevard-tee` | Sunset Boulevard Tee | 24.99 | true | demo-tshirt-001 |
| `wildflower-tee` | Wildflower Tee | 29.99 | false | demo-tshirt-002 |
| `good-vibes-tee` | Good Vibes Tee | 24.99 | false | demo-tshirt-003 |
| `celestial-star-tee` | Celestial Star Tee | 26.99 | false | demo-tshirt-004 |
| `botanical-garden-shirt` | Botanical Garden Shirt | 28.99 | false | demo-tshirt-005 |
| `matcha-lover-tee` | Matcha Lover Tee | 22.99 | false | demo-tshirt-006 |
| `retro-wave-tee` | Retro Wave Tee | 24.99 | false | demo-tshirt-007 |
| `mushroom-forest-tee` | Mushroom Forest Tee | 26.99 | false | demo-tshirt-008 |
| `cottagecore-vibes-shirt` | Cottagecore Vibes Shirt | 28.99 | false | demo-tshirt-009 |
| `lazy-sunday-tee` | Lazy Sunday Tee | 22.99 | false | demo-tshirt-010 |
| `espresso-yourself-tee` | Espresso Yourself Tee | 24.99 | false | demo-tshirt-011 |
| `strawberry-fields-shirt` | Strawberry Fields Shirt | 26.99 | false | demo-tshirt-012 |
| `moon-child-tee` | Moon Child Tee | 24.99 | false | demo-tshirt-013 |
| `fern-life-tee` | Fern Life Tee | 22.99 | false | demo-tshirt-014 |
| `yoga-flow-shirt` | Yoga Flow Shirt | 24.99 | false | demo-tshirt-015 |
| `cat-nap-tee` | Cat Nap Tee | 22.99 | false | demo-tshirt-016 |
| `picnic-day-shirt` | Picnic Day Shirt | 26.99 | false | demo-tshirt-017 |
| `reading-is-magic-tee` | Reading Is Magic Tee | 24.99 | false | demo-tshirt-018 |
| `lavender-dreams-tee` | Lavender Dreams Tee | 28.99 | false | demo-tshirt-019 |
| `golden-hour-shirt` | Golden Hour Shirt | 34.99 | false | demo-tshirt-020 |

**Hoodies (12 Produkte, €39,99–€54,99, type: variable-size-color):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `stay-cozy-hoodie` | Stay Cozy Hoodie | 44.99 | true | demo-hoodie-001 |
| `forest-bear-hoodie` | Forest Bear Hoodie | 46.99 | true | demo-hoodie-002 |
| `sleepy-fox-hoodie` | Sleepy Fox Hoodie | 44.99 | false | demo-hoodie-003 |
| `mushroom-cottage-hoodie` | Mushroom Cottage Hoodie | 46.99 | false | demo-hoodie-004 |
| `stargazer-hoodie` | Stargazer Hoodie | 44.99 | false | demo-hoodie-005 |
| `wildblumen-hoodie` | Wildblumen Hoodie | 48.99 | false | demo-hoodie-006 |
| `cafe-au-lait-hoodie` | Café au Lait Hoodie | 44.99 | false | demo-hoodie-007 |
| `frog-with-mushroom-hoodie` | Frog With Mushroom Hoodie | 46.99 | false | demo-hoodie-008 |
| `hygge-life-hoodie` | Hygge Life Hoodie | 44.99 | false | demo-hoodie-009 |
| `botanical-sketch-hoodie` | Botanical Sketch Hoodie | 48.99 | false | demo-hoodie-010 |
| `midnight-sky-hoodie` | Midnight Sky Hoodie | 49.99 | false | demo-hoodie-011 |
| `otter-cuddle-hoodie` | Otter Cuddle Hoodie | 54.99 | false | demo-hoodie-012 |

**Sweatshirts (10 Produkte, €34,99–€49,99, type: variable-size-color):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `cozy-corner-sweatshirt` | Cozy Corner Sweatshirt | 39.99 | false | demo-sweat-001 |
| `buecherliebe-sweatshirt` | Bücherliebe Sweatshirt | 39.99 | false | demo-sweat-002 |
| `rainy-day-sweatshirt` | Rainy Day Sweatshirt | 37.99 | false | demo-sweat-003 |
| `vintage-floral-sweat` | Vintage Floral Sweat | 42.99 | false | demo-sweat-004 |
| `mindful-mornings-sweat` | Mindful Mornings Sweat | 39.99 | false | demo-sweat-005 |
| `herb-garden-sweatshirt` | Herb Garden Sweatshirt | 44.99 | false | demo-sweat-006 |
| `bookclub-sweatshirt` | Bookclub Sweatshirt | 39.99 | false | demo-sweat-007 |
| `dried-flowers-sweat` | Dried Flowers Sweat | 42.99 | false | demo-sweat-008 |
| `fox-in-autumn-sweat` | Fox In Autumn Sweat | 44.99 | false | demo-sweat-009 |
| `velvet-garden-sweat` | Velvet Garden Sweat | 49.99 | false | demo-sweat-010 |

**Tanktops (8 Produkte, €17,99–€24,99, type: variable-size-color):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `golden-hour-tank` | Golden Hour Tank | 19.99 | false | demo-tank-001 |
| `tropical-vibes-tank` | Tropical Vibes Tank | 19.99 | false | demo-tank-002 |
| `yoga-girl-tank` | Yoga Girl Tank | 22.99 | false | demo-tank-003 |
| `wildflower-tank` | Wildflower Tank | 19.99 | false | demo-tank-004 |
| `summer-bloom-tank` | Summer Bloom Tank | 22.99 | false | demo-tank-005 |
| `citrus-fresh-tank` | Citrus Fresh Tank | 17.99 | false | demo-tank-006 |
| `boho-spirit-tank` | Boho Spirit Tank | 19.99 | false | demo-tank-007 |
| `morning-stretch-tank` | Morning Stretch Tank | 24.99 | false | demo-tank-008 |

**Langarmshirts (8 Produkte, €24,99–€34,99, type: variable-size-color):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `celestial-longsleeve` | Celestial Longsleeve | 27.99 | false | demo-longsleeve-001 |
| `crystal-cave-longsleeve` | Crystal Cave Longsleeve | 29.99 | false | demo-longsleeve-002 |
| `autumn-leaves-longsleeve` | Autumn Leaves Longsleeve | 27.99 | false | demo-longsleeve-003 |
| `botanical-line-longsleeve` | Botanical Line Longsleeve | 29.99 | false | demo-longsleeve-004 |
| `midnight-mushroom-longsleeve` | Midnight Mushroom Longsleeve | 27.99 | false | demo-longsleeve-005 |
| `starmap-longsleeve` | Starmap Longsleeve | 34.99 | false | demo-longsleeve-006 |
| `rainy-window-longsleeve` | Rainy Window Longsleeve | 27.99 | false | demo-longsleeve-007 |
| `cozy-cabin-longsleeve` | Cozy Cabin Longsleeve | 29.99 | false | demo-longsleeve-008 |

**Taschen (10 Produkte, €14,99–€29,99, type: variable-color):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `botanical-dreams-tote` | Botanical Dreams Tote | 19.99 | true | demo-bag-001 |
| `wildflower-tote` | Wildflower Tote | 17.99 | false | demo-bag-002 |
| `mushroom-garden-bag` | Mushroom Garden Bag | 19.99 | false | demo-bag-003 |
| `bookworm-tote` | Bookworm Tote | 17.99 | false | demo-bag-004 |
| `celestial-market-bag` | Celestial Market Bag | 21.99 | false | demo-bag-005 |
| `cottagecore-tote` | Cottagecore Tote | 17.99 | false | demo-bag-006 |
| `lavender-field-bag` | Lavender Field Bag | 22.99 | false | demo-bag-007 |
| `cat-lady-tote` | Cat Lady Tote | 19.99 | false | demo-bag-008 |
| `picnic-dreams-bag` | Picnic Dreams Bag | 24.99 | false | demo-bag-009 |
| `herb-witch-tote` | Herb Witch Tote | 29.99 | false | demo-bag-010 |

**Mützen & Caps (8 Produkte, €19,99–€29,99, type: variable-color):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `wildflower-cap` | Wildflower Cap | 22.99 | false | demo-cap-001 |
| `celestial-beanie` | Celestial Beanie | 24.99 | false | demo-cap-002 |
| `mushroom-snapback` | Mushroom Snapback | 22.99 | false | demo-cap-003 |
| `good-vibes-cap` | Good Vibes Cap | 19.99 | false | demo-cap-004 |
| `botanical-dad-hat` | Botanical Dad Hat | 24.99 | false | demo-cap-005 |
| `stargazer-beanie` | Stargazer Beanie | 22.99 | false | demo-cap-006 |
| `cottagecore-cap` | Cottagecore Cap | 24.99 | false | demo-cap-007 |
| `moon-phase-hat` | Moon Phase Hat | 29.99 | false | demo-cap-008 |

**Buttons & Anstecker (0 Produkte — Kategorie existiert, wird in zukünftigen Slices befüllt)**

**Tassen (10 Produkte, €12,99–€19,99, type: simple):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `matcha-morning-tasse` | Matcha Morning Tasse | 14.99 | true | demo-mug-001 |
| `espresso-yourself-tasse` | Espresso Yourself Tasse | 14.99 | false | demo-mug-002 |
| `botanical-brew-tasse` | Botanical Brew Tasse | 15.99 | false | demo-mug-003 |
| `moon-and-stars-tasse` | Moon And Stars Tasse | 15.99 | false | demo-mug-004 |
| `cottagecore-tasse` | Cottagecore Tasse | 14.99 | false | demo-mug-005 |
| `frog-pond-tasse` | Frog Pond Tasse | 15.99 | false | demo-mug-006 |
| `lazy-cat-tasse` | Lazy Cat Tasse | 14.99 | false | demo-mug-007 |
| `wildblumen-tasse` | Wildblumen Tasse | 15.99 | false | demo-mug-008 |
| `reading-corner-tasse` | Reading Corner Tasse | 14.99 | false | demo-mug-009 |
| `herb-witch-tasse` | Herb Witch Tasse | 19.99 | false | demo-mug-010 |

**Poster & Kunstdrucke (10 Produkte, €9,99–€24,99, type: simple):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `moon-phases-poster` | Moon Phases Poster | 14.99 | false | demo-poster-001 |
| `botanical-study-poster` | Botanical Study Poster | 16.99 | false | demo-poster-002 |
| `celestial-map-poster` | Celestial Map Poster | 19.99 | false | demo-poster-003 |
| `mushroom-forest-print` | Mushroom Forest Print | 14.99 | false | demo-poster-004 |
| `cottagecore-poster` | Cottagecore Poster | 12.99 | false | demo-poster-005 |
| `wildflower-meadow-print` | Wildflower Meadow Print | 16.99 | true | demo-poster-006 |
| `cat-bookshelf-poster` | Cat Bookshelf Poster | 14.99 | false | demo-poster-007 |
| `midnight-garden-print` | Midnight Garden Print | 19.99 | false | demo-poster-008 |
| `frog-teaparty-poster` | Frog Teaparty Poster | 12.99 | false | demo-poster-009 |
| `retro-travel-print` | Retro Travel Print | 24.99 | false | demo-poster-010 |

**Kissen (6 Produkte, €24,99–€34,99, type: variable-color):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `botanical-garden-kissen` | Botanical Garden Kissen | 27.99 | false | demo-pillow-001 |
| `moon-phases-kissen` | Moon Phases Kissen | 29.99 | false | demo-pillow-002 |
| `mushroom-cottage-kissen` | Mushroom Cottage Kissen | 27.99 | false | demo-pillow-003 |
| `wildflower-kissen` | Wildflower Kissen | 27.99 | true | demo-pillow-004 |
| `cat-nap-kissen` | Cat Nap Kissen | 24.99 | false | demo-pillow-005 |
| `celestial-kissen` | Celestial Kissen | 34.99 | false | demo-pillow-006 |

**Handyhüllen (8 Produkte, €14,99–€19,99, type: simple):**

| slug | name | price | featured | spreadconnect_id |
|------|------|-------|----------|-----------------|
| `botanical-phone-case` | Botanical Phone Case | 17.99 | false | demo-case-001 |
| `moon-phases-case` | Moon Phases Case | 17.99 | false | demo-case-002 |
| `wildflower-phone-case` | Wildflower Phone Case | 16.99 | false | demo-case-003 |
| `cottagecore-case` | Cottagecore Case | 16.99 | false | demo-case-004 |
| `mushroom-phone-case` | Mushroom Phone Case | 17.99 | false | demo-case-005 |
| `celestial-case` | Celestial Case | 19.99 | true | demo-case-006 |
| `cat-lady-case` | Cat Lady Case | 16.99 | false | demo-case-007 |
| `good-vibes-case` | Good Vibes Case | 14.99 | false | demo-case-008 |

---

## Constraints & Hinweise

**Betrifft:**
- `scripts/product-catalog.json` — Neue Datei, wird von PHP und Node.js gelesen

**JSON-Kompatibilität:**
- JSON erlaubt keine Kommentare — Schema-Dokumentation als `_comment`-Feld im Root-Objekt
- PHP: `json_decode(file_get_contents())` — Standard-JSON-Parsing, kein Problem
- Node.js (ESM): `import catalog from '../scripts/product-catalog.json' assert { type: 'json' }` oder `JSON.parse(readFileSync())`

**Abgrenzung:**
- Das `motif`-Feld bleibt leer `""` in diesem Slice — Befüllung erfolgt in Slice 3
- Keine Bildpfade in diesem Slice — Bildgenerierung ist Slice 4, Bild-Import ist Slice 5
- Die Produktbeschreibungen in diesem Slice sind vollständig genug für den Seed — Slice 3 ergänzt nur das Motiv-Feld
- Buttons & Anstecker: 0 Produkte in Slice 1 — die discovery.md Produktverteilungs-Tabelle führt diese Kategorie nicht auf (Gesamtsumme 110 ohne Buttons). Die Kategorie `buttons-anstecker` existiert in der `categories`-Sektion für zukünftige Erweiterungen, enthält aber keine Produkte.

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollständig
- [x] Sicherheits-/Privacy-Aspekte bedacht (statische JSON-Datei, keine sensitiven Daten)
- [x] Kein UI — keine UX/Copy notwendig

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Scripts
- [ ] `scripts/product-catalog.json` — Vollständige JSON-Datei mit allen 110 Produkten in 11 aktiven Produktkategorien + Buttons & Anstecker (leere Kategorie, 0 Produkte) + 3 Parent-Kategorien (15 Kategorien gesamt). Enthält `_comment`-Feld als Schema-Dokumentation. Alle Produkte haben leeres `"motif": ""` Platzhalter-Feld.

### Tests
- [ ] `tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts` — Vitest-Tests die die JSON-Struktur, Produktverteilung, Typen, Featured-Count, Slug-Eindeutigkeit, Spreadconnect-ID-Pattern und Preisbereiche validieren
<!-- DELIVERABLES_END -->

---

## Links

- Architecture: `specs/phase-1/2026-02-27-seed-data/architecture.md`
- Discovery: `specs/phase-1/2026-02-27-seed-data/discovery.md`
- Beispiel-Prompts: `seed-data-prompts/prompts.md`
- Bestehendes Seed-Script (Pattern-Referenz): `scripts/seed-products.php`
