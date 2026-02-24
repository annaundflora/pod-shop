// tests/slices/frontend-theming/slice-02-shadcn-ui-primitives.test.ts
/**
 * Acceptance Tests for Slice 02: shadcn/ui Primitives.
 * Derived from GIVEN/WHEN/THEN Acceptance Criteria in the Slice-Spec.
 *
 * AC-1:  Button mit data-slot="button" (default, outline, ghost, destructive, secondary, link)
 * AC-2:  Button Size Varianten (default, sm, lg, icon)
 * AC-3:  Input mit data-slot="input"
 * AC-4:  Card compound components mit data-slot Attributen
 * AC-5:  Badge mit data-slot="badge" und Varianten
 * AC-6:  Skeleton mit data-slot="skeleton" + animate-pulse
 * AC-7:  Dialog exportiert DialogTrigger, DialogContent, DialogTitle, DialogDescription
 * AC-8:  Sheet exportiert SheetTrigger, SheetContent mit side prop
 * AC-9:  layout.tsx bindet Inter Font ein (font className am html)
 * AC-10: globals.css Import-Reihenfolge (generated-theme.css -> tailwindcss -> tw-animate-css)
 * AC-11: Keine hardcoded Tailwind Farben in UI-Komponenten
 */
import { describe, it, expect } from 'vitest'
import { render } from '@testing-library/react'
import { existsSync, readFileSync } from 'fs'
import { resolve } from 'path'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')

// ─── Datei-Existenz Tests ────────────────────────────────────────────────────

describe('shadcn/ui Primitives — Dateien existieren', () => {
  const uiFiles = [
    'components/ui/button.tsx',
    'components/ui/input.tsx',
    'components/ui/card.tsx',
    'components/ui/badge.tsx',
    'components/ui/skeleton.tsx',
    'components/ui/dialog.tsx',
    'components/ui/sheet.tsx',
  ]

  for (const file of uiFiles) {
    it(`should have ${file}`, () => {
      expect(existsSync(resolve(FRONTEND_ROOT, file))).toBe(true)
    })
  }

  it('should have lib/theme/fonts.ts', () => {
    expect(existsSync(resolve(FRONTEND_ROOT, 'lib/theme/fonts.ts'))).toBe(true)
  })
})

// ─── AC-1: Button Variant Tests ─────────────────────────────────────────────

describe('AC-1: Button Component — Variants', () => {
  it('should render with data-slot="button" and default variant', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button>Klick mich</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn).toBeTruthy()
    expect(btn?.textContent).toBe('Klick mich')
  })

  it('should apply default variant classes (bg-primary)', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button variant="default">Test</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('bg-primary')
  })

  it('should apply destructive variant (bg-error)', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button variant="destructive">Loeschen</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('bg-error')
  })

  it('should apply outline variant (border-border)', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button variant="outline">Outline</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('border-border')
  })

  it('should apply ghost variant (bg-transparent)', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button variant="ghost">Ghost</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('bg-transparent')
  })

  it('should apply secondary variant (bg-surface-elevated)', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button variant="secondary">Secondary</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('bg-surface-elevated')
  })

  it('should apply link variant (text-primary, underline-offset)', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button variant="link">Link</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('text-primary')
    expect(btn?.className).toContain('underline-offset-4')
  })

  it('should be disabled when isLoading=true', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button isLoading>Laden...</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn).toHaveAttribute('disabled')
    expect(btn).toHaveAttribute('aria-disabled', 'true')
  })

  it('should render spinner when isLoading=true', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button isLoading>Laden...</Button>)
    const spinner = container.querySelector('svg')
    expect(spinner).toBeTruthy()
    expect(spinner).toHaveAttribute('aria-hidden', 'true')
  })

  it('should have [touch-action:manipulation] CVA class', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button>Mobile</Button>)
    const btn = container.querySelector('[data-slot="button"]') as HTMLElement
    expect(btn?.className).toContain('[touch-action:manipulation]')
  })

  it('should render as Slot with asChild=true', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(
      <Button asChild>
        <a href="/test">Link Button</a>
      </Button>
    )
    const anchor = container.querySelector('a')
    expect(anchor).toBeTruthy()
    expect(anchor?.textContent).toBe('Link Button')
  })

  it('should have focus-visible ring class', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button>Focus</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('focus-visible:ring-2')
  })
})

