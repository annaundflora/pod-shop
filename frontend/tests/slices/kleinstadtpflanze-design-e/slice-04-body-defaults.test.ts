// tests/slices/kleinstadtpflanze-design-e/slice-04-body-defaults.test.ts
//
// Slice-04: Body-Defaults aus Design E (15px / 1.55)
// Spec: specs/2026-04-16-kleinstadtpflanze-design-e/slim-spec.md (Section: Slice 4)
//
// Acceptance Criteria:
//   AC-1: @layer base body rule contains `font-size: 15px` AND `line-height: 1.55`
//   AC-2: structural equivalent assertion (JSDOM + CSS-layers + Tailwind JIT kann
//         getComputedStyle nicht zuverlaessig aufloesen — assert CSS-Source stattdessen)
//   AC-3: Heading-Rule (h1..h6) bleibt erhalten (text-wrap: balance, font-semibold)
//   AC-4: Body-Rule behaelt @apply antialiased text-text-primary bg-surface
//   AC-5: touch-action: manipulation bleibt erhalten UND --min-touch-target: 2.75rem ist gesetzt
//
// Testing strategy: no-mocks. Wir lesen den echten Source von app/globals.css,
// parsen die @layer base / @theme Bloecke mittels Regex/Klammer-Matching und
// stellen strukturelle Assertions. Browser-Level getComputedStyle (AC-2) ist
// in Vitest+JSDOM mit Tailwind v4 CSS-Layers nicht reliabel aufloesbar — das
// ist explizit dokumentiert und wird durch Source-Assertion substituiert.

import { describe, it, expect, beforeAll } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')
const GLOBALS_CSS = resolve(FRONTEND_ROOT, 'app/globals.css')

/**
 * Extract a CSS rule body (text between `{` and matching `}`) for a selector
 * inside a given search window. Uses manual brace-depth counting so nested
 * `@apply` / declarations don't confuse the matcher.
 *
 * selectorPattern: regex that matches the *start* of the rule (the selector
 *                  plus its opening `{`). The returned string is everything
 *                  between the selector's `{` and the matching `}`.
 */
function extractRuleBody(source: string, selectorPattern: RegExp): string | null {
  const match = selectorPattern.exec(source)
  if (!match) return null

  // Find the `{` that belongs to this rule (at or after the match end).
  let i = match.index + match[0].length - 1
  if (source[i] !== '{') {
    // selectorPattern didn't include the `{` — find the next one.
    i = source.indexOf('{', match.index + match[0].length)
    if (i === -1) return null
  }

  let depth = 1
  const start = i + 1
  i++
  while (i < source.length && depth > 0) {
    const ch = source[i]
    if (ch === '{') depth++
    else if (ch === '}') depth--
    if (depth === 0) break
    i++
  }
  if (depth !== 0) return null
  return source.slice(start, i)
}

let globalsSource: string
let baseLayerBody: string | null

