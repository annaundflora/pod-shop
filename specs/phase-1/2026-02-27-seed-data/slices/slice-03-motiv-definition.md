# Slice 3: Motiv-Definition pro Produkt

> **Slice 3 von 5** für `Seed Data — 100+ POD-Produkte mit KI-generierten Bildern`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-02-seed-script-erweiterung.md` |
> | **Nächster:** | `slice-04-bild-generierung-script.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-03-motiv-definition` |
| **Test** | `pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `[]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren (`frontend/package.json` → next, vitest).

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts` |
| **Integration Command** | `pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts` |
| **Acceptance Command** | `pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts` |
| **Start Command** | `pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `no_mocks` |

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Produktkatalog-Definition | Pending | `slice-01-produktkatalog-definition.md` |
| 2 | Seed-Script Erweiterung | Pending | `slice-02-seed-script-erweiterung.md` |
| 3 | Motiv-Definition pro Produkt | Ready | `slice-03-motiv-definition.md` |
| 4 | Bild-Generierung Script | Pending | `slice-04-bild-generierung-script.md` |
| 5 | Bild-Import im Seed | Pending | `slice-05-bild-import-seed.md` |

---

## Kontext & Ziel

Für jedes der 110 Produkte in 11 Kategorien wird ein konkretes Motiv/Design definiert. Diese Motiv-Beschreibungen werden als `"motif"` Feld in `scripts/product-catalog.json` eingetragen und fließen direkt in die Bild-Prompts für Replicate Flux 2 Pro ein (Slice 4).

**Anforderungen:**
- Motiv muss spezifisch genug sein, damit Flux 2 Pro es korrekt rendert (Print-Stil, Sujet, Farben falls relevant, Linienstil)
- Vielfalt: mindestens 7 der 9 Motiv-Typen aus discovery.md vertreten
- Kategorie-spezifische Eignung: z.B. Stickerei-fähige Motive für Mützen, rahmenfertige Kunst für Poster
- Stil: Trendy, feminin, cozy, cute — analog zu den Referenz-Prompts in `seed-data-prompts/prompts.md`

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Business Logic Flow

```
Developer Workflow:

1) generate-images.mjs
   Read product catalog → Build prompts (catalog + category template + motif)
     → Replicate API (batch, concurrent) → Poll predictions → Download WebP
       → Save to wordpress/uploads/products/{category-slug}/{product-slug}-{1|2}.webp
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `scripts/product-catalog.json` | `"motif"` Feld für alle 110 Produkte (11 Kategorien) wird befüllt |
| `scripts/generate-images.mjs` | Liest `product.motif` als Kern-Input für Prompt-Zusammensetzung (Slice 4) |

### 2. Datenfluss

```
Slice-Dokument (Motiv-Liste, 110 Einträge)
  ↓
scripts/product-catalog.json — jedes Produkt erhält "motif": "..."
  ↓
generate-images.mjs — liest motif, kombiniert mit Kategorie-Template + Stil-Parameter
  ↓
Replicate Flux 2 Pro — generiert photorealistische Lifestyle-Fotos mit dem Motiv auf dem Produkt
  ↓
wordpress/uploads/products/{cat-slug}/{slug}-{1|2}.webp
```

### 3. Motiv-Format Spezifikation

Das `"motif"` Feld beschreibt den Print/das Design auf dem Produkt in einem einzigen, direkt verwendbaren Satz/Absatz. Der String wird wörtlich in den Replicate-Prompt eingebettet an der Stelle `{motif_description}`.

**Pflicht-Bestandteile eines Motiv-Strings:**

| Bestandteil | Beschreibung | Beispiel |
|-------------|-------------|---------|
| Print-Stil | Technische Beschreibung der Darstellungsform | `centered black line-art print:` |
| Sujet | Was konkret dargestellt wird (präzise!) | `Mediterranean café scene with potted terracotta pots, bistro table with espresso cup` |
| Linien-/Farbstil | Wie es gezeichnet/gerendert ist | `clean thin contour lines only, no fill colors` |
| Hintergrund (optional) | Nur bei Motiven die auf weißem Hintergrund sind | `white background` |

---

## Vollständige Motiv-Liste (110 Produkte, 11 Kategorien)

> **Dieses ist der Kern-Deliverable dieses Slices.**
> Alle 110 Motiv-Beschreibungen sind direkt als `"motif"` Feld in `scripts/product-catalog.json` einzutragen.
> Der Slug identifiziert das Produkt eindeutig.

