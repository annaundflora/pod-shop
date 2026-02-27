# Feature: Seed Data — 100+ POD-Produkte mit KI-generierten Bildern

**Epic:** --
**Status:** Ready
**Wireframes:** -- (kein UI-Feature)

---

## Problem & Solution

**Problem:**
- Shop hat nur 3 Demo-Produkte in 2 Kategorien — wirkt leer und unrealistisch
- Keine Produktbilder vorhanden (`sourceUrl: null`)
- Featured Products nicht markiert → Homepage-Section leer
- Keine Reviews → Social Proof fehlt
- Keine Kategorie-Hierarchie → Navigation unbrauchbar für echten Shop-Eindruck

**Solution:**
- Erweiterte `seed-products.php` mit 110 handkuratierten POD-Produkten in 12 Kategorien
- Separates `generate-images.mjs` Script generiert 2 Bilder pro Produkt via Replicate Flux 2 Pro
- Bilder werden committet und beim Seed als WordPress-Medien importiert

**Business Value:**
- Realistischer Shop-Eindruck für Demos und Entwicklung
- Pagination, Suche, Filterung testbar mit echten Datenmengen
- Professionelle Produktbilder für ~$3 (200 Bilder × $0.015)

---

## Scope & Boundaries

| In Scope |
|----------|
| 3 Parent-Kategorien + 12 Unter-Kategorien mit Beschreibungen |
| 110 Produkte (variable + simple), handkuratierte Namen/Beschreibungen (DE/EN kreativ gemischt) |
| Bestehende Attribute: `pa_groesse` (S–XXL) + `pa_farbe` (Schwarz/Weiß/Grau/Navy) |
| 2 KI-generierte Bilder pro Produkt via Replicate Flux 2 Pro (1MP) |
| Separates Node.js-Script `scripts/generate-images.mjs` für Bildgenerierung |
| Optimierte Prompts pro Produktkategorie (Bild-Stil: Lifestyle, Produkt-fokussiert, Crop auf Produkt). Beispielprompts: `seed-data-prompts/prompts.md` |
| Definition der Motive/Designs pro Produkt (illustrative Prints, Schriftzüge, Muster — passend zum Stil der Beispielprompts) |
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
| Bildgenerierung für Kategorien im selben Script (kann separat behandelt werden) |

---

## Current State Reference

- `scripts/seed-products.php` — Erstellt 3 Produkte, 2 Kategorien, 2 Attribute, 4 Legal Pages
- `scripts/mock-data.sh` — Wrapper, ruft `seed-products.php` via `wp eval-file`, Idempotenz-Flag `pod_shop_mock_data_seeded`
- `scripts/setup.sh` — Ruft `mock-data.sh` automatisch auf
- WooCommerce Attribute `pa_groesse` + `pa_farbe` existieren bereits
- Variable Products mit Variationen (5 Größen × 4 Farben) werden bereits erstellt
- `wordpress/uploads/` Verzeichnis ist gemountet in Docker

---

## User Flow

1. Developer führt `node scripts/generate-images.mjs` aus → Script liest Produktliste, generiert 2 Bilder pro Produkt via Replicate API, speichert in `wordpress/uploads/products/`
2. Developer committet generierte Bilder
3. Developer startet `docker compose up -d` → `setup.sh` → `mock-data.sh` → `seed-products.php`
4. `seed-products.php` erstellt Kategorien → Produkte → importiert Bilder → setzt Featured → erstellt Reviews
5. Shop zeigt 110 Produkte mit Bildern, Kategorien, Featured-Section, Reviews

**Error Paths:**
- `REPLICATE_API_KEY` nicht gesetzt → Script bricht ab mit Fehlermeldung
- Replicate API Rate Limit → Script wartet und retried
- Bild-Dateien nicht vorhanden beim Seed → Produkt wird ohne Bild erstellt (graceful degradation)

---

## Business Rules