// ─── AC-2: Button Size Variant Tests ────────────────────────────────────────

describe('AC-2: Button Component — Size Variants', () => {
  it('should have min touch target height for default size', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button>Touch</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('h-[var(--min-touch-target')
  })

  it('should apply sm size (h-8)', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button size="sm">Small</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('h-8')
  })

  it('should apply lg size (h-12)', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button size="lg">Large</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('h-12')
  })

  it('should apply icon size (square min-touch-target)', async () => {
    const { Button } = await import('../../../components/ui/button')
    const { container } = render(<Button size="icon">X</Button>)
    const btn = container.querySelector('[data-slot="button"]')
    expect(btn?.className).toContain('h-[var(--min-touch-target')
    expect(btn?.className).toContain('w-[var(--min-touch-target')
  })
})

// ─── AC-3: Input Tests ──────────────────────────────────────────────────────

describe('AC-3: Input Component', () => {
  it('should render with data-slot="input" and default variant', async () => {
    const { Input } = await import('../../../components/ui/input')
    const { container } = render(<Input placeholder="Test" />)
    const input = container.querySelector('[data-slot="input"]')
    expect(input).toBeTruthy()
    expect(input?.className).toContain('border-border')
  })

  it('should apply error variant class (border-error)', async () => {
    const { Input } = await import('../../../components/ui/input')
    const { container } = render(<Input variant="error" />)
    const input = container.querySelector('[data-slot="input"]')
    expect(input?.className).toContain('border-error')
  })

  it('should have aria-invalid=true with error variant', async () => {
    const { Input } = await import('../../../components/ui/input')
    const { container } = render(<Input variant="error" />)
    const input = container.querySelector('[data-slot="input"]')
    expect(input).toHaveAttribute('aria-invalid', 'true')
  })

  it('should show errorMessage and have aria-describedby', async () => {
    const { Input } = await import('../../../components/ui/input')
    const { container } = render(
      <Input id="email" variant="error" errorMessage="Pflichtfeld" />
    )
    const errorEl = container.querySelector('[data-slot="input-error"]')
    expect(errorEl).toBeTruthy()
    expect(errorEl?.textContent).toBe('Pflichtfeld')
    expect(errorEl).toHaveAttribute('role', 'alert')

    const input = container.querySelector('[data-slot="input"]')
    expect(input).toHaveAttribute('aria-describedby', 'email-error')
  })

  it('should have min touch target height', async () => {
    const { Input } = await import('../../../components/ui/input')
    const { container } = render(<Input />)
    const input = container.querySelector('[data-slot="input"]')
    expect(input?.className).toContain('h-[var(--min-touch-target')
  })
})

// ─── AC-4: Card Tests ───────────────────────────────────────────────────────