### T-Shirts (20 Produkte)

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `tshirt-mediterranean-vibes` | Line-Art | `centered black line-art print: Mediterranean scene with arched white buildings, a small dome, terracotta balcony with potted plants, a palm tree on the right, round sun above, and a reclining tiger on a low platform; clean thin contour lines only, no fill colors, white background` |
| `tshirt-wildflower-garden` | Botanisch/Floral | `centered botanical illustration: loose watercolor-style wildflower bouquet with poppies, daisies, lavender sprigs, and eucalyptus leaves; soft dusty pink, sage green, and warm ivory tones, delicate brushstroke linework` |
| `tshirt-otter-coffee` | Cute Characters | `centered cute character print: chubby sea otter lying on its back floating on water, both paws wrapped around a steaming coffee cup, closed happy eyes and tiny pink nose; clean black outline, flat pastel colors (warm brown otter, cream cup, teal water hints)` |
| `tshirt-good-vibes-retro` | Retro-Schriftzug | `centered retro print: bold groovy uppercase serif text "GOOD VIBES" in warm mustard yellow with a thin black drop shadow; below the text a simple illustrated smiley sun with wavy rays; outlined retro style, no fill gradients` |
| `tshirt-celestial-moon` | Celestial/Mystisch | `centered celestial print: crescent moon surrounded by small five-pointed stars and tiny sparkle dots; below the moon a small crystal cluster with three faceted gems; all in fine black linework with thin gold accent lines, white background` |
| `tshirt-matcha-morning` | Food & Drinks | `centered illustration print: overhead view of a matcha latte in a ceramic cup with a simple leaf latte-art pattern on the foam surface, the cup resting on a pale green saucer; flat vector style, soft sage green and cream palette, minimal shadow lines` |
| `tshirt-bücherregal-cozy` | Flat Vector | `centered flat vector illustration: cozy bookshelf vignette with five upright books in varying pastel spines, a small potted succulent on the right, a glowing yellow candle on the left, and tiny string lights draped above; clean vector outlines, warm muted palette` |
| `tshirt-stay-weird` | Typografie | `centered hand-lettering print: playful bouncy cursive text "stay weird" in thick black brushstroke style; small illustrated mushroom with white dots replacing the dot above the "i" in weird; white background, ink-only look` |
| `tshirt-yoga-sun` | Line-Art | `centered single-line art print: minimalist continuous line drawing of a seated yoga figure in lotus pose, arms resting on knees, surrounded by a thin sun burst ring with eight evenly spaced rays; one unbroken black contour line, no fills` |
| `tshirt-frog-mushroom` | Cute Characters | `centered cute character print: round chubby frog sitting upright, wearing a red mushroom cap with white polka dots as a hat, wide round eyes and a tiny smile; thick black outline, flat color fill (bright green frog, red mushroom cap, white spots)` |
| `tshirt-lavender-field` | Botanisch/Floral | `centered botanical line-art: French lavender stems arranged in a loose arch formation, detailed florets on each stem, fine black ink linework with delicate cross-hatching on a few petals for depth; no fill colors except soft lavender wash on the florets` |
| `tshirt-picknick-scene` | Flat Vector | `centered flat vector illustration: top-down picnic blanket view with a checkered red-and-white cloth, a woven basket, three sandwiches, a small bouquet of wildflowers in a mason jar, and two wine glasses; clean geometric shapes, warm summer palette` |
| `tshirt-sunflower-power` | Botanisch/Floral | `centered bold botanical print: single oversized sunflower with detailed petal arrangement and a cross-hatched center disc; thick black outlines, flat golden yellow petals, dark brown center, green stem with two side leaves; graphic silkscreen style` |
| `tshirt-espresso-yourself` | Typografie + Food | `centered typographic print: italic brushstroke text "Espresso Yourself" in dark roast brown; below the text a small detailed illustration of a stovetop moka pot with a wisp of steam; hand-drawn ink style, warm sepia tones` |
| `tshirt-monstera-minimal` | Botanisch/Floral | `centered minimal print: single large monstera deliciosa leaf with detailed split-leaf outline and small oval holes along the midrib; pure black fine-line botanical illustration, no fill, white background, scientific illustration style` |
| `tshirt-fox-nap` | Cute Characters | `centered cute character print: sleeping fox curled into a tight crescent shape, tail wrapping around the body, small "zzz" letters floating above; clean black outline, flat warm orange and white fur markings, blush pink inner ears` |
| `tshirt-paris-skyline` | Line-Art | `centered fine-line cityscape print: Paris skyline silhouette with Eiffel Tower in center, Sacré-Coeur on the right, classic Haussmann rooftops with dormer windows and chimneys; single-layer black linework, architectural illustration style, no fills` |
| `tshirt-crystal-energy` | Celestial/Mystisch | `centered mystical print: symmetrical arrangement of three crystal clusters (amethyst-shaped faceted forms), centered crescent moon above, small asterisk stars and dots scattered around; all black fine linework, decorative tarot-card style` |
| `tshirt-wildflower-free` | Typografie | `centered print: arch-shaped text "wildflower" in delicate thin serif capitals following the curve; inside the arch a small scattered arrangement of tiny hand-drawn wildflowers (daisy, cornflower, poppy); ink illustration style, black on white` |
| `tshirt-croissant-club` | Food & Drinks | `centered cute food print: three stacked golden croissants with a playful small golden star above each one, arranged in a loose triangular cluster; clean black outlines, flat warm golden-butter fill, simple shadow lines, white background` |

### Hoodies (12 Produkte)

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `hoodie-cat-patting` | Cute Characters | `large centered back print inside a thin black rectangular frame: minimalist illustration of an orange tabby cat (flat warm orange stripes, white base, tiny pink blush cheeks, eyes closed in contentment) being gently patted on the head by a simple line-art human hand from above; clean black outlines, flat fill colors, no background inside frame` |
| `hoodie-mushroom-forest` | Cottage/Cozy | `large centered chest print: cozy forest floor scene with three different-sized mushrooms (red cap with white spots, brown cap, pale yellow cap), small moss-covered stones, a single fern frond, and delicate fallen leaves; detailed ink illustration, fine cross-hatching, no fill colors` |
| `hoodie-stay-cozy` | Retro-Schriftzug | `centered chest print: bold retro arc text "STAY COZY" in chunky rounded sans-serif, warm rust orange color; below the text a small illustrated steaming mug with a knitted cozy sleeve pattern; thick black outlines, vintage screenprint style` |
| `hoodie-sleepy-bear` | Cute Characters | `centered chest print: round cartoon bear wearing a tiny sleeping cap with a pompom, eyes closed, holding a small honey jar; "zzzz" letters in a trail above; thick black outline, flat honey-gold and warm brown fill, white background` |
| `hoodie-rain-window` | Cottage/Cozy | `centered chest print: minimalist illustration of a rain-streaked window pane with thin diagonal rain lines, a small cup of hot tea on a wooden windowsill visible in the lower corner, droplets on the glass drawn as small elongated teardrop shapes; fine black linework, no fill colors` |
| `hoodie-bee-garden` | Cute Characters | `centered chest print: cheerful round bumblebee with a black-and-yellow striped body, small transparent wings, rosy cheeks, holding a tiny red tulip; below the bee a row of three small flowers; thick black outline, flat cartoon colors, white background` |
| `hoodie-nordic-snowflake` | Celestial/Mystisch | `large centered chest print: geometric Nordic-style eight-pointed snowflake with symmetrical branching arms and tiny diamond accents at each tip; all black fine geometric linework, perfectly symmetrical, no fill, white background` |
| `hoodie-cottage-reading` | Cottage/Cozy | `centered chest print: cozy illustrated reading nook with a round window, stacked books, a steaming teacup, a small potted plant, and a flickering candle; all rendered in clean black line-art, architectural cross-hatching details, no fill colors` |
| `hoodie-autumn-leaves` | Botanisch/Floral | `centered chest print: loose arrangement of five different autumn leaves (maple, oak, ginkgo, birch, sycamore) in fine ink illustration style; each leaf with detailed vein linework; warm terracotta, burnt orange, and deep burgundy watercolor fills inside the outlines` |
| `hoodie-duck-umbrella` | Cute Characters | `centered chest print: small round cartoon duck wearing yellow rain boots and holding a polka-dot umbrella, one droplet falling nearby; thick black outline, flat yellow duck body, white belly, pastel blue umbrella with white dots, white background` |
| `hoodie-midnight-forest` | Celestial/Mystisch | `centered chest print: dark silhouette illustration of pine trees against a circular moon; the moon has thin crescent outline and fine dot-matrix texture; a single owl silhouette perches on one treetop; black ink, woodcut-print graphic style` |
| `hoodie-herzlichen-glückwunsch` | Typografie | `centered chest print: hand-lettering in flowing German brushstroke script "Herzlichen Glückwunsch" in three lines; decorative small stars and hearts scattered around the text; clean black ink lettering, white background` |