- Produkte in Kategorie "Kleidung" (T-Shirts, Hoodies, Sweatshirts, Tanktops, Langarmshirts): Variable Products mit Größe + Farbe
- Produkte in Kategorie "Accessoires" (Taschen, Mützen, Kissen): Variable Products mit nur Farbe (keine Größe)
- Produkte in Kategorie "Wohnen" (Tassen, Poster, Handyhüllen): Simple Products (keine Variationen)
- Preise pro Kategorie in realistischen Bereichen (siehe Data-Section)
- Alle Produkte bekommen eine `_spreadconnect_product_id` (Demo-ID-Format)
- ~10% der Produkte werden als Featured markiert
- Featured-Produkte bekommen 3–5 deutsche Mock-Reviews (Rating 3–5 Sterne)
- Seed bleibt idempotent (Check-Flag vor Ausführung)

---

## Data

### Kategorie-Struktur

| Parent | Unter-Kategorie | Slug | Produkt-Typ | Attribute |
|--------|----------------|------|-------------|-----------|
| Kleidung | T-Shirts | `t-shirts` | Variable | Größe + Farbe |
| Kleidung | Hoodies | `hoodies` | Variable | Größe + Farbe |
| Kleidung | Sweatshirts | `sweatshirts` | Variable | Größe + Farbe |
| Kleidung | Tanktops | `tanktops` | Variable | Größe + Farbe |
| Kleidung | Langarmshirts | `langarmshirts` | Variable | Größe + Farbe |
| Accessoires | Taschen | `taschen` | Variable | Farbe |
| Accessoires | Mützen & Caps | `muetzen-caps` | Variable | Farbe |
| Accessoires | Buttons & Anstecker | `buttons-anstecker` | Simple | — |
| Wohnen & Geschenke | Tassen | `tassen` | Simple | — |
| Wohnen & Geschenke | Poster & Kunstdrucke | `poster-kunstdrucke` | Simple | — |
| Wohnen & Geschenke | Kissen | `kissen` | Variable | Farbe |
| Wohnen & Geschenke | Handyhüllen | `handyhuellen` | Simple | — |

### Produktverteilung

| Kategorie | Anzahl | Preis-Range | Variationen pro Produkt |
|-----------|--------|-------------|------------------------|
| T-Shirts | 20 | €19,99 – €34,99 | 20 (5 Größen × 4 Farben) |
| Hoodies | 12 | €39,99 – €54,99 | 20 |
| Sweatshirts | 10 | €34,99 – €49,99 | 20 |
| Tanktops | 8 | €17,99 – €24,99 | 20 |
| Langarmshirts | 8 | €24,99 – €34,99 | 20 |
| Taschen | 10 | €14,99 – €29,99 | 4 (nur Farbe) |
| Mützen & Caps | 8 | €19,99 – €29,99 | 4 |
| Tassen | 10 | €12,99 – €19,99 | — (Simple) |
| Poster & Kunstdrucke | 10 | €9,99 – €24,99 | — (Simple) |
| Kissen | 6 | €24,99 – €34,99 | 4 |
| Handyhüllen | 8 | €14,99 – €19,99 | — (Simple) |
| **Gesamt** | **110** | | |

### Attribute-Werte

| Attribut | Werte |
|----------|-------|
| `pa_groesse` | S, M, L, XL, XXL |
| `pa_farbe` | Schwarz, Weiß, Grau, Navy |

### Bild-Generierung

| Feld | Wert |
|------|------|
| Provider | Replicate.com |
| Modell | Flux 2 Pro (`black-forest-labs/flux-2-pro`) |
| Auflösung | 1MP (~1024×1024) |
| Bilder pro Produkt | 2 (verschiedene Winkel/Perspektiven) |
| Bilder für Kategorien | 1 pro Kategorie (12 Stück) |
| Gesamt-Bilder | ~232 (110×2 + 12) |
| Geschätzte Kosten | ~$3.50 |
| Bild-Stil | Lifestyle, Produkt-fokussiert, Crop auf Produkt (Torso-Crop bei Kleidung), natürliches Licht |
| Speicherort | `wordpress/uploads/products/{category-slug}/{product-slug}-{1|2}.webp` |
| API-Key | `REPLICATE_API_KEY` in `.env` (Root-Level) |

### Prompt-Strategie (pro Kategorie)