describe('AC-4: Card Component — compound components with data-slot', () => {
  it('should render default variant with shadow-card token', async () => {
    const { Card } = await import('../../../components/ui/card')
    const { container } = render(<Card>Inhalt</Card>)
    const card = container.querySelector('[data-slot="card"]')
    expect(card).toBeTruthy()
    expect(card?.className).toContain('shadow-[var(--shadow-card)]')
  })

  it('should render interactive variant with hover classes', async () => {
    const { Card } = await import('../../../components/ui/card')
    const { container } = render(<Card variant="interactive">Interaktiv</Card>)
    const card = container.querySelector('[data-slot="card"]')
    expect(card?.className).toContain('cursor-pointer')
    expect(card?.className).toContain('hover:shadow-[var(--shadow-card-hover)]')
  })

  it('should render Card compound components with data-slot attributes', async () => {
    const { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } =
      await import('../../../components/ui/card')
    const { container } = render(
      <Card>
        <CardHeader>
          <CardTitle>Titel</CardTitle>
          <CardDescription>Beschreibung</CardDescription>
        </CardHeader>
        <CardContent>Inhalt</CardContent>
        <CardFooter>Footer</CardFooter>
      </Card>
    )
    expect(container.querySelector('[data-slot="card"]')).toBeTruthy()
    expect(container.querySelector('[data-slot="card-header"]')).toBeTruthy()
    expect(container.querySelector('[data-slot="card-title"]')?.textContent).toBe('Titel')
    expect(container.querySelector('[data-slot="card-description"]')?.textContent).toBe('Beschreibung')
    expect(container.querySelector('[data-slot="card-content"]')?.textContent).toBe('Inhalt')
    expect(container.querySelector('[data-slot="card-footer"]')?.textContent).toBe('Footer')
  })

  it('should use --radius-card token', async () => {
    const { Card } = await import('../../../components/ui/card')
    const { container } = render(<Card>Test</Card>)
    const card = container.querySelector('[data-slot="card"]')
    expect(card?.className).toContain('rounded-[var(--radius-card')
  })
})

// ─── AC-5: Badge Tests ──────────────────────────────────────────────────────

describe('AC-5: Badge Component — Variants', () => {
  it('should render default variant with data-slot="badge" (bg-primary)', async () => {
    const { Badge } = await import('../../../components/ui/badge')
    const { container } = render(<Badge>Neu</Badge>)
    const badge = container.querySelector('[data-slot="badge"]')
    expect(badge).toBeTruthy()
    expect(badge?.className).toContain('bg-primary')
    expect(badge?.className).toContain('text-white')
  })

  it('should render secondary variant (bg-surface-elevated)', async () => {
    const { Badge } = await import('../../../components/ui/badge')
    const { container } = render(<Badge variant="secondary">Sekundaer</Badge>)
    const badge = container.querySelector('[data-slot="badge"]')
    expect(badge?.className).toContain('bg-surface-elevated')
  })

  it('should render destructive variant (bg-error)', async () => {
    const { Badge } = await import('../../../components/ui/badge')
    const { container } = render(<Badge variant="destructive">Fehler</Badge>)
    const badge = container.querySelector('[data-slot="badge"]')
    expect(badge?.className).toContain('bg-error')
  })

  it('should render outline variant (bg-transparent, border-border)', async () => {
    const { Badge } = await import('../../../components/ui/badge')
    const { container } = render(<Badge variant="outline">Outline</Badge>)
    const badge = container.querySelector('[data-slot="badge"]')
    expect(badge?.className).toContain('bg-transparent')
    expect(badge?.className).toContain('border-border')
  })
})

// ─── AC-6: Skeleton Tests ───────────────────────────────────────────────────

describe('AC-6: Skeleton Component', () => {
  it('should render with data-slot="skeleton", animate-pulse and bg-surface-elevated', async () => {
    const { Skeleton } = await import('../../../components/ui/skeleton')
    const { container } = render(<Skeleton className="h-4 w-3/4" />)
    const skeleton = container.querySelector('[data-slot="skeleton"]')
    expect(skeleton).toBeTruthy()
    expect(skeleton?.className).toContain('animate-pulse')
    expect(skeleton?.className).toContain('bg-surface-elevated')
  })

  it('should have aria-busy="true" and aria-label for screen readers', async () => {
    const { Skeleton } = await import('../../../components/ui/skeleton')
    const { container } = render(<Skeleton />)
    const skeleton = container.querySelector('[data-slot="skeleton"]')
    expect(skeleton).toHaveAttribute('aria-busy', 'true')
    expect(skeleton).toHaveAttribute('aria-label', 'Wird geladen...')
  })

  it('should accept custom className for sizing', async () => {
    const { Skeleton } = await import('../../../components/ui/skeleton')
    const { container } = render(<Skeleton className="h-12 w-full" />)
    const skeleton = container.querySelector('[data-slot="skeleton"]')
    expect(skeleton?.className).toContain('h-12')
    expect(skeleton?.className).toContain('w-full')
  })
})