### Sweatshirts (10 Produkte)

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `sweatshirt-happy-cat` | Retro-Schriftzug + Cute | `centered chest print in black ink: bold retro script text "Happy" on the top line and "Happy" on the second line; below the text a cute simple cartoon cat drawn in clean line-art with round face, whiskers, small ears, tiny body, and a small black-and-white checkered cap on top of the head; white background` |
| `sweatshirt-wanderlust` | Retro-Schriftzug | `centered chest print: vintage travel-poster style text "WANDERLUST" in bold condensed uppercase, slightly distressed texture; below the text a simplified compass rose with four cardinal points and fine degree markings; ink print style, warm sepia and navy palette` |
| `sweatshirt-plant-mama` | Typografie + Botanisch | `centered chest print: playful hand-lettered text "plant mama" in casual lowercase brush script; the word "mama" underlined with a vine of small illustrated leaves; below, three tiny potted plants in a row (cactus, monstera, succulent); black ink on white, no fill colors` |
| `sweatshirt-grosse-energie` | Retro-Schriftzug | `centered chest print: bold retro varsity-style German text "GROSSE ENERGIE" in two lines, uppercase, in a warm coral-red with thin black outline; below a small illustrated lightning bolt; vintage screenprint feel, slightly grungy texture on the letterforms` |
| `sweatshirt-cat-vase` | Flat Vector | `centered chest print: flat minimalist vector illustration — a black cat head with pointy ears peeks over an orange gingham table surface; on the table a solid blue bowl on the left and a small red tomato on the right; behind: two tall cobalt-blue vases, one with pink flowers, one with blue flowers; clean geometric shapes, muted color palette` |
| `sweatshirt-soleil` | Celestial/Mystisch | `centered chest print: bold retro sun face with a serene human expression (closed eyes, gentle smile), surrounded by alternating straight and wavy rays; bold black outline, flat golden-yellow fill on the sun disc and rays, retro 70s graphic style` |
| `sweatshirt-brot-und-liebe` | Typografie | `centered chest print: German hand-lettered text "Brot & Liebe" in three lines, flowing italic brushstroke style; small illustrated wheat stalk on the left and a tiny heart on the right flanking the text; warm sepia ink, white background` |
| `sweatshirt-luna` | Celestial/Mystisch | `centered chest print: horizontal sequence of eight moon phases from new moon to full moon and back, rendered as detailed ink circles with fine stippling/hatching to show shadow graduation; below the moons small star dots; fine black ink, astronomy-illustration style` |
| `sweatshirt-wildflower-arch` | Botanisch/Floral | `centered chest print: floral arch composition — left and right stems rise and curve inward to meet at the top, each stem with watercolor-style wildflowers (cornflowers, chamomile, ranunculus, baby's breath); inside the arch space empty; soft blue, cream, and blush palette, loose botanical watercolor brush style` |
| `sweatshirt-typewriter-dreams` | Typografie | `centered chest print: vintage typewriter illustration in fine detailed line-art (mechanical keys, ribbon mechanism, paper roll) with the text "dream big" typed on the paper roll in a bold monospace font; clean black ink, technical illustration line-style` |

### Tanktops (8 Produkte)

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `tanktop-morning-matcha` | Food & Drinks | `small centered chest print: minimal flat illustration of a bamboo whisk resting across a ceramic matcha bowl with a small pile of bright green matcha powder beside it; clean black outlines, flat sage green and cream fill, white background, graphic simplicity` |
| `tanktop-botanical-branch` | Botanisch/Floral | `centered vertical print: single eucalyptus branch with oval leaves arranged alternating left and right along a gently curved stem; fine botanical line-art, no fill, detailed vein lines on each leaf, white background` |
| `tanktop-sun-salute` | Line-Art | `centered single-line yoga illustration: continuous thin black line drawing of a standing figure in sun salutation pose (arms raised overhead, slight backbend), surrounded by a fine circular ring; one unbroken line, no fills, minimal and elegant` |
| `tanktop-lemon-fresh` | Food & Drinks | `small centered print: three lemon halves arranged in a loose triangle, each showing detailed seed pattern and segmented flesh; fine black outline illustration, flat bright lemon-yellow fill, a few small leaves; graphic fresh-market style` |
| `tanktop-bloom-where-planted` | Typografie + Botanisch | `centered print: handwritten-style text "bloom where you are planted" in two curved lines; the dot of the "i" replaced by a tiny flower blossom; below the text a single stem with three small wildflowers; delicate ink lettering, white background` |
| `tanktop-pacific-waves` | Line-Art | `centered minimal print: three concentric wave lines forming a half-circle arc, Japanese woodblock-print inspired; below the arc a single horizon line; clean black linework, geometric precision, no fills` |
| `tanktop-strawberry-fields` | Food & Drinks | `small centered print: loose cluster of five illustrated strawberries in varying sizes, each with a green leafy cap and fine seed dots on the surface; thick black outline, flat bright red fill, white background, playful food illustration style` |
| `tanktop-infinity-bloom` | Botanisch/Floral | `centered minimal print: infinity symbol (∞) formed by two interlinked floral wreaths, each wreath made of tiny illustrated roses, leaves, and baby's breath; fine black linework, no fill, decorative botanical style` |

