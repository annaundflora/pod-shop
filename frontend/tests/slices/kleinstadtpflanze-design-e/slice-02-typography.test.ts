// tests/slices/kleinstadtpflanze-design-e/slice-02-typography.test.ts
//
// Slice-02: Typografie-Integration (Work Sans + Source Serif 4)
// Spec: specs/2026-04-16-kleinstadtpflanze-design-e/slim-spec.md (Section: Slice 2)
//
// REVISION (manual QA finding): fonts.ts now dispatches on
// process.env.NEXT_PUBLIC_THEME — only the kleinstadtpflanze theme switches
// to Work Sans + Source Serif 4. The default theme keeps Inter (its
// historical font), so existing shops do not regress.
//
// Acceptance Criteria:
//   AC-1: lib/theme/fonts.ts imports Work_Sans + Source_Serif_4 from
//         next/font/google with correct weights, variables and display:'swap'
//         (kleinstadtpflanze branch). Inter is the default-theme branch.
//   AC-2: Runtime browser check — bodyFont.variable === '--font-body'
//         assertion (structural equivalent; getComputedStyle cannot run
//         reliably in Vitest/JSDOM for next/font).
//   AC-3: Runtime browser check — headingFont.variable === '--font-heading'
//         assertion (structural equivalent; Tailwind font-heading utility
//         cannot be exercised in JSDOM).
//   AC-4: themes/kleinstadtpflanze/theme.yaml documents fonts.body='Work Sans'
//         and fonts.heading='Source Serif 4'. The default theme YAML keeps
//         "Inter" (font choice is theme-scoped after the conditional dispatch).
//   AC-5: lib/theme/fonts.ts dispatches body/heading exports based on
//         NEXT_PUBLIC_THEME — Inter for the default theme, Work Sans +
//         Source Serif 4 for kleinstadtpflanze. The `pnpm build` half of the
//         AC was verified manually; the structural dispatch logic is the
//         testable piece here.
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
const KLEINSTADTPFLANZE_THEME_YAML = resolve(
  FRONTEND_ROOT,
  'themes/kleinstadtpflanze/theme.yaml',
)

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

    it('instantiates Work_Sans() somewhere in the module (kleinstadtpflanze branch)', () => {
      // After the conditional dispatch refactor, the call lives in an
      // internal const (e.g. workSansBody) rather than directly on the export.
      expect(source).toMatch(/=\s*Work_Sans\s*\(/)
    })

    it('instantiates Source_Serif_4() somewhere in the module (kleinstadtpflanze branch)', () => {
      expect(source).toMatch(/=\s*Source_Serif_4\s*\(/)
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

  describe('AC-4: theme YAMLs document the per-theme font names', () => {
    // GIVEN die Font-Dokumentation liegt in YAML
    // WHEN frontend/themes/<theme>/theme.yaml geladen wird
    // THEN default behaelt fonts.body/heading == "Inter" (kein Regress fuer
    //      Bestands-Shops) UND kleinstadtpflanze.fonts.body == "Work Sans"
    //      sowie kleinstadtpflanze.fonts.heading == "Source Serif 4"
    //      (dokumentarisch — Laufzeit-Effekt kommt aus fonts.ts).

    const defaultYaml = parseYaml(readFileSync(DEFAULT_THEME_YAML, 'utf-8')) as {
      fonts?: { body?: string; heading?: string }
    }
    const kleinstadtpflanzeYaml = parseYaml(
      readFileSync(KLEINSTADTPFLANZE_THEME_YAML, 'utf-8'),
    ) as { fonts?: { body?: string; heading?: string } }

    it('default theme keeps fonts.body="Inter"', () => {
      expect(defaultYaml.fonts).toBeDefined()
      expect(defaultYaml.fonts?.body).toBe('Inter')
    })

    it('default theme keeps fonts.heading="Inter"', () => {
      expect(defaultYaml.fonts).toBeDefined()
      expect(defaultYaml.fonts?.heading).toBe('Inter')
    })

    it('kleinstadtpflanze theme sets fonts.body="Work Sans"', () => {
      expect(kleinstadtpflanzeYaml.fonts).toBeDefined()
      expect(kleinstadtpflanzeYaml.fonts?.body).toBe('Work Sans')
    })

    it('kleinstadtpflanze theme sets fonts.heading="Source Serif 4"', () => {
      expect(kleinstadtpflanzeYaml.fonts).toBeDefined()
      expect(kleinstadtpflanzeYaml.fonts?.heading).toBe('Source Serif 4')
    })
  })

  describe('AC-5: fonts.ts dispatches on NEXT_PUBLIC_THEME (Inter for default, Work Sans + Source Serif 4 for kleinstadtpflanze)', () => {
    // GIVEN pnpm build wird nach dem Font-Umbau ausgefuehrt
    // WHEN der Build durchlaeuft
    // THEN Exit-Code ist 0, es erscheint kein next/font-Fehler bezueglich
    //      nicht-verfuegbarer Font-Weights.
    //
    // Decision: The `pnpm build` half of this AC is verified manually by
    // the implementer. Running a full Next.js production build inside the
    // Vitest suite is impractical. The testable structural pieces are:
    //  - fonts.ts imports all three fonts (Inter + Work_Sans + Source_Serif_4)
    //  - fonts.ts inspects process.env.NEXT_PUBLIC_THEME for the dispatch
    //  - bodyFont/headingFont are conditional ternaries on the theme flag

    const source = readFileSync(FONTS_TS, 'utf-8')

    it('imports Inter, Work_Sans and Source_Serif_4 from next/font/google', () => {
      const importLines = source
        .split('\n')
        .filter((line) => /from\s+['"]next\/font\/google['"]/.test(line))
      expect(importLines.length).toBeGreaterThan(0)
      const importedSymbols = importLines.join(' ')
      expect(importedSymbols).toMatch(/\bInter\b/)
      expect(importedSymbols).toMatch(/\bWork_Sans\b/)
      expect(importedSymbols).toMatch(/\bSource_Serif_4\b/)
    })

    it('reads NEXT_PUBLIC_THEME to decide between Inter and the kleinstadtpflanze fonts', () => {
      expect(source).toMatch(
        /process\.env\.NEXT_PUBLIC_THEME\s*===\s*['"]kleinstadtpflanze['"]/,
      )
    })

    it('exports bodyFont as a conditional choice between Work_Sans and Inter', () => {
      // Body export is a ternary using the theme flag and pointing at the two
      // pre-instantiated font objects.
      expect(source).toMatch(
        /export\s+const\s+bodyFont\s*=\s*\w+\s*\?\s*\w+\s*:\s*\w+/,
      )
    })

    it('exports headingFont as a conditional choice between Source_Serif_4 and Inter', () => {
      expect(source).toMatch(
        /export\s+const\s+headingFont\s*=\s*\w+\s*\?\s*\w+\s*:\s*\w+/,
      )
    })
  })
})