**Beispielprompts:** `seed-data-prompts/prompts.md` — zeigen den Zielstil: photorealistische Lifestyle-Fotografie mit spezifischen Crops, detaillierten Motiv-Beschreibungen auf den Produkten, Pose/Props/Setting-Anweisungen, Kamera-Parameter (85mm, f/2.0–2.8, shallow DOF).

| Kategorie | Prompt-Fokus |
|-----------|-------------|
| T-Shirts | Torso-Crop (Kopf bis Mitte Oberschenkel), Person trägt Shirt, Motiv/Print zentriert auf Brust sichtbar, natürliches Licht, Props (Kaffee, Tasche) |
| Hoodies/Sweatshirts | Lips-to-Hips-Crop, lässige Pose, Stoff-Textur + Ribbed-Details, Motiv komplett sichtbar, Café/Indoor-Setting |
| Tanktops | Schulter-bis-Hüfte-Crop, sportlich-lässig, Outdoor-Setting |
| Langarmshirts | Torso + Ärmel sichtbar, casual Pose, Indoor warm |
| Taschen | 45°-Winkel, auf neutraler Oberfläche oder über Schulter getragen, Print/Muster sichtbar |
| Mützen & Caps | Kopf-Crop, seitlich oder frontal, natürlicher Hintergrund, Stickerei/Print sichtbar |
| Tassen | 45°-Winkel auf Holztisch, weiche Schatten, Motiv zur Kamera gedreht, Kaffee/Tee im Becher |
| Poster | Gerahmt an Wand in Wohnzimmer-Kontext, leicht schräg, Motiv vollständig sichtbar |
| Kissen | Auf Sofa/Bett, Lifestyle-Setting, Muster/Print frontal |
| Handyhüllen | In Hand gehalten, Phone-Screen aus, Hüllen-Design sichtbar, natürlicher Hintergrund |

### Motiv-Definition (Teil der Planung)

Jedes Produkt bekommt ein individuelles Motiv/Design, das im Prompt beschrieben wird. Die Motive werden im Planner pro Produkt definiert und orientieren sich am Stil der Beispielprompts:

**Stil-Richtung:** Trendy, feminin, cozy, cute — vielfältige Motive aus allen Bereichen. Nicht auf ein Thema beschränkt.

| Motiv-Typ | Beispiele | Geeignet für |
|-----------|-----------|-------------|
| Line-Art Illustration | Mediterrane Szene, Reise-Skylines, Café-Szenen, Yoga-Posen | T-Shirts, Sweatshirts, Taschen |
| Cute Characters | Frösche mit Pilzhut, schlafender Fuchs, Otter mit Kaffee, Biene im Blumengarten | Hoodies, Tassen, Kissen |
| Retro-Schriftzug + Illustration | "Good Vibes", "Stay Cozy", "Wildflower" mit passender Illustration | Sweatshirts, Tanktops, Tassen |
| Flat Vector Illustration | Stillleben mit Vasen + Pflanzen, Picknick-Szene, Bücherregal mit Kerzen | T-Shirts, Poster, Kissen |
| Botanische/Florale Motive | Wildblumen-Sträuße, Lavendel, Monstera, Trockenblumen-Arrangements | Taschen, Kissen, Handyhüllen, Poster |
| Typografie | Inspirierende Sprüche, Wortspiele, Handlettering-Stil (DE/EN) | Tassen, T-Shirts, Poster |
| Celestial/Mystisch | Mond-Phasen, Sternzeichen, Kristalle, Sonne + Sterne | Handyhüllen, Poster, Langarmshirts |
| Food & Drinks | Matcha Latte, Croissant, Erdbeer-Arrangement, Espresso-Tasse | Tassen, Tanktops, T-Shirts |
| Cottage/Cozy | Pilze im Wald, Regentropfen am Fenster, Strickpullover-Illustration, Leseecke | Hoodies, Kissen, Poster |

**Referenz-Datei:** `seed-data-prompts/prompts.md`

### Reviews (Mock-Daten)