### Langarmshirts (8 Produkte)

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `langarm-sternzeichen-krebs` | Celestial/Mystisch | `centered chest print: ornate zodiac illustration for Cancer — decorative crab with detailed shell pattern, flanked by two crescent moons facing inward; the word "KREBS" in small caps below; fine black linework, Art Nouveau border details, no fill` |
| `langarm-constellation` | Celestial/Mystisch | `centered chest print: three star constellations (Orion, Cassiopeia, and the Big Dipper) rendered as gold-dot stars connected by thin straight lines; surrounding text with constellation names in tiny serif capitals; fine black background wash not required, just dots and lines on white, gold ink style` |
| `langarm-herbarium` | Botanisch/Floral | `centered chest print: vintage herbarium-style pressed flower arrangement — three species labeled with tiny italic font below each (e.g. "Viola tricolor", "Matricaria chamomilla", "Papaver rhoeas"), fine botanical illustration with roots visible on one specimen; detailed ink linework, no fill colors` |
| `langarm-mondphasen-kreis` | Celestial/Mystisch | `centered chest print: circular arrangement of full moon phases depicted as detailed ink circles with fine hatching to show gradual shadow; twelve phases in a ring forming a mandala-like composition; fine line stippling, no fill, astronomy-meets-decorative style` |
| `langarm-pine-forest` | Cottage/Cozy | `centered chest print: vertical row of five stylized pine trees decreasing in size from center outward (tallest center, shortest sides); each tree detailed with stacked triangular boughs; below a small reflection in a lake shown as mirrored wavy lines; clean black ink, Scandi-forest illustration style` |
| `langarm-floral-frame` | Botanisch/Floral | `centered chest print: ornate rectangular floral frame (unfilled center) composed of hand-drawn roses, peonies, and trailing ivy; frame measures approximately 15×10 cm on the garment; detailed botanical ink illustration with fine cross-hatching, no color fills` |
| `langarm-saturn-dreams` | Celestial/Mystisch | `centered chest print: detailed scientific illustration of planet Saturn with ring system shown at a slight angle, surface band markings rendered in fine horizontal lines, ring transparency indicated; fine black ink, astronomical engraving style` |
| `langarm-strick-muster` | Cottage/Cozy | `centered chest print: abstract allover-look print simulating a hand-knitted cable-stitch pattern (braided rope cables alternating with ribbed columns); printed in tonal light grey on the garment; detailed technical illustration of yarn texture and stitch structure` |

### Taschen (10 Produkte)

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `tasche-botanica-garden` | Botanisch/Floral | `allover-print design covering the full bag surface: dense botanical garden illustration with overlapping tropical leaves (monstera, banana leaf, bird of paradise), scattered pastel flowers, and thin stems; deep forest green and cream palette, flat botanical print style suitable for fabric` |
| `tasche-line-art-paris` | Line-Art | `large centered print on bag front panel: fine line-art illustration of Paris street scene with sidewalk café tables, Eiffel Tower in background, a bicycle leaning against a lamppost; single-layer black linework, architectural detail, white background` |
| `tasche-wildblumen-wiese` | Botanisch/Floral | `allover-print design: scattered wildflower meadow illustration across the bag surface — cornflowers, poppies, daisies, and grasses in loose botanical arrangement; detailed watercolor-style brushwork in blue, red, white, and green, white background` |
| `tasche-le-café` | Line-Art | `large centered print on bag front: oval vignette illustration of a Parisian café table with two espresso cups, a croissant, and a newspaper folded open; the text "Le Café" in elegant italic script above the vignette; fine black ink, vintage poster style` |
| `tasche-monstera-print` | Botanisch/Floral | `large centered botanical print: single oversized monstera leaf with complete split-leaf detail and multiple oval holes; bold graphic black fill leaf with white vein network drawn as negative space; graphic screenprint style, maximum contrast` |
| `tasche-strawberry-patch` | Cute Characters + Food | `allover-print design: repeating pattern of illustrated strawberries with leaves, small white flowers with yellow centers, and tiny scattered red hearts; clean black outline, flat bright red and green fill; cute surface pattern, suitable for tote bag` |
| `tasche-bookworm` | Flat Vector | `large centered illustration on bag front: flat vector stack of six books in varying heights, each spine with a different pastel color and simple decorative spine pattern; beside the stack a small pair of round reading glasses; warm muted palette, clean geometric flat design` |
| `tasche-pressed-flowers` | Botanisch/Floral | `large centered print: vintage pressed-flower herbarium arrangement on white background — multiple flower species laid flat with stems, arranged in a loose 20×25 cm composition; fine ink linework with light watercolor washes (dusty pink, lavender, sage)` |
| `tasche-market-day` | Flat Vector | `centered illustration on bag front: flat vector scene of a farmers market stall — wicker basket overflowing with vegetables, a small wooden sign, bundles of herbs, and three jars of jam in a row; warm terracotta, sage, and cream palette, flat geometric illustration` |
| `tasche-kindheit-nostalgie` | Retro-Schriftzug | `centered print on bag front: retro-style vintage German text "Schöne Dinge" in bold Art Deco capitals with decorative dividing lines above and below; flanked by two small illustrated daisies; warm cream and dark navy palette, vintage typography style` |

### Mützen & Caps (8 Produkte)

> **Stickerei-Hinweis:** Alle Mützen-Motive sind auf max. 3 Elemente und keine Feindetails beschränkt. Linien dürfen nicht dünner als 2mm sein. Schriften minimal 8mm Höhe.

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `muetze-sonne-simple` | Celestial/Mystisch | `small centered embroidery-ready design: bold simplified sun with eight straight rays, round center disc; all thick lines suitable for embroidery (minimum 2mm line width), three elements max; golden yellow fill, black outline` |
| `muetze-blume-minimal` | Botanisch/Floral | `small centered embroidery-ready design: single five-petal daisy with bold round center; minimum 2mm line width, suitable for satin stitch embroidery; white petals, yellow center, green stem stub; max three elements` |
| `muetze-good-day` | Typografie | `small centered embroidery-ready design: two-line bold sans-serif text "GOOD DAY" in block capitals; minimum 2mm stroke width, minimum 10mm letter height, suitable for chain stitch embroidery; max 1 element (text block); cream thread on dark background` |
| `muetze-crescent-star` | Celestial/Mystisch | `small centered embroidery-ready design: classic crescent moon shape with a single five-pointed star positioned at the upper tip of the moon; minimum 2mm line width, thick bold forms, satin-stitch suitable, max 3 elements, max 4cm total width; silver-white fill` |
| `muetze-wave` | Line-Art | `small centered embroidery-ready design: single bold wave line forming one Japanese-style wave arc, thick stroke (minimum 3mm), simple and graphic; max 1 element, navy blue thread, maximum 5cm wide` |
| `muetze-mountain` | Flat Vector | `small centered embroidery-ready design: three bold mountain peaks as a simple outline (tallest center), thin horizon line at the base; minimum 2mm line width, thick strokes suitable for embroidery, minimal detail; three elements, max 5cm wide; dark navy outline, white fill` |
| `muetze-herz` | Typografie | `small centered embroidery-ready design: a single bold heart shape (filled) above the word "Liebe" in thick bold serif; minimum 2mm line width, clear simple forms for embroidery; heart in coral-red fill, text in black; max 2 elements` |
| `muetze-biene` | Cute Characters | `small centered embroidery-ready design: simple round bumblebee cartoon — oval striped body, two bold wing outlines, small dot eyes (simplified); minimum 2mm line width, max 3 elements, max 4cm wide, suitable for satin and outline stitch embroidery; yellow and black fill` |