// ─── AC-7: Dialog Tests ─────────────────────────────────────────────────────

describe('AC-7: Dialog Component — exports and renderability', () => {
  it('should export DialogTrigger, DialogContent, DialogTitle, DialogDescription', async () => {
    const dialog = await import('../../../components/ui/dialog')
    expect(dialog.Dialog).toBeDefined()
    expect(dialog.DialogTrigger).toBeDefined()
    expect(dialog.DialogContent).toBeDefined()
    expect(dialog.DialogTitle).toBeDefined()
    expect(dialog.DialogDescription).toBeDefined()
  })

  it('should render Dialog components without errors', async () => {
    const { Dialog, DialogTrigger, DialogContent, DialogHeader, DialogTitle, DialogDescription } =
      await import('../../../components/ui/dialog')
    expect(() =>
      render(
        <Dialog>
          <DialogTrigger>Oeffnen</DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Titel</DialogTitle>
              <DialogDescription>Beschreibung</DialogDescription>
            </DialogHeader>
          </DialogContent>
        </Dialog>
      )
    ).not.toThrow()
  })

  it('should have close button with aria-label="Dialog schliessen" when open', async () => {
    const { Dialog, DialogTrigger, DialogContent } = await import('../../../components/ui/dialog')
    render(
      <Dialog open>
        <DialogTrigger>Oeffnen</DialogTrigger>
        <DialogContent>Inhalt</DialogContent>
      </Dialog>
    )
    // Dialog uses Radix Portal — content renders to document.body, not container
    const closeBtn = document.querySelector('[data-slot="dialog-close"]')
    expect(closeBtn).toHaveAttribute('aria-label', 'Dialog schließen')
  })
})

// ─── AC-8: Sheet Tests ──────────────────────────────────────────────────────

describe('AC-8: Sheet Component — exports and side prop', () => {
  it('should export SheetTrigger and SheetContent', async () => {
    const sheet = await import('../../../components/ui/sheet')
    expect(sheet.Sheet).toBeDefined()
    expect(sheet.SheetTrigger).toBeDefined()
    expect(sheet.SheetContent).toBeDefined()
  })

  it('should render Sheet components without errors', async () => {
    const { Sheet, SheetTrigger, SheetContent } = await import('../../../components/ui/sheet')
    expect(() =>
      render(
        <Sheet>
          <SheetTrigger>Oeffnen</SheetTrigger>
          <SheetContent side="right">Inhalt</SheetContent>
        </Sheet>
      )
    ).not.toThrow()
  })

  it('should apply side="right" slide-in class', async () => {
    const { Sheet, SheetContent } = await import('../../../components/ui/sheet')
    render(
      <Sheet open>
        <SheetContent side="right">Inhalt</SheetContent>
      </Sheet>
    )
    // Sheet uses Radix Portal — content renders to document.body, not container
    const content = document.querySelector('[data-slot="sheet-content"]')
    expect(content?.className).toContain('slide-in-from-right')
  })

  it('should apply side="left" slide-in class', async () => {
    const { Sheet, SheetContent } = await import('../../../components/ui/sheet')
    render(
      <Sheet open>
        <SheetContent side="left">Inhalt</SheetContent>
      </Sheet>
    )
    const content = document.querySelector('[data-slot="sheet-content"]')
    expect(content?.className).toContain('slide-in-from-left')
  })

  it('should apply side="top" slide-in class', async () => {
    const { Sheet, SheetContent } = await import('../../../components/ui/sheet')
    render(
      <Sheet open>
        <SheetContent side="top">Inhalt</SheetContent>
      </Sheet>
    )
    const content = document.querySelector('[data-slot="sheet-content"]')
    expect(content?.className).toContain('slide-in-from-top')
  })

  it('should apply side="bottom" slide-in class', async () => {
    const { Sheet, SheetContent } = await import('../../../components/ui/sheet')
    render(
      <Sheet open>
        <SheetContent side="bottom">Inhalt</SheetContent>
      </Sheet>
    )
    const content = document.querySelector('[data-slot="sheet-content"]')
    expect(content?.className).toContain('slide-in-from-bottom')
  })

  it('should have close button with aria-label="Panel schliessen"', async () => {
    const { Sheet, SheetContent } = await import('../../../components/ui/sheet')
    render(
      <Sheet open>
        <SheetContent>Inhalt</SheetContent>
      </Sheet>
    )
    const closeBtn = document.querySelector('[data-slot="sheet-close"]')
    expect(closeBtn).toHaveAttribute('aria-label', 'Panel schließen')
  })
})