| Feld | Wert |
|------|------|
| Anzahl | 3–5 pro Featured-Produkt (~40–50 Reviews gesamt) |
| Rating | 3–5 Sterne (gewichtet: 60% 5★, 25% 4★, 15% 3★) |
| Sprache | Deutsch |
| Namen | Deutsche Vornamen + Nachname-Initial (z.B. "Maria K.", "Thomas B.") |
| Inhalt | 1–2 Sätze, produktspezifisch (Qualität, Passform, Lieferung, Design) |

---

## Implementation Slices

### Dependencies

```
Slice 1 ──→ Slice 2 ──→ Slice 5
               ↑            ↑
Slice 3 ──→ Slice 4 ────────┘
```

### Slices

| # | Name | Scope | Testability | Dependencies |
|---|------|-------|-------------|--------------|
| 1 | Produktkatalog-Definition | PHP-Array/JSON mit allen 110 Produkten: Namen, Beschreibungen, Preise, Kategorien, SKUs, Spreadconnect-IDs | Array-Struktur validierbar, Anzahl pro Kategorie prüfbar | -- |
| 2 | Seed-Script Erweiterung | `seed-products.php` refactoren: Kategorie-Hierarchie, Simple + Variable Products, Featured-Markierung, Reviews, Idempotenz | `docker compose up -d` erstellt alle 110 Produkte, Kategorien, Reviews. GraphQL-Abfrage zeigt korrekte Daten | Slice 1 |
| 3 | Motiv-Definition pro Produkt | Für jedes der 110 Produkte ein konkretes Motiv/Design definieren (Illustration, Schriftzug, Muster). Trendy, feminin, cozy, cute — vielfältig aus allen Bereichen. Output: Motiv-Beschreibung pro Produkt als Teil des Produktkatalogs, die direkt in Bild-Prompts einfließt | Jedes Produkt hat eine Motiv-Beschreibung, die spezifisch genug für Flux 2 Pro ist. Review der Motive auf Vielfalt + Stil-Konsistenz | -- |
| 4 | Bild-Generierung Script | `scripts/generate-images.mjs`: Replicate API Integration (Flux 2 Pro), Prompts aus Produktkatalog + Motiv-Definition + Kategorie-Template zusammensetzen, Batch-Generierung, Retry bei Fehlern, Fortschritts-Anzeige. Referenz: `seed-data-prompts/prompts.md` | Script generiert Bilder, speichert in korrektem Pfad, API-Fehler werden gehandled | Slice 3 |
| 5 | Bild-Import im Seed | `seed-products.php` erweitern: Bilder aus `wordpress/uploads/products/` als WP-Medien importieren, Produkten + Kategorien zuweisen | Produkte haben Bilder in GraphQL Response (`image.sourceUrl` nicht null) | Slice 2, Slice 4 |

### Recommended Order

1. **Slice 1:** Produktkatalog-Definition — Datenbasis für alles
2. **Slice 3:** Motiv-Definition — Kreative Arbeit, kann parallel zu Slice 2
3. **Slice 2:** Seed-Script Erweiterung — Braucht Produktkatalog aus Slice 1
4. **Slice 4:** Bild-Generierung Script — Braucht Motive aus Slice 3
5. **Slice 5:** Bild-Import — Braucht Seed-Script + generierte Bilder

---

## Context & Research

### Similar Patterns in Codebase

| Feature | Location | Relevant because |
|---------|----------|------------------|
| Bestehendes Seed-Script | `scripts/seed-products.php` | Basis für Erweiterung, zeigt WP-CLI Product-Creation Pattern |
| Mock-Data Wrapper | `scripts/mock-data.sh` | Idempotenz-Pattern mit WP-Options Flag |
| Setup-Pipeline | `scripts/setup.sh` | Integration Point für Seed |
| Variationen-Erstellung | `scripts/seed-products.php` | Pattern für 5×4 Variationen-Matrix |
| Bild-Handling in GraphQL | `frontend/lib/graphql/queries.ts` | Zeigt erwartete Bild-Felder: `sourceUrl`, `altText`, `mediaDetails { width, height }` |

### Web Research