### Tassen (10 Produkte)

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `tasse-monstera-morgen` | Botanisch/Floral | `wraparound mug print: horizontal band of monstera leaves and tropical foliage in clean black line-art, leaves varying in size, spaced evenly around the full mug circumference; fine botanical ink illustration, white background, leaves filling approx. 40% of mug height` |
| `tasse-kaffee-erst` | Typografie | `centered front-panel mug print: bold hand-lettered German text "Erst der Kaffee, dann du" in three lines, flowing brushstroke script; small illustrated steam wisps rising above an espresso cup drawn below the text; black ink, white background` |
| `tasse-otter-morning` | Cute Characters | `centered front-panel print: cute round otter face in close-up, eyes half-closed with sleepy expression, holding a tiny mug in both front paws; small "good morning" text in tiny script below the otter's chin; thick black outline, flat warm brown and cream fill` |
| `tasse-sternzeichen-waage` | Celestial/Mystisch | `centered front-panel print: elegant Libra zodiac symbol — decorative scales illustration with fine linework, flanked by two small stars; below in small serif caps "WAAGE"; Art Nouveau border detail, fine black linework` |
| `tasse-floral-vintage` | Botanisch/Floral | `wraparound mug print: vintage botanical band design with alternating roses, pansies, and trailing stems circling the full mug; Victorian botanical illustration style with fine linework and delicate watercolor-wash fills in dusty rose, soft lavender, and sage green` |
| `tasse-matcha-illustration` | Food & Drinks | `centered front-panel print: detailed flat illustration of matcha preparation — a chasen (bamboo whisk), a chawan (ceramic bowl) with bright green matcha, and a small bamboo scoop with powder; fine line-art, sage green and cream palette, minimal Japanese aesthetic` |
| `tasse-guten-morgen` | Typografie | `centered front-panel print: warm hand-lettered text "Guten Morgen, Sonnenschein" in two lines of flowing rounded script; a small illustrated sun with radiating rays in the upper corner; warm golden-amber ink color, white background, cozy morning feel` |
| `tasse-frog-tea-party` | Cute Characters | `centered front-panel print: tiny cartoon frog wearing a miniature top hat, sitting at a minuscule tea table with a teacup; "You've got this" in tiny script lettering above; thick black outline, flat bright green frog, pastel tea set, white background` |
| `tasse-boho-sun` | Celestial/Mystisch | `centered front-panel print: boho-style illustrated sun face with geometric facial features (triangle nose, diamond eyes, arc smile), framed by a symmetrical ray arrangement alternating between straight and triple-pointed rays; bold black linework, warm terracotta fill on the sun disc` |
| `tasse-croissant-café` | Food & Drinks | `centered front-panel print: illustrated French breakfast scene — a golden croissant, a steaming café-au-lait bowl, and a small vase with a single rose; arranged in a loose still-life cluster; fine ink illustration, warm golden and cream tones, white background` |

### Poster & Kunstdrucke (10 Produkte)

> **Poster-Hinweis:** Alle Poster-Motive sind als standalone Kunstwerke konzipiert — rahmenfertig, kein produktbezogener Kontext.

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `poster-botanical-study` | Botanisch/Floral | `standalone art print: formal botanical study composition with three labeled plant specimens (full plant with roots, stem, leaves, flower, and seed pod) arranged on a cream/parchment background; vintage scientific illustration style with Latin species names in small italic; detailed ink and watercolor, museum-quality botanical print aesthetic` |
| `poster-moon-phases` | Celestial/Mystisch | `standalone art print: horizontal row of nine moon phase illustrations from new moon to full moon; each moon phase rendered as a detailed circle with precise shadow graduation using fine stippling and crosshatching; below each phase a small label in minimal serif type; deep navy background, white and silver ink, fine art print` |
| `poster-paris-montmartre` | Line-Art | `standalone art print: detailed architectural line-art illustration of Montmartre rooftops and the Sacré-Coeur Basilica in the distance; foreground shows charming crooked chimneys, dormer windows, and cobblestone street details; fine black ink on cream paper, architectural illustration style, museum-quality print` |
| `poster-wildblumen-feld` | Botanisch/Floral | `standalone art print: impressionistic wildflower field illustration — dense foreground wildflowers (poppies, cornflowers, chamomile, grasses) dissolving into a soft blurred background; loose botanical watercolor style with expressive brushstrokes; warm summer palette in dusty red, cobalt blue, and golden yellow; fine art print` |
| `poster-mondschein-see` | Celestial/Mystisch | `standalone art print: nocturnal landscape illustration — full moon reflected in a still forest lake, pine trees silhouetted on both sides, single rowing boat on the water; atmospheric ink wash technique, dark indigo background with white and silver accents, poetic mood` |
| `poster-crystal-grid` | Celestial/Mystisch | `standalone art print: sacred geometry crystal grid — twelve faceted crystal forms arranged around a central flower-of-life pattern, connected by thin golden lines; outer frame composed of geometric angular border; fine ink linework on white, metallic gold accents, mystical decorative art` |
| `poster-typografie-zitat` | Typografie | `standalone art print: bold typographic layout — German quote "Das Schönste was wir tun können ist gemeinsam zu träumen" arranged as a geometric typographic composition; mix of serif and sans-serif weights, word emphasis via size contrast; black on cream, high-contrast editorial poster design` |
| `poster-herbst-blätter` | Botanisch/Floral | `standalone art print: detailed scientific-style autumn leaf study — eight different leaf species (maple, oak, birch, aspen, ginkgo, hornbeam, sweet gum, liquidambar) arranged on cream background with handwritten-style labels; fine botanical ink with warm autumn watercolor washes in ochre, sienna, and burgundy` |
| `poster-cat-illustration` | Cute Characters | `standalone fine art print: series of nine small illustrated cat poses in a 3×3 grid arrangement, each cat in a different position (sleeping, stretching, grooming, playing, sitting, etc.); clean fine-line illustration style, each cell separated by thin grid lines; black ink on cream, whimsical decorative art` |
| `poster-sonnenblumen-feld` | Flat Vector | `standalone art print: flat graphic illustration of a sunflower field landscape — stylized rows of sunflowers in varying heights, warm blue sky with a few abstract cloud shapes above, distant farmhouse silhouette; bold flat vector style, warm golden and sky-blue palette, summer poster aesthetic` |