describe('slice-04: Body-Defaults aus Design E (15px / 1.55)', () => {
  beforeAll(() => {
    globalsSource = readFileSync(GLOBALS_CSS, 'utf-8')
    baseLayerBody = extractRuleBody(globalsSource, /@layer\s+base\s*\{/)
  })

  describe('AC-1: @layer base body rule sets font-size: 15px and line-height: 1.55', () => {
    it(
      'AC-1: GIVEN app/globals.css enthaelt @layer base block fuer body, ' +
        'WHEN die Datei geparst wird, ' +
        'THEN die body-Regel enthaelt sowohl `font-size: 15px` ALS AUCH `line-height: 1.55`',
      () => {
        expect(baseLayerBody, '@layer base block missing in globals.css').not.toBeNull()

        // Extrahiere die body { ... } Regel innerhalb von @layer base.
        const bodyRuleBody = extractRuleBody(baseLayerBody!, /(^|\s)body\s*\{/)
        expect(bodyRuleBody, 'body { ... } rule missing inside @layer base').not.toBeNull()

        // Toleriere optionalen Whitespace zwischen Eigenschaft, Doppelpunkt und Wert,
        // sowie optionales Semikolon-Trailing.
        expect(
          /font-size\s*:\s*15px\s*;/.test(bodyRuleBody!),
          `body rule missing "font-size: 15px;" — actual body:\n${bodyRuleBody}`,
        ).toBe(true)
        expect(
          /line-height\s*:\s*1\.55\s*;/.test(bodyRuleBody!),
          `body rule missing "line-height: 1.55;" — actual body:\n${bodyRuleBody}`,
        ).toBe(true)
      },
    )
  })

  describe('AC-2: structural equivalent for getComputedStyle assertion', () => {
    // DECISION: getComputedStyle(document.body) kann in Vitest + JSDOM + Tailwind v4
    // (CSS-Layers + @theme + @import chain) nicht zuverlaessig die gemergte
    // Laufzeit-Typografie aufloesen. Browser-Verifikation erfolgt manuell im Dev-Server.
    // Als deterministisches Substitut pruefen wir, dass die CSS-Quelle exakt die
    // Werte enthaelt, die der Browser zu `15px` / `1.55` aufloesen wird.
    it(
      'AC-2: GIVEN Browser-Level getComputedStyle ist in JSDOM nicht reliabel, ' +
        'WHEN wir die CSS-Quelle strukturell pruefen, ' +
        'THEN body enthaelt literal "font-size: 15px;" und "line-height: 1.55;" ' +
        '(der Browser wird diese zu "15px" / "23.25px"-Aequivalent aufloesen)',
      () => {
        // Wir suchen bewusst im kompletten globals.css, damit die Assertion nicht an
        // der Regel-Extraktion haengt — das ist der strukturelle Substitut fuer AC-2.
        expect(globalsSource).toMatch(/font-size\s*:\s*15px\s*;/)
        expect(globalsSource).toMatch(/line-height\s*:\s*1\.55\s*;/)
      },
    )
  })

  describe('AC-3: Heading-Overrides bleiben erhalten', () => {
    it(
      'AC-3: GIVEN die Anpassungen sind aktiv, ' +
        'WHEN h1..h6-Rule innerhalb @layer base geprueft wird, ' +
        'THEN sie existiert und enthaelt text-wrap: balance sowie font-semibold (via @apply)',
      () => {
        expect(baseLayerBody, '@layer base block missing').not.toBeNull()

        // Heading-Selector: entweder die volle Liste h1..h6 oder zumindest ein h1-Einstieg.
        // Wir matchen die erste Regel, deren Selector mit `h1` beginnt.
        const headingRuleBody = extractRuleBody(baseLayerBody!, /(^|\s)h1[^{]*\{/)
        expect(
          headingRuleBody,
          'Heading rule (h1, ...) missing inside @layer base — structural regression',
        ).not.toBeNull()

        // text-wrap: balance (Design-E-Typografie-Erbe)
        expect(
          /text-wrap\s*:\s*balance\s*;/.test(headingRuleBody!),
          `Heading rule missing "text-wrap: balance;" — actual:\n${headingRuleBody}`,
        ).toBe(true)

        // @apply font-semibold (Tailwind-Utility via @apply)
        expect(
          /@apply[^;]*\bfont-semibold\b/.test(headingRuleBody!),
          `Heading rule missing "@apply ... font-semibold" — actual:\n${headingRuleBody}`,
        ).toBe(true)
      },
    )
  })

  describe('AC-4: Body-Rule behaelt bestehende @apply-Klassen', () => {
    it(
      'AC-4: GIVEN die bestehenden Body-Settings, ' +
        'WHEN die body-Regel inspiziert wird, ' +
        'THEN `@apply antialiased text-text-primary bg-surface` ist weiterhin vorhanden ' +
        '(alle drei Klassen als Substring im body-Block)',
      () => {
        expect(baseLayerBody, '@layer base block missing').not.toBeNull()
        const bodyRuleBody = extractRuleBody(baseLayerBody!, /(^|\s)body\s*\{/)
        expect(bodyRuleBody, 'body rule missing').not.toBeNull()

        // Ein einziger @apply mit allen drei Klassen (Reihenfolge spec-gemaess beibehalten).
        // Wir pruefen Substring-Praesenz in genau dieser @apply-Zeile statt strikte Reihenfolge,
        // damit spaeter auch andere Ordnung nicht faelschlich faelschlich bricht — aber
        // der Spec-Deliverable verlangt explizit diese drei Klassen.
        expect(
          /@apply[^;]*\bantialiased\b/.test(bodyRuleBody!),
          `body @apply missing "antialiased" — actual:\n${bodyRuleBody}`,
        ).toBe(true)
        expect(
          /@apply[^;]*\btext-text-primary\b/.test(bodyRuleBody!),
          `body @apply missing "text-text-primary" — actual:\n${bodyRuleBody}`,
        ).toBe(true)
        expect(
          /@apply[^;]*\bbg-surface\b/.test(bodyRuleBody!),
          `body @apply missing "bg-surface" — actual:\n${bodyRuleBody}`,
        ).toBe(true)
      },
    )
  })

  describe('AC-5: touch-action und --min-touch-target bleiben erhalten', () => {
    it(
      'AC-5a: GIVEN --min-touch-target: 2.75rem ist weiter gesetzt, ' +
        'WHEN globals.css geprueft wird, ' +
        'THEN die CSS-Variable existiert (im @theme-Block erwartet)',
      () => {
        expect(
          /--min-touch-target\s*:\s*2\.75rem\s*;/.test(globalsSource),
          'CSS variable "--min-touch-target: 2.75rem;" missing in globals.css',
        ).toBe(true)
      },
    )

    it(
      'AC-5b: GIVEN die Mobile-Touch-Setup-Regel fuer button, a, ' +
        'WHEN globals.css geprueft wird, ' +
        'THEN `touch-action: manipulation;` ist weiterhin vorhanden ' +
        '(keine Regression fuer Pinterest-Mobile-Traffic)',
      () => {
        expect(
          /touch-action\s*:\s*manipulation\s*;/.test(globalsSource),
          '"touch-action: manipulation;" missing in globals.css — Mobile regression',
        ).toBe(true)
      },
    )
  })
})