| Source | Finding |
|--------|---------|
| WooCommerce CSV Import Schema | Natives Import-Format für Bulk-Produkte, Alternative zu WP-CLI |
| WC Smooth Generator (GitHub) | Offizielles WooCommerce-Tool für Zufallsprodukte, aber ohne POD-Semantik |
| Spreadshirt ProductType API | Liefert echte Produkttypen, Farben, Größen — nicht genutzt (handkuratiert stattdessen) |
| Replicate Flux 2 Pro Pricing | 1MP: ~66 Generationen/$1, optimal für Produktbilder |
| WP-CLI `wp wc product create` | Vollständige API für Produkterstellung inkl. Variationen, Meta, Kategorien |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| -- | Keine offenen Fragen | -- | -- | -- |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-27 | Codebase | `seed-products.php` erstellt 3 Produkte, 2 Kategorien, 2 Attribute, 4 Legal Pages |
| 2026-02-27 | Codebase | Featured Products werden nicht markiert → Homepage leer |
| 2026-02-27 | Codebase | Keine Produktbilder vorhanden, `wordpress/uploads/2026/02/` ist leer |
| 2026-02-27 | Codebase | Keine Reviews im Seed, nur in Test-Fixtures |
| 2026-02-27 | Web | WC Smooth Generator: schnell aber generische englische Daten |
| 2026-02-27 | Web | WooCommerce CSV Import: Format dokumentiert, aber Variationen zweistufig |
| 2026-02-27 | Web | Spreadshirt API: `productTypes` Endpoint liefert echte Katalogdaten |
| 2026-02-27 | Web | Replicate Flux 2 Pro: 1MP für ~$0.015/Bild, ideal für Batch-Generierung |

---

## Q&A Log

| # | Frage | Antwort |
|---|-------|---------|
| 1 | Soll erst eine umfassende Recherche durchgeführt werden (Codebase + Web) oder direkt die Kernfragen beantwortet werden? | Recherche zuerst |
| 2 | Welcher Ansatz für Seed Data passt am besten? (A: PHP-Script erweitern, B: WC Smooth Generator, C: CSV-Import, E: Spreadshirt API + PHP) | A: PHP-Script erweitern — passt in bestehende Pipeline, volle Kontrolle |
| 3 | Wie viele Produkte werden benötigt? (10-20, 30-50, 100+) | 100+ Produkte für realistischen Shop-Eindruck und Performance-Tests |
| 4 | Welche Produktkategorien soll der Shop abdecken? (Nur Kleidung, + Accessoires, Volle POD-Palette) | Volle POD-Palette: Kleidung + Accessoires + Wohnen & Geschenke |
| 5 | Wie sollen Produktdaten generiert werden? (Handkuratiert, Faker, Mix) | Handkuratierte Vorlagen + Multiplikation — 10-15 Vorlagen pro Kategorie |
| 6 | Wie sollen Produktbilder beschafft werden? (Spreadshirt API, Picsum, Unsplash, Sonstiges) | Replicate.com Account vorhanden, KI-Bildgenerierung mit Flux 2 Pro auf Basis der Produktbeschreibung. 1MP reicht, ~66 Generationen/$1 |
| 7 | Wie soll der Replicate-Workflow funktionieren? (Separates Script, Teil des Seeds, Manuell) | Separates Script (generate-images.mjs), einmal laufen, Bilder committen |
| 8 | Welcher Bild-Stil? (Weißer Hintergrund, Lifestyle, Flat Lay) | Lifestyle mit Fokus auf Produkt, Crop (z.B. Kopf/Beine abgeschnitten bei T-Shirts). Optimierte Prompts pro Kategorie sind Teil des Scope |
| 9 | Sollen bestehende Attribute reichen oder neue dazu? (Größe+Farbe, Mehr Farben, Neue Attribute) | Größe + Farbe reichen |
| 10 | Soll pro Farbvariante ein Bild generiert werden? (1/Produkt, 1/Farbe, Hauptbild + 2-3) | 2 Bilder pro Produkt (nicht pro Farbe) |
| 11 | Passen Kategorie-Struktur und Produktverteilung? | Passt so |
| 12 | Sollen Produktnamen deutsch oder englisch sein? | Kreativ gemischt — wie es pro Produkt passt |
| 13 | Replicate-Modell und API-Key-Konfiguration? | Flux 2 Pro bestätigt, API-Key via `REPLICATE_API_KEY` in `.env` |