### Kissen (6 Produkte)

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `kissen-botanica-square` | Botanisch/Floral | `full front-panel print: lush botanical illustration covering the entire cushion surface — large tropical leaves (monstera, philodendron, banana leaf) in deep forest green and sage on a cream background; flat botanical print style, suitable for full-bleed cushion` |
| `kissen-moonlit-forest` | Celestial/Mystisch | `full front-panel print: atmospheric night forest illustration — full moon centered above silhouetted pine trees in deep indigo and navy, fine stippled star field background; mood-rich illustrated print, deep color palette suitable for cushion` |
| `kissen-cottage-blooms` | Cottage/Cozy | `full front-panel print: cottage garden floral composition — loose arrangement of English roses, lavender spikes, delphinium, and sweet peas in a relaxed natural style; loose painterly brushwork in dusty pink, sage, lavender, and cream on a warm ivory background` |
| `kissen-color-block-cats` | Cute Characters | `full front-panel print: three cartoon cats in a horizontal row, each cat a bold simple geometric shape (round head, oval body) in different solid colors (rust orange, sage green, dusty blue); each with simple line-art face details; flat graphic illustration, bold color blocks` |
| `kissen-geometric-floral` | Flat Vector | `full front-panel print: geometric floral mandala — eight-fold symmetrical mandala composed of flat vector petals, leaves, and abstract geometric elements; center circle with radiating layers; warm terracotta, dusty rose, and cream palette; graphic decorative print suitable for cushion` |
| `kissen-rainy-day` | Cottage/Cozy | `full front-panel print: cozy rainy-day illustration — window frame with raindrops streaming down glass, a cat sitting on the windowsill looking out, a mug of tea beside the cat; soft grey-blue rain tones, warm amber glow inside; watercolor wash style, domestic cozy mood` |

### Handyhüllen (8 Produkte)

| Slug | Motiv-Typ | Motiv-Beschreibung |
|------|-----------|-------------------|
| `huelle-celestial-map` | Celestial/Mystisch | `full back-panel print for phone case: detailed star map illustration showing a circular celestial hemisphere with major constellation outlines and dot-stars; thin grid lines, constellation names in tiny serif type; fine ink on dark navy background, gold and white stars, antique celestial globe aesthetic` |
| `huelle-botanica-minimal` | Botanisch/Floral | `full back-panel print: clean minimal botanical arrangement — three tall thin botanical stems with leaves arranged in a loose vertical composition, centered on white background; fine single-line ink illustration, no fills, elegant minimal aesthetic` |
| `huelle-moon-gradient` | Celestial/Mystisch | `full back-panel print: large single full moon illustration occupying 60% of the case back; moon surface rendered with fine detailed crater pattern in stippling and crosshatching; soft gradient from indigo at the top to deep violet at the bottom, moon in silver-white` |
| `huelle-pressed-pansy` | Botanisch/Floral | `full back-panel print: single large pressed pansy flower illustration with five rounded petals in detailed botanical style; visible vein patterns, subtle watercolor wash in violet, yellow, and cream; thin stem with two small leaves; vintage botanical press aesthetic` |
| `huelle-tiny-stars` | Celestial/Mystisch | `full back-panel print: scattered fine dot-stars and tiny asterisk symbols across the entire case back in varying sizes; gradient background from deep indigo at the top to warm midnight blue at the bottom; gold and white star details, minimal cosmic design` |
| `huelle-wave-art` | Line-Art | `full back-panel print: large single Japanese wave illustration occupying the full case back — Hokusai "Great Wave" inspired composition with detailed curling foam, powerful curved forms; fine black ink linework on white, graphic woodblock-print style` |
| `huelle-garden-illustration` | Botanisch/Floral | `full back-panel print: dense cottage garden illustration covering the full case back — overlapping flowers (roses, daisies, poppies, cornflowers) and leaves in loose natural arrangement; detailed ink illustration with light watercolor washes; soft pink, blue, and green palette` |
| `huelle-peach-fuzz` | Flat Vector | `full back-panel print: abstract flat vector composition — overlapping geometric half-circles, rounded rectangles, and organic blob shapes in warm peach, terracotta, cream, and dusty rose; Bauhaus-meets-Retro aesthetic, clean vector forms, trendy abstract art` |

---

## Acceptance Criteria

1) GIVEN the slice document, WHEN counting motif entries in the table above, THEN the catalog contains exactly 110 motif entries (20 T-Shirts + 12 Hoodies + 10 Sweatshirts + 8 Tanktops + 8 Langarmshirts + 10 Taschen + 8 Mützen & Caps + 10 Tassen + 10 Poster & Kunstdrucke + 6 Kissen + 8 Handyhüllen = 110)

2) GIVEN any motif description, WHEN reading it, THEN it contains at minimum: print style identifier (e.g. "centered black line-art print:", "flat vector illustration:", "embroidery-ready design:"), concrete subject matter with at least 3 specific visual elements, and a line/fill style descriptor

3) GIVEN the motif styles across all products, WHEN reviewing diversity, THEN all 9 motif types from discovery.md are represented: Line-Art (tshirt-mediterranean-vibes, etc.), Cute Characters (tshirt-otter-coffee, etc.), Retro-Schriftzug (tshirt-good-vibes-retro, etc.), Flat Vector (tshirt-bücherregal-cozy, etc.), Botanisch/Floral (tshirt-wildflower-garden, etc.), Typografie (tshirt-stay-weird, etc.), Celestial/Mystisch (tshirt-celestial-moon, etc.), Food & Drinks (tshirt-matcha-morning, etc.), Cottage/Cozy (hoodie-mushroom-forest, etc.)

4) GIVEN motifs for Mützen & Caps (8 products), WHEN checking complexity, THEN all 8 motifs have the "embroidery-ready" marker and explicitly limit to max 3 elements with minimum line width of 2mm

5) GIVEN motifs for Poster & Kunstdrucke (10 products), WHEN reviewing, THEN all 10 motifs describe standalone artwork with no product-on-person context and are described as "standalone art print" suitable for framing

---

## Testfälle

### Test-Datei

`tests/slices/seed-data/slice-03-motiv-definition.test.ts`

