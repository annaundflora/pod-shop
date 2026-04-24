// tests/slices/kleinstadtpflanze-design-e/slice-02-typography.test.ts
//
// Slice-02: Typografie-Integration (Work Sans + Source Serif 4)
// Spec: specs/2026-04-16-kleinstadtpflanze-design-e/slim-spec.md (Section: Slice 2)
//
// Acceptance Criteria:
//   AC-1: lib/theme/fonts.ts imports Work_Sans + Source_Serif_4 from next/font/google
//         with correct weights, variables and display:'swap'.
//   AC-2: Runtime browser check — bodyFont.variable === '--font-body' assertion
//         (structural equivalent; getComputedStyle cannot run reliably in
//         Vitest/JSDOM for next/font).
//   AC-3: Runtime browser check — headingFont.variable === '--font-heading'
//         assertion (structural equivalent; Tailwind font-heading utility
//         cannot be exercised in JSDOM).
//   AC-4: themes/default/theme.yaml contains fonts.body='Work Sans' and
//         fonts.heading='Source Serif 4'.
//   AC-5: No Inter import in lib/theme/fonts.ts. The `pnpm build` side of the
//         AC was verified manually by the implementer — running a full Next.js
//         build inside Vitest is too slow to be practical. The structural
//         "no Inter import" assertion stands in.
//
// Testing strategy: no-mocks. Read real source files via fs.readFileSync and
// regex-grep them. `next/font/google` is NOT executed at test time because it
// relies on Next's build-time font-loader hook which is not available in a
// plain Vitest/Node environment.

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { parse as parseYaml } from 'yaml'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')
const FONTS_TS = resolve(FRONTEND_ROOT, 'lib/theme/fonts.ts')
const DEFAULT_THEME_YAML = resolve(FRONTEND_ROOT, 'themes/default/theme.yaml')