// ─── AC-9: Font Setup — layout.tsx ──────────────────────────────────────────

describe('AC-9: layout.tsx bindet Inter Font korrekt ein', () => {
  const layoutPath = resolve(FRONTEND_ROOT, 'app/layout.tsx')

  it('should import headingFont and bodyFont from lib/theme/fonts', () => {
    const content = readFileSync(layoutPath, 'utf-8')
    expect(content).toContain("from '@/lib/theme/fonts'")
    expect(content).toContain('headingFont')
    expect(content).toContain('bodyFont')
  })

  it('should apply font variables to html element className', () => {
    const content = readFileSync(layoutPath, 'utf-8')
    // headingFont.variable and bodyFont.variable should be in template literal on <html>
    expect(content).toContain('headingFont.variable')
    expect(content).toContain('bodyFont.variable')
  })
})

// ─── AC-9 (cont): Font Setup — lib/theme/fonts.ts ──────────────────────────

describe('AC-9: Font Setup — lib/theme/fonts.ts', () => {
  const fontsPath = resolve(FRONTEND_ROOT, 'lib/theme/fonts.ts')

  it('should exist', () => {
    expect(existsSync(fontsPath)).toBe(true)
  })

  it('should contain Inter import from next/font/google', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain("from 'next/font/google'")
  })

  it('headingFont should use variable "--font-heading"', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain("variable: '--font-heading'")
  })

  it('bodyFont should use variable "--font-body"', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain("variable: '--font-body'")
  })

  it('should export headingFont', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain('export const headingFont')
  })

  it('should export bodyFont', () => {
    const content = readFileSync(fontsPath, 'utf-8')
    expect(content).toContain('export const bodyFont')
  })
})

// ─── AC-10: globals.css Import-Reihenfolge ──────────────────────────────────

describe('AC-10: globals.css — korrekte Import-Reihenfolge', () => {
  const cssPath = resolve(FRONTEND_ROOT, 'app/globals.css')

  it('should have correct import order: generated-theme.css -> tailwindcss -> tw-animate-css', () => {
    const content = readFileSync(cssPath, 'utf-8')
    const generatedIdx = content.indexOf('@import "./generated-theme.css"')
    const tailwindIdx = content.indexOf('@import "tailwindcss"')
    const twAnimateIdx = content.indexOf('@import "tw-animate-css"')

    expect(generatedIdx).toBeGreaterThanOrEqual(0)
    expect(tailwindIdx).toBeGreaterThanOrEqual(0)
    expect(twAnimateIdx).toBeGreaterThanOrEqual(0)

    // Order: generated-theme.css < tailwindcss < tw-animate-css
    expect(generatedIdx).toBeLessThan(tailwindIdx)
    expect(tailwindIdx).toBeLessThan(twAnimateIdx)
  })

  it('should have --font-heading and --font-body in @theme block', () => {
    const content = readFileSync(cssPath, 'utf-8')
    expect(content).toContain('--font-heading')
    expect(content).toContain('--font-body')
  })
})

// ─── AC-11: Token-Binding Tests — keine hardcoded Farben ────────────────────