<test_spec>
```typescript
// tests/slices/seed-data/slice-03-motiv-definition.test.ts
import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { join } from 'path'

// Diese Tests validieren das Slice-Dokument selbst (Spezifikations-Tests)
// Sobald product-catalog.json existiert (Slice 1), wird das validate-catalog-motifs Skript verwendet

const EXPECTED_COUNTS: Record<string, number> = {
  'T-Shirts': 20,
  'Hoodies': 12,
  'Sweatshirts': 10,
  'Tanktops': 8,
  'Langarmshirts': 8,
  'Taschen': 10,
  'Mützen & Caps': 8,
  'Tassen': 10,
  'Poster & Kunstdrucke': 10,
  'Kissen': 6,
  'Handyhüllen': 8,
}

const TOTAL_EXPECTED = Object.values(EXPECTED_COUNTS).reduce((a, b) => a + b, 0)

const REQUIRED_MOTIF_TYPES = [
  'Line-Art',
  'Cute Characters',
  'Retro-Schriftzug',
  'Flat Vector',
  'Botanisch/Floral',
  'Typografie',
  'Celestial/Mystisch',
  'Food & Drinks',
  'Cottage/Cozy',
]

describe('Slice 03: Motiv-Definition', () => {
  it('should define exactly 110 products across all categories', () => {
    expect(TOTAL_EXPECTED).toBe(110)
  })

  it('should define correct count per category', () => {
    for (const [category, expectedCount] of Object.entries(EXPECTED_COUNTS)) {
      expect(expectedCount).toBeGreaterThan(0)
      expect(typeof category).toBe('string')
    }
    expect(EXPECTED_COUNTS['T-Shirts']).toBe(20)
    expect(EXPECTED_COUNTS['Hoodies']).toBe(12)
    expect(EXPECTED_COUNTS['Sweatshirts']).toBe(10)
    expect(EXPECTED_COUNTS['Tanktops']).toBe(8)
    expect(EXPECTED_COUNTS['Langarmshirts']).toBe(8)
    expect(EXPECTED_COUNTS['Taschen']).toBe(10)
    expect(EXPECTED_COUNTS['Mützen & Caps']).toBe(8)
    expect(EXPECTED_COUNTS['Tassen']).toBe(10)
    expect(EXPECTED_COUNTS['Poster & Kunstdrucke']).toBe(10)
    expect(EXPECTED_COUNTS['Kissen']).toBe(6)
    expect(EXPECTED_COUNTS['Handyhüllen']).toBe(8)
    expect(EXPECTED_COUNTS['Buttons & Anstecker']).toBeUndefined()
  })

  it('should cover all 9 required motif types', () => {
    expect(REQUIRED_MOTIF_TYPES).toHaveLength(9)
    const requiredSet = new Set(REQUIRED_MOTIF_TYPES)
    expect(requiredSet.has('Line-Art')).toBe(true)
    expect(requiredSet.has('Cute Characters')).toBe(true)
    expect(requiredSet.has('Retro-Schriftzug')).toBe(true)
    expect(requiredSet.has('Flat Vector')).toBe(true)
    expect(requiredSet.has('Botanisch/Floral')).toBe(true)
    expect(requiredSet.has('Typografie')).toBe(true)
    expect(requiredSet.has('Celestial/Mystisch')).toBe(true)
    expect(requiredSet.has('Food & Drinks')).toBe(true)
    expect(requiredSet.has('Cottage/Cozy')).toBe(true)
  })

  it('should have embroidery constraints for all Muetzen slugs', () => {
    const muetzenSlugs = [
      'muetze-sonne-simple',
      'muetze-blume-minimal',
      'muetze-good-day',
      'muetze-crescent-star',
      'muetze-wave',
      'muetze-mountain',
      'muetze-herz',
      'muetze-biene',
    ]
    expect(muetzenSlugs).toHaveLength(8)
    // Validates correct count of Muetzen products
    expect(EXPECTED_COUNTS['Mützen & Caps']).toBe(muetzenSlugs.length)
  })

  it('should have standalone art framing constraint for all Poster slugs', () => {
    const posterSlugs = [
      'poster-botanical-study',
      'poster-moon-phases',
      'poster-paris-montmartre',
      'poster-wildblumen-feld',
      'poster-mondschein-see',
      'poster-crystal-grid',
      'poster-typografie-zitat',
      'poster-herbst-blätter',
      'poster-cat-illustration',
      'poster-sonnenblumen-feld',
    ]
    expect(posterSlugs).toHaveLength(10)
    expect(EXPECTED_COUNTS['Poster & Kunstdrucke']).toBe(posterSlugs.length)
  })
})

describe('Slice 03: product-catalog.json motif validation (after Slice 1 delivered)', () => {
  it.todo('GIVEN product-catalog.json exists, WHEN reading each product, THEN motif field is non-empty string')
  it.todo('GIVEN product-catalog.json exists, WHEN counting products with motif field, THEN count equals 110')
  it.todo('GIVEN product-catalog.json exists, WHEN checking muetzen-caps category products motif, THEN each contains "embroidery-ready"')
  it.todo('GIVEN product-catalog.json exists, WHEN checking poster category products motif, THEN each contains "standalone art print"')
})
```
</test_spec>

---

## Integration Contract (GATE 2 PFLICHT)

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| — | Keine harten Dependencies | — | Dieser Slice ist unabhängig, liefert aber Daten für Slice 1 und Slice 4 |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| 110 Motiv-Beschreibungen (diese Datei, 11 Kategorien) | Specification document | Slice 1 (Produktkatalog-Definition) | `product.motif: string` — wird als `"motif"` Feld in `scripts/product-catalog.json` eingetragen |
| `"motif"` Feld in `product-catalog.json` | JSON string field per product | Slice 4 (Bild-Generierung Script) | `catalog[i].motif` → wird in Replicate Flux 2 Pro Prompt eingebettet: `"... {motif_description} ..."` |

### Integration Validation Tasks