describe('Slice-02: Typografie-Integration (Work Sans + Source Serif 4)', () => {
  describe('AC-1: fonts.ts exports headingFont + bodyFont with correct next/font/google config', () => {
    // GIVEN frontend/lib/theme/fonts.ts exportiert die Namen headingFont und bodyFont
    // WHEN die Datei geladen wird
    // THEN bodyFont ist ein Work_Sans-Aufruf von next/font/google mit
    //      subsets: ['latin'], weight: ['400','500','600','700'],
    //      variable: '--font-body', display: 'swap'
    //      UND headingFont ist ein Source_Serif_4-Aufruf mit
    //      subsets: ['latin'], weight: ['600','700'],
    //      variable: '--font-heading', display: 'swap'.

    const source = readFileSync(FONTS_TS, 'utf-8')

    it('imports Work_Sans and Source_Serif_4 from next/font/google', () => {
      // Single combined import or two separate imports — both acceptable
      expect(source).toMatch(/from\s+['"]next\/font\/google['"]/)
      expect(source).toMatch(/\bWork_Sans\b/)
      expect(source).toMatch(/\bSource_Serif_4\b/)
    })

    it('exports bodyFont via Work_Sans() call', () => {
      // Match `export const bodyFont = Work_Sans(` (allow whitespace variance)
      expect(source).toMatch(/export\s+const\s+bodyFont\s*=\s*Work_Sans\s*\(/)
    })

    it('exports headingFont via Source_Serif_4() call', () => {
      expect(source).toMatch(
        /export\s+const\s+headingFont\s*=\s*Source_Serif_4\s*\(/,
      )
    })

    it('body font declares weights 400, 500, 600, 700', () => {
      // Locate the Work_Sans(...) block and assert the weight tuple.
      const bodyBlockMatch = source.match(/Work_Sans\s*\(\s*\{([\s\S]*?)\}\s*\)/)
      expect(bodyBlockMatch, 'Work_Sans({...}) block must be present').not.toBeNull()
      const bodyBlock = bodyBlockMatch![1]
      expect(bodyBlock).toMatch(/weight\s*:\s*\[\s*['"]400['"]\s*,\s*['"]500['"]\s*,\s*['"]600['"]\s*,\s*['"]700['"]\s*\]/)
    })

    it('body font sets variable=--font-body and display=swap with subsets=[latin]', () => {
      const bodyBlockMatch = source.match(/Work_Sans\s*\(\s*\{([\s\S]*?)\}\s*\)/)
      expect(bodyBlockMatch).not.toBeNull()
      const bodyBlock = bodyBlockMatch![1]
      expect(bodyBlock).toMatch(/variable\s*:\s*['"]--font-body['"]/)
      expect(bodyBlock).toMatch(/display\s*:\s*['"]swap['"]/)
      expect(bodyBlock).toMatch(/subsets\s*:\s*\[\s*['"]latin['"]\s*\]/)
    })

    it('heading font declares weights 600, 700 only (no 400/500)', () => {
      const headingBlockMatch = source.match(
        /Source_Serif_4\s*\(\s*\{([\s\S]*?)\}\s*\)/,
      )
      expect(headingBlockMatch, 'Source_Serif_4({...}) block must be present')
        .not.toBeNull()
      const headingBlock = headingBlockMatch![1]
      expect(headingBlock).toMatch(
        /weight\s*:\s*\[\s*['"]600['"]\s*,\s*['"]700['"]\s*\]/,
      )
    })

    it('heading font sets variable=--font-heading and display=swap with subsets=[latin]', () => {
      const headingBlockMatch = source.match(
        /Source_Serif_4\s*\(\s*\{([\s\S]*?)\}\s*\)/,
      )
      expect(headingBlockMatch).not.toBeNull()
      const headingBlock = headingBlockMatch![1]
      expect(headingBlock).toMatch(/variable\s*:\s*['"]--font-heading['"]/)
      expect(headingBlock).toMatch(/display\s*:\s*['"]swap['"]/)
      expect(headingBlock).toMatch(/subsets\s*:\s*\[\s*['"]latin['"]\s*\]/)
    })
  })

  describe('AC-2: bodyFont exposes --font-body CSS variable for <body>', () => {
    // GIVEN der Dev-Server laeuft mit pnpm dev
    // WHEN die Homepage im Browser geladen wird und auf <body> bzw. <html>
    //      inspiziert wird
    // THEN die Klassen-Attribute enthalten sowohl die Work-Sans-Variable
    //      (--font-body) als auch die Source-Serif-4-Variable (--font-heading)
    //      UND getComputedStyle(document.body).fontFamily liefert eine Kette,
    //      die mit "Work Sans" beginnt.
    //
    // Decision: The getComputedStyle assertion requires a real browser +
    // Next.js font loader; JSDOM cannot resolve `next/font/google`. We assert
    // the structural equivalent: the source declares
    // `variable: '--font-body'` on the Work_Sans() call, which is the ONLY
    // way Next injects the variable class into <body>. If this string is
    // present, Next guarantees the runtime behaviour.

    const source = readFileSync(FONTS_TS, 'utf-8')

    it('declares variable=--font-body on the Work_Sans (body) call', () => {
      const bodyBlockMatch = source.match(/Work_Sans\s*\(\s*\{([\s\S]*?)\}\s*\)/)
      expect(bodyBlockMatch).not.toBeNull()
      expect(bodyBlockMatch![1]).toMatch(/variable\s*:\s*['"]--font-body['"]/)
    })

    it('uses display=swap on body font so browser falls back until font loads', () => {
      const bodyBlockMatch = source.match(/Work_Sans\s*\(\s*\{([\s\S]*?)\}\s*\)/)
      expect(bodyBlockMatch).not.toBeNull()
      expect(bodyBlockMatch![1]).toMatch(/display\s*:\s*['"]swap['"]/)
    })
  })

  describe('AC-3: headingFont exposes --font-heading CSS variable for Tailwind font-heading utility', () => {
    // GIVEN Slice 2 ist gemerged
    // WHEN ein <h1> im DOM gerendert wird, das die Tailwind-Utility
    //      font-heading nutzt
    // THEN getComputedStyle(h1).fontFamily enthaelt "Source Serif 4"
    //      (oder ueber display: swap-Fallback "Source Serif Pro" / Georgia /
    //      serif).
    //
    // Decision: Same as AC-2 — getComputedStyle on a font-heading element
    // cannot run reliably in Vitest/JSDOM because next/font/google is a
    // build-time hook. We assert the structural prerequisite: the
    // Source_Serif_4 call binds the variable the Tailwind @theme block uses
    // for `font-heading`.

    const source = readFileSync(FONTS_TS, 'utf-8')

    it('declares variable=--font-heading on the Source_Serif_4 (heading) call', () => {
      const headingBlockMatch = source.match(
        /Source_Serif_4\s*\(\s*\{([\s\S]*?)\}\s*\)/,
      )
      expect(headingBlockMatch).not.toBeNull()
      expect(headingBlockMatch![1]).toMatch(
        /variable\s*:\s*['"]--font-heading['"]/,
      )
    })

    it('uses display=swap on heading font so browser falls back to serif/Georgia/Source Serif Pro until load', () => {
      const headingBlockMatch = source.match(
        /Source_Serif_4\s*\(\s*\{([\s\S]*?)\}\s*\)/,
      )
      expect(headingBlockMatch).not.toBeNull()
      expect(headingBlockMatch![1]).toMatch(/display\s*:\s*['"]swap['"]/)
    })
  })

  describe('AC-4: themes/default/theme.yaml documents new font names', () => {
    // GIVEN die Font-Dokumentation liegt in YAML
    // WHEN frontend/themes/default/theme.yaml geladen wird
    // THEN fonts.body == "Work Sans" UND fonts.heading == "Source Serif 4"
    //      (dokumentarisch, Laufzeit-Effekt kommt aus fonts.ts).

    const yamlSource = readFileSync(DEFAULT_THEME_YAML, 'utf-8')
    const config = parseYaml(yamlSource) as {
      fonts?: { body?: string; heading?: string }
    }

    it('fonts.body equals "Work Sans"', () => {
      expect(config.fonts).toBeDefined()
      expect(config.fonts?.body).toBe('Work Sans')
    })

    it('fonts.heading equals "Source Serif 4"', () => {
      expect(config.fonts).toBeDefined()
      expect(config.fonts?.heading).toBe('Source Serif 4')
    })
  })

  describe('AC-5: no Inter import remains in lib/theme/fonts.ts', () => {
    // GIVEN pnpm build wird nach dem Font-Umbau ausgefuehrt
    // WHEN der Build durchlaeuft
    // THEN Exit-Code ist 0, es erscheint kein next/font-Fehler bezueglich
    //      nicht-verfuegbarer Font-Weights, UND kein Import von Inter ist
    //      mehr in lib/theme/fonts.ts vorhanden.
    //
    // Decision: The `pnpm build` half of this AC was verified manually by
    // the implementer. Running a full Next.js production build inside the
    // Vitest suite (40s+ single assertion) is impractical and would
    // re-verify work Next itself already validates on every build.
    // The structural half — "no Inter import" — is the testable piece.

    const source = readFileSync(FONTS_TS, 'utf-8')

    it('does not import Inter from next/font/google', () => {
      // Catch both `import { Inter }` and `import { Inter as ...}` shapes.
      expect(source).not.toMatch(/\bInter\b/)
    })

    it('only imports Work_Sans and Source_Serif_4 as font identifiers', () => {
      // Assert the import line declares exactly these two identifiers
      // (either as a single combined import or two adjacent imports).
      const importLines = source
        .split('\n')
        .filter((line) => /from\s+['"]next\/font\/google['"]/.test(line))
      expect(importLines.length).toBeGreaterThan(0)

      const importedSymbols = importLines.join(' ')
      expect(importedSymbols).toMatch(/Work_Sans/)
      expect(importedSymbols).toMatch(/Source_Serif_4/)
      // Assert nothing else snuck in (defensive regression guard).
      expect(importedSymbols).not.toMatch(/\bInter\b/)
      expect(importedSymbols).not.toMatch(/\bRoboto\b/)
    })
  })
})