describe('AC-11: Theme Token Binding — keine hardcoded Tailwind Farben', () => {
  const checkNoHardcodedColors = (className: string) => {
    expect(className).not.toMatch(/\b(red|green|blue|yellow|purple|pink|orange|gray|slate|zinc)-\d{3}\b/)
  }

  it('Button: kein hardcoded bg-red-* oder bg-green-*', async () => {
    const { Button } = await import('../../../components/ui/button')
    const variants = ['default', 'destructive', 'outline', 'ghost', 'link', 'secondary'] as const
    for (const variant of variants) {
      const { container } = render(<Button variant={variant}>T</Button>)
      const el = container.querySelector('[data-slot="button"]')
      checkNoHardcodedColors(el?.className ?? '')
    }
  })

  it('Input: kein hardcoded border-red-*', async () => {
    const { Input } = await import('../../../components/ui/input')
    const { container } = render(<Input variant="error" />)
    const el = container.querySelector('[data-slot="input"]')
    checkNoHardcodedColors(el?.className ?? '')
  })

  it('Badge: kein hardcoded bg-red-* oder bg-green-*', async () => {
    const { Badge } = await import('../../../components/ui/badge')
    const variants = ['default', 'secondary', 'destructive', 'outline'] as const
    for (const variant of variants) {
      const { container } = render(<Badge variant={variant}>T</Badge>)
      const el = container.querySelector('[data-slot="badge"]')
      checkNoHardcodedColors(el?.className ?? '')
    }
  })

  it('Button source file: no hardcoded Tailwind color classes', () => {
    const content = readFileSync(resolve(FRONTEND_ROOT, 'components/ui/button.tsx'), 'utf-8')
    expect(content).not.toMatch(/\b(bg|text|border)-(red|green|blue|yellow|purple|pink|orange|gray|slate|zinc)-\d{3}\b/)
  })

  it('Input source file: no hardcoded Tailwind color classes', () => {
    const content = readFileSync(resolve(FRONTEND_ROOT, 'components/ui/input.tsx'), 'utf-8')
    expect(content).not.toMatch(/\b(bg|text|border)-(red|green|blue|yellow|purple|pink|orange|gray|slate|zinc)-\d{3}\b/)
  })

  it('Card source file: no hardcoded Tailwind color classes', () => {
    const content = readFileSync(resolve(FRONTEND_ROOT, 'components/ui/card.tsx'), 'utf-8')
    expect(content).not.toMatch(/\b(bg|text|border)-(red|green|blue|yellow|purple|pink|orange|gray|slate|zinc)-\d{3}\b/)
  })

  it('Badge source file: no hardcoded Tailwind color classes', () => {
    const content = readFileSync(resolve(FRONTEND_ROOT, 'components/ui/badge.tsx'), 'utf-8')
    expect(content).not.toMatch(/\b(bg|text|border)-(red|green|blue|yellow|purple|pink|orange|gray|slate|zinc)-\d{3}\b/)
  })

  it('Skeleton source file: no hardcoded Tailwind color classes', () => {
    const content = readFileSync(resolve(FRONTEND_ROOT, 'components/ui/skeleton.tsx'), 'utf-8')
    expect(content).not.toMatch(/\b(bg|text|border)-(red|green|blue|yellow|purple|pink|orange|gray|slate|zinc)-\d{3}\b/)
  })
})

// ─── package.json Dependencies Tests ────────────────────────────────────────

describe('package.json — Neue Dependencies vorhanden', () => {
  const pkg = JSON.parse(
    readFileSync(resolve(FRONTEND_ROOT, 'package.json'), 'utf-8')
  )
  const allDeps = { ...pkg.dependencies, ...pkg.devDependencies }

  it('should have class-variance-authority', () => {
    expect(allDeps['class-variance-authority']).toBeDefined()
  })

  it('should have @radix-ui/react-dialog', () => {
    expect(allDeps['@radix-ui/react-dialog']).toBeDefined()
  })

  it('should have @radix-ui/react-slot', () => {
    expect(allDeps['@radix-ui/react-slot']).toBeDefined()
  })

  it('should have lucide-react', () => {
    expect(allDeps['lucide-react']).toBeDefined()
  })

  it('should have tw-animate-css', () => {
    expect(allDeps['tw-animate-css']).toBeDefined()
  })
})