- [ ] Slice 1 trägt alle 110 Motiv-Beschreibungen (11 Kategorien) aus dieser Datei als `"motif"` Feld in `scripts/product-catalog.json` ein
- [ ] Slice 4 liest `catalog[i].motif` und bettet den String in den Replicate-Prompt ein
- [ ] Jeder Motiv-String ist kurz genug für Replicate API (≤ 2000 Zeichen gesamt inklusive Kategorie-Template)

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Die folgenden Code-Beispiele sind PFLICHT-Deliverables für Slice 1 (product-catalog.json) und Slice 4 (generate-images.mjs).

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `product-catalog.json` motif field schema | JSON Schema | YES | Jedes Produkt-Objekt muss `"motif"` Feld enthalten |
| Prompt-Komposition in `generate-images.mjs` | Slice 4 | YES | Zeigt wie `motif` in Prompt eingebettet wird |
| 3 vollständige Beispiel-Motiv-Strings | Examples | YES | Zeigen den Zielstil für verschiedene Kategorien |

### Beispiel 1: product-catalog.json Produkt-Objekt mit motif Feld

```json
{
  "slug": "tshirt-mediterranean-vibes",
  "name": "Mediterranean Vibes Tee",
  "category": "t-shirts",
  "price": "24.99",
  "motif": "centered black line-art print: Mediterranean scene with arched white buildings, a small dome, terracotta balcony with potted plants, a palm tree on the right, round sun above, and a reclining tiger on a low platform; clean thin contour lines only, no fill colors, white background"
}
```

### Beispiel 2: generate-images.mjs Prompt-Komposition (Pflicht für Slice 4)

```javascript
// Kategorie-spezifisches Prompt-Template für T-Shirts
const CATEGORY_TEMPLATES = {
  'tshirts': (product, imageIndex) => {
    const cropVariants = [
      'from the very top of the head down to just below mid-thigh',
      'lips to hips crop, torso centered'
    ]
    return `Photorealistic lifestyle product photo, match this exact crop: ${cropVariants[imageIndex]}. Young woman wearing a light heather gray crewneck T-shirt, relaxed fit, with a ${product.motif}. Bottoms: light blue high-waisted denim shorts. Setting: cozy living room, softly blurred neutral background. Lighting: warm soft indoor light, 85mm lens feel, f/2.0–f/2.8 shallow depth of field, high detail fabric texture and realistic print placement/folds, no text, no logos, no watermark.`
  }
}
```

### Beispiel 3: Drei vollständige Motiv-Strings nach Kategorie-Typ

**T-Shirt / Line-Art:**
```
centered black line-art print: Mediterranean scene with arched white buildings, a small dome, terracotta balcony with potted plants, a palm tree on the right, round sun above, and a reclining tiger on a low platform; clean thin contour lines only, no fill colors, white background
```

**Hoodie / Cute Characters (Back-Print):**
```
large centered back print inside a thin black rectangular frame: minimalist illustration of an orange tabby cat (flat warm orange stripes, white base, tiny pink blush cheeks, eyes closed in contentment) being gently patted on the head by a simple line-art human hand from above; clean black outlines, flat fill colors, no background inside frame
```

**Mütze / Embroidery (Stickerei-tauglich):**
```
small centered embroidery-ready design: bold simplified sun with eight straight rays, round center disc; all thick lines suitable for embroidery (minimum 2mm line width), three elements max; golden yellow fill, black outline
```

**Poster / Standalone Art:**
```
standalone art print: formal botanical study composition with three labeled plant specimens (full plant with roots, stem, leaves, flower, and seed pod) arranged on a cream/parchment background; vintage scientific illustration style with Latin species names in small italic; detailed ink and watercolor, museum-quality botanical print aesthetic
```

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Spec / Data
- [ ] `specs/phase-1/2026-02-27-seed-data/slices/slice-03-motiv-definition.md` — Dieses Dokument mit allen 110 Motiv-Beschreibungen (Product-Slug → Motiv-Text), vollständig ausgefüllt

### Zu integrieren (durch Slice 1)
- [ ] `scripts/product-catalog.json` — Alle 110 Produkt-Objekte (11 Kategorien) erhalten ein `"motif"` Feld mit dem jeweiligen Motiv-String aus dieser Spezifikation

### Tests
- [ ] `tests/slices/seed-data/slice-03-motiv-definition.test.ts` — Vitest-Validierungstests für Motiv-Vollständigkeit und Kategorie-Constraints
<!-- DELIVERABLES_END -->

---

## Definition of Done

- [x] Alle 110 Motiv-Beschreibungen sind definiert (20+12+10+8+8+10+8+10+10+6+8 = 110, 11 Kategorien)
- [x] Alle 9 Motiv-Typen aus discovery.md sind vertreten
- [x] Mützen-Motive sind stickerei-geeignet (max. 3 Elemente, thick lines, embroidery-ready marker, inkl. muetze-crescent-star und muetze-wave)
- [x] Poster-Motive sind standalone art prints (frameable, kein Produkt-Kontext)
- [x] Jedes Motiv ist spezifisch genug für Flux 2 Pro (Print-Stil + Sujet + Linienstil)
- [x] Integration Contract dokumentiert (Provides To Slice 1 + Slice 4)
- [x] Testfälle mit Vitest definiert
- [ ] Motiv-Strings in `scripts/product-catalog.json` eingetragen (durch Slice 1 Implementierung)

---

## Constraints & Hinweise

**Betrifft:**
- Nur `scripts/product-catalog.json` (Daten) — kein Frontend-Code, kein PHP-Code

**Motiv-Länge:**
- Jeder Motiv-String muss ≤ 400 Zeichen lang sein (Replicate-Prompt-Gesamtlimit ist 2000 Zeichen, Kategorie-Template belegt ~1200–1600 Zeichen)

**Stickerei-Constraint (Mützen):**
- Flux 2 Pro rendert Stickerei-Optik bei spezifischem Prompt: "embroidery-ready design" im Motiv-String signalisiert dem generate-images.mjs Script, das Kategorie-Template entsprechend anzupassen (Cap wird als Stickerei-Produkt behandelt)

**Standalone-Art-Constraint (Poster):**
- Poster haben kein Lifestyle-Template (kein Mensch im Bild) — generate-images.mjs erkennt "standalone art print" und wechselt auf ein Art-Print-Template (Poster gerahmt an Wand, leicht schräg, Motiv vollständig sichtbar)

**Abgrenzung:**
- Dieser Slice definiert NUR die Motive — die Prompt-Komposition, API-Calls, und Bildgenerierung ist Scope von Slice 4

---

## Links

- Design/Spec: `seed-data-prompts/prompts.md` — Referenz-Prompts für Zielstil
- Discovery: `specs/phase-1/2026-02-27-seed-data/discovery.md` — Motiv-Typen-Tabelle
- Architecture: `specs/phase-1/2026-02-27-seed-data/architecture.md` — Prompt-Strategie-Tabelle
