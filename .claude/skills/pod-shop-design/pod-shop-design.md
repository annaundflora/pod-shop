---
name: pod-shop-design
description: Design System Skill für den POD Shop. Nutze diesen Skill bei JEDER Frontend-Arbeit – neue Seiten, Komponenten, Layouts, Styling. Definiert Typography, Colors, Spacing, Components und Brand-Ästhetik. Stellt sicher, dass Claude konsistentes, professionelles Design produziert statt generischem AI-Output.
---

# POD Shop Design System

## Design-Philosophie

Dieser Shop verkauft Print-on-Demand Produkte. Der Traffic kommt überwiegend von Pinterest – visuell affine Nutzer, überwiegend mobil. Das Design muss:
- **Produkte in den Vordergrund stellen** (Bilder sind der Star)
- **Mobile-first** sein (Pinterest-Traffic = 80%+ mobil)
- **Schnell laden** (keine schweren Animationen, Performance zählt)
- **Vertrauen aufbauen** (seriös genug zum Kaufen, aber nicht steril)

## Ästhetische Richtung

**Tone:** Clean editorial mit warmer Note. Denke: ein gut kuratierter Independent-Shop, nicht Amazon, nicht Etsy. Professionell aber mit Persönlichkeit.

**NICHT:** Generischer E-Commerce Look, Dropshipping-Vibes, überladene Seiten, Stock-Photo-Feeling.

## Typography

### Font-Pairing
- **Display/Headlines:** Wähle EINE distinctive Serif oder Sans-Serif. Beispiele die zum Tone passen: Bricolage Grotesque, Newsreader, Crimson Pro, Source Serif 4, Fraunces. NICHT: Inter, Roboto, Open Sans, Lato, System Fonts.
- **Body:** Ein gut lesbarer Sans-Serif als Kontrast. Beispiele: DM Sans, IBM Plex Sans, Source Sans 3.
- **Laden von:** Google Fonts via `next/font/google` (optimiert für Next.js)

### Typografische Hierarchie
- Extreme Größenunterschiede nutzen (3x Sprünge, nicht 1.5x)
- Headlines: Gewicht 700-900
- Body: Gewicht 400
- Preis-Darstellung: Monospace oder tabular-nums für Alignment
- Line-Height: Headlines eng (1.1-1.2), Body großzügig (1.5-1.6)

## Farben

### Prinzip
Ein dominanter Ton + scharfer Akzent. NICHT: gleichmäßig verteilte Pastelltöne oder das typische Lila/Blau-Gradient.

### Tailwind Config Pattern
```
// tailwind.config.ts – Beispiel-Struktur
// PASSE DIESE WERTE AN DEINE BRAND AN
colors: {
  brand: {
    DEFAULT: '...', // Hauptfarbe
    light: '...',
    dark: '...',
  },
  accent: '...',     // Akzent für CTAs, Badges
  surface: {
    DEFAULT: '...',  // Hintergrund
    elevated: '...', // Karten, Modals
  },
  text: {
    primary: '...',
    secondary: '...',
    muted: '...',
  }
}
```

### Dark/Light
- Starte mit EINEM Theme (das zum Brand passt)
- CSS Variables für spätere Erweiterung nutzen

## Spacing & Layout

### Grid
- Container max-width: 1280px
- Produkt-Grids: 
  - Mobile: 2 Spalten
  - Tablet: 3 Spalten  
  - Desktop: 4 Spalten
- Großzügiger Whitespace zwischen Sections (py-16 bis py-24)
- Produkt-Cards: Konsistenter Gap (gap-4 bis gap-6)

### Produkt-Bilder
- Bilder sind das wichtigste Element – GROSS anzeigen
- Aspect-Ratio konsistent halten (z.B. 3:4 für Bekleidung)
- object-fit: cover mit abgerundeten Ecken
- Hover: Subtiler Scale-Effekt (scale-105) oder zweites Bild zeigen

## Komponenten-Patterns

### Produkt-Card
- Bild dominant (min 70% der Card-Fläche)
- Produktname: 1-2 Zeilen, truncate wenn nötig
- Preis: Klar sichtbar, eigene Gewichtung
- KEINE überladenen Badges, Ratings, Discount-Tags im MVP
- Hover-State: Subtil aber spürbar

### Buttons
- Primary CTA: Volle Farbe, groß genug für Touch (min h-12 auf Mobile)
- "In den Warenkorb": Auffällig, immer sichtbar auf Produktseite
- "Zur Kasse": Noch auffälliger als Add-to-Cart
- Disabled-State: Deutlich erkennbar, nicht nur opacity

### Navigation
- Minimal: Logo, Kategorien, Warenkorb-Icon mit Badge
- Mobile: Hamburger oder Bottom-Nav
- Warenkorb-Badge zeigt Anzahl der Produkte
- Sticky Header für schnellen Zugriff

### Warenkorb
- Klare Produktübersicht mit kleinem Bild
- Menge ändern: +/- Buttons, gut tippbar
- Gesamtpreis immer sichtbar
- "Zur Kasse"-Button fixiert am unteren Rand (Mobile)

### Varianten-Auswahl
- Farben: Farbkreise (Swatches), nicht Dropdown
- Größen: Button-Gruppe, nicht Dropdown
- Ausgewählt: Klarer Active-State (Border, Background-Change)
- Nicht verfügbar: Durchgestrichen oder ausgegraut

## Motion & Interaction

### Prinzip
Subtil und purposeful. Kein Wow-Faktor nötig – die Produkte sind der Wow-Faktor.

### Was animieren
- Page transitions: Sanftes Fade-In beim Seitenwechsel
- Produkt-Cards: Hover-Scale (transform: scale(1.02-1.05))
- "In den Warenkorb": Kurzes Feedback (Checkmark, Badge-Animation)
- Skeleton-Loading für Produktbilder

### Was NICHT animieren
- Keine aufwändigen Scroll-Animationen (Performance!)
- Keine Parallax-Effekte
- Keine automatischen Karussells

## Hintergründe & Texturen

### Prinzip
Clean aber nicht steril. Leichte Tiefe erzeugen.

- Haupthintergrund: Nicht reines Weiß (#ffffff), sondern leicht warm (z.B. #fafaf8 oder #f8f7f4)
- Karten: Leicht elevated (subtle shadow oder Border)
- KEINE: Gradient-Meshes, Noise-Overlays, Pattern-Backgrounds auf Shop-Seiten (lenkt von Produkten ab)

## Responsive Breakpoints

Tailwind Standard nutzen:
- sm: 640px
- md: 768px  
- lg: 1024px
- xl: 1280px

**Mobile-first heißt:** Default-Styles SIND die Mobile-Styles. Desktop ist die Erweiterung.

## Performance-Regeln

- Bilder: next/image mit automatischer Optimierung
- Fonts: next/font/google (kein externen Google Fonts Request)
- Keine externen CSS-Frameworks außer Tailwind
- Keine schweren JS-Libraries für Animationen (CSS transitions reichen)
- Lazy Loading für Produkt-Bilder unterhalb des Folds

## Code-Konventionen

- Tailwind für alles – kein custom CSS außer für Spezialfälle
- shadcn/ui als Komponenten-Basis wo sinnvoll
- Alle Farben/Spacing über Tailwind Config (nicht hardcoded)
- Responsive Klassen immer mitdenken
- Semantic HTML (nav, main, section, article)
- Accessibility: Alt-Texte für Produktbilder, aria-labels für Icons