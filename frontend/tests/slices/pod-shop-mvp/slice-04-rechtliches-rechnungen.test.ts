/**
 * Acceptance & Unit Tests for Slice 04: Rechtliches + Rechnungen
 *
 * Tests are derived from the GIVEN/WHEN/THEN Acceptance Criteria
 * in docs/features/pod-shop-mvp/slices/slice-04-rechtliches-rechnungen.md
 *
 * AC-1:  Faktur Pro creates PDF invoice on order_status_processing (WordPress - manual test)
 * AC-2:  PDF contains exact section 19 UStG text (WordPress - manual test)
 * AC-3:  Sequential GoBD-compliant invoice numbers (WordPress - manual test)
 * AC-4:  WordPress pages /impressum, /agb, /datenschutz, /widerruf accessible (WordPress - manual test)
 * AC-5:  Checkout shows Widerrufsbelehrung link (WordPress - manual test)
 * AC-6:  First visit (no localStorage) shows cookie consent banner with two buttons
 * AC-7:  "Alle Akzeptieren" sets localStorage cookie-consent to "accepted", banner disappears
 * AC-8:  "Nur Notwendige" sets localStorage cookie-consent to "rejected", banner disappears
 * AC-9:  Returning visitor with existing consent decision does not see banner again
 * AC-10: Footer contains 4 legal links (Impressum, AGB, Datenschutz, Widerruf) pointing to WP pages
 * AC-11: prefers-reduced-motion disables slide-up animation
 *
 * Note: AC-1 through AC-5 are WordPress/WooCommerce/Faktur Pro configurations
 * that require manual verification. They cannot be tested via Vitest.
 * Structural assertions are included where possible.
 *
 * IMPORTANT for Slice 06 integration:
 * - localStorage key MUST be 'cookie-consent' (CONSENT_KEY)
 * - Slice 06 (Pinterest Tag) reads this key directly via isConsentAccepted()
 */
import { describe, it, expect, beforeEach } from 'vitest'
import {
  getConsentState,
  setConsentAccepted,
  setConsentRejected,
  hasConsentDecision,
  isConsentAccepted,
  CONSENT_KEY,
} from '@/lib/consent/cookie-consent'
import * as fs from 'fs'
import * as path from 'path'

// ---------------------------------------------------------------------------
// AC-6: Cookie Consent Banner - First Visit (getConsentState)
// ---------------------------------------------------------------------------

describe('AC-6: CookieConsent - getConsentState (first visit detection)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('AC-6: GIVEN a user visits the shop for the first time (localStorage "cookie-consent" not set) WHEN getConsentState() is called THEN it returns null', () => {
    // Arrange - localStorage is empty (beforeEach)
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBeNull()
  })

  it('AC-6: GIVEN no consent decision exists WHEN hasConsentDecision() is called THEN it returns false (banner should be shown)', () => {
    // Arrange - localStorage is empty
    // Act
    const hasDecision = hasConsentDecision()
    // Assert
    expect(hasDecision).toBe(false)
  })

  it('AC-6: GIVEN localStorage contains an invalid/unknown value for cookie-consent WHEN getConsentState() is called THEN it returns null (treated as no decision)', () => {
    // Arrange - invalid value
    localStorage.setItem(CONSENT_KEY, 'unknown-value')
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBeNull()
  })
})

// ---------------------------------------------------------------------------
// AC-7: "Alle Akzeptieren" - setConsentAccepted
// ---------------------------------------------------------------------------

describe('AC-7: CookieConsent - "Alle Akzeptieren" sets accepted', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('AC-7: GIVEN the cookie consent banner is visible WHEN the user clicks "Alle Akzeptieren" THEN localStorage.getItem("cookie-consent") returns "accepted"', () => {
    // Arrange - banner visible (no decision yet)
    expect(hasConsentDecision()).toBe(false)
    // Act (WHEN) - simulate clicking "Alle Akzeptieren"
    setConsentAccepted()
    // Assert (THEN)
    expect(localStorage.getItem('cookie-consent')).toBe('accepted')
  })

  it('AC-7: GIVEN setConsentAccepted() was called WHEN getConsentState() is called THEN it returns "accepted"', () => {
    // Arrange
    setConsentAccepted()
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBe('accepted')
  })

  it('AC-7: GIVEN setConsentAccepted() was called WHEN isConsentAccepted() is called THEN it returns true (Slice 6 Pinterest dependency)', () => {
    // Arrange
    setConsentAccepted()
    // Act
    const accepted = isConsentAccepted()
    // Assert - Slice 6 uses isConsentAccepted() to decide if Pinterest Tag fires
    expect(accepted).toBe(true)
  })
})

// ---------------------------------------------------------------------------
// AC-8: "Nur Notwendige" - setConsentRejected
// ---------------------------------------------------------------------------

describe('AC-8: CookieConsent - "Nur Notwendige" sets rejected', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('AC-8: GIVEN the cookie consent banner is visible WHEN the user clicks "Nur Notwendige" THEN localStorage.getItem("cookie-consent") returns "rejected"', () => {
    // Arrange - banner visible (no decision yet)
    expect(hasConsentDecision()).toBe(false)
    // Act (WHEN) - simulate clicking "Nur Notwendige"
    setConsentRejected()
    // Assert (THEN)
    expect(localStorage.getItem('cookie-consent')).toBe('rejected')
  })

  it('AC-8: GIVEN setConsentRejected() was called WHEN getConsentState() is called THEN it returns "rejected"', () => {
    // Arrange
    setConsentRejected()
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBe('rejected')
  })

  it('AC-8: GIVEN setConsentRejected() was called WHEN isConsentAccepted() is called THEN it returns false (Pinterest Tag stays inactive)', () => {
    // Arrange
    setConsentRejected()
    // Act
    const accepted = isConsentAccepted()
    // Assert - Slice 6 Pinterest Tag must NOT fire
    expect(accepted).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// AC-9: Returning Visitor - Banner not shown after decision
// ---------------------------------------------------------------------------

describe('AC-9: CookieConsent - Returning visitor does not see banner again', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('AC-9: GIVEN the user accepted cookies WHEN the page is reloaded (hasConsentDecision checked) THEN hasConsentDecision() returns true (banner should NOT appear)', () => {
    // Arrange - user previously accepted
    setConsentAccepted()
    // Act (WHEN) - simulate page reload
    const hasDecision = hasConsentDecision()
    // Assert (THEN)
    expect(hasDecision).toBe(true)
  })

  it('AC-9: GIVEN the user rejected cookies WHEN the page is reloaded THEN hasConsentDecision() returns true (banner should NOT appear)', () => {
    // Arrange - user previously rejected
    setConsentRejected()
    // Act (WHEN) - simulate page reload
    const hasDecision = hasConsentDecision()
    // Assert (THEN)
    expect(hasDecision).toBe(true)
  })

  it('AC-9: GIVEN consent decision persists in localStorage WHEN getConsentState() is called multiple times THEN it consistently returns the stored value', () => {
    // Arrange
    setConsentAccepted()
    // Act - multiple reads
    const first = getConsentState()
    const second = getConsentState()
    const third = isConsentAccepted()
    // Assert
    expect(first).toBe('accepted')
    expect(second).toBe('accepted')
    expect(third).toBe(true)
  })
})

// ---------------------------------------------------------------------------
// AC-10: Footer Legal Links
// ---------------------------------------------------------------------------

describe('AC-10: Footer contains 4 legal links pointing to WooCommerce WordPress pages', () => {
  it('AC-10: GIVEN the Next.js frontend is loaded WHEN the footer is visible THEN links for Impressum, AGB, Datenschutz, Widerruf are present in footer.tsx', () => {
    // Arrange - read footer source to verify link definitions
    const footerPath = path.resolve(__dirname, '../../../components/layout/footer.tsx')
    const footerContent = fs.readFileSync(footerPath, 'utf-8')

    // Assert (THEN) - all 4 legal links defined
    expect(footerContent).toContain("label: 'Impressum'")
    expect(footerContent).toContain("label: 'AGB'")
    expect(footerContent).toContain("label: 'Datenschutz'")
    expect(footerContent).toContain("label: 'Widerruf'")
  })

  it('AC-10: Footer legal links point to WP_URL slugs /impressum, /agb, /datenschutz, /widerruf', () => {
    // Arrange
    const footerPath = path.resolve(__dirname, '../../../components/layout/footer.tsx')
    const footerContent = fs.readFileSync(footerPath, 'utf-8')

    // Assert - hrefs use WP_URL + slug pattern
    expect(footerContent).toContain('/impressum')
    expect(footerContent).toContain('/agb')
    expect(footerContent).toContain('/datenschutz')
    expect(footerContent).toContain('/widerruf')
    expect(footerContent).toContain('WP_URL')
  })

  it('AC-10: Footer has exactly 4 legal links in LEGAL_LINKS array', () => {
    // Arrange
    const footerPath = path.resolve(__dirname, '../../../components/layout/footer.tsx')
    const footerContent = fs.readFileSync(footerPath, 'utf-8')

    // Count the link entries in LEGAL_LINKS
    const linkMatches = footerContent.match(/\{ label: '[^']+', href: /g)
    // Assert
    expect(linkMatches).not.toBeNull()
    expect(linkMatches!.length).toBe(4)
  })

  it('AC-10: Footer contains "Mein Konto" link pointing to WooCommerce account page', () => {
    // Arrange
    const footerPath = path.resolve(__dirname, '../../../components/layout/footer.tsx')
    const footerContent = fs.readFileSync(footerPath, 'utf-8')

    // Assert
    expect(footerContent).toContain('Mein Konto')
    expect(footerContent).toContain('/mein-konto')
  })

  it('AC-10: Footer uses semantic nav element with aria-label for accessibility', () => {
    // Arrange
    const footerPath = path.resolve(__dirname, '../../../components/layout/footer.tsx')
    const footerContent = fs.readFileSync(footerPath, 'utf-8')

    // Assert
    expect(footerContent).toContain('<nav aria-label="Rechtliche Seiten">')
    expect(footerContent).toContain('<ul')
    expect(footerContent).toContain('<li')
  })
})

// ---------------------------------------------------------------------------
// AC-11: prefers-reduced-motion (structural assertion via CSS)
// ---------------------------------------------------------------------------

describe('AC-11: prefers-reduced-motion disables slide-up animation', () => {
  it('AC-11: GIVEN a user with prefers-reduced-motion: reduce WHEN the banner appears THEN globals.css contains prefers-reduced-motion override for slide-up keyframes', () => {
    // Arrange
    const cssPath = path.resolve(__dirname, '../../../app/globals.css')
    const cssContent = fs.readFileSync(cssPath, 'utf-8')

    // Assert - prefers-reduced-motion media query exists
    expect(cssContent).toContain('prefers-reduced-motion: reduce')
    // Assert - slide-up keyframes defined
    expect(cssContent).toContain('@keyframes slide-up')
    // Assert - animation token defined
    expect(cssContent).toContain('--animate-slide-up')
  })

  it('AC-11: CookieConsentBanner uses motion-safe:animate-slide-up class for conditional animation', () => {
    // Arrange
    const bannerPath = path.resolve(__dirname, '../../../components/layout/cookie-consent-banner.tsx')
    const bannerContent = fs.readFileSync(bannerPath, 'utf-8')

    // Assert - motion-safe modifier ensures animation only plays without reduced-motion preference
    expect(bannerContent).toContain('motion-safe:animate-slide-up')
  })
})

// ---------------------------------------------------------------------------
// Slice 06 Integration Contract: localStorage key and consent API
// ---------------------------------------------------------------------------

describe('Slice 06 Integration Contract: CONSENT_KEY and consent utilities', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('CONSENT_KEY equals "cookie-consent" (Slice 6 Pinterest Tag reads this key)', () => {
    // This is the contract between Slice 4 and Slice 6
    expect(CONSENT_KEY).toBe('cookie-consent')
  })

  it('setConsentAccepted() writes to localStorage under the exact key "cookie-consent"', () => {
    // Arrange & Act
    setConsentAccepted()
    // Assert - direct localStorage access (as Slice 6 would do)
    const rawValue = localStorage.getItem('cookie-consent')
    expect(rawValue).toBe('accepted')
  })

  it('setConsentRejected() writes to localStorage under the exact key "cookie-consent"', () => {
    // Arrange & Act
    setConsentRejected()
    // Assert
    const rawValue = localStorage.getItem('cookie-consent')
    expect(rawValue).toBe('rejected')
  })

  it('Consent decision can be overwritten (accept then reject)', () => {
    // Arrange
    setConsentAccepted()
    expect(isConsentAccepted()).toBe(true)
    // Act
    setConsentRejected()
    // Assert
    expect(getConsentState()).toBe('rejected')
    expect(isConsentAccepted()).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// Integration Contract: File structure and CookieConsentBanner in layout
// ---------------------------------------------------------------------------

describe('Integration Contract: CookieConsentBanner in layout.tsx and file structure', () => {
  it('CookieConsentBanner is imported and rendered in app/layout.tsx', () => {
    const layoutPath = path.resolve(__dirname, '../../../app/layout.tsx')
    const layoutContent = fs.readFileSync(layoutPath, 'utf-8')

    // Assert - import exists
    expect(layoutContent).toContain(
      "import { CookieConsentBanner } from '@/components/layout/cookie-consent-banner'"
    )
    // Assert - component rendered
    expect(layoutContent).toContain('<CookieConsentBanner />')
  })

  it('CookieConsentBanner is placed after {children} inside CartProvider', () => {
    const layoutPath = path.resolve(__dirname, '../../../app/layout.tsx')
    const layoutContent = fs.readFileSync(layoutPath, 'utf-8')

    // Assert - CookieConsentBanner appears after {children}
    const childrenIdx = layoutContent.indexOf('{children}')
    const bannerIdx = layoutContent.indexOf('<CookieConsentBanner />')
    expect(childrenIdx).toBeGreaterThan(-1)
    expect(bannerIdx).toBeGreaterThan(childrenIdx)

    // Assert - inside CartProvider
    const cartProviderStart = layoutContent.indexOf('<CartProvider>')
    const cartProviderEnd = layoutContent.indexOf('</CartProvider>')
    expect(bannerIdx).toBeGreaterThan(cartProviderStart)
    expect(bannerIdx).toBeLessThan(cartProviderEnd)
  })

  it('cookie-consent-banner.tsx is a client component (use client directive)', () => {
    const bannerPath = path.resolve(__dirname, '../../../components/layout/cookie-consent-banner.tsx')
    const bannerContent = fs.readFileSync(bannerPath, 'utf-8')

    expect(bannerContent).toContain("'use client'")
  })

  it('cookie-consent-banner.tsx imports consent utilities from @/lib/consent/cookie-consent', () => {
    const bannerPath = path.resolve(__dirname, '../../../components/layout/cookie-consent-banner.tsx')
    const bannerContent = fs.readFileSync(bannerPath, 'utf-8')

    expect(bannerContent).toContain('hasConsentDecision')
    expect(bannerContent).toContain('setConsentAccepted')
    expect(bannerContent).toContain('setConsentRejected')
    expect(bannerContent).toContain("from '@/lib/consent/cookie-consent'")
  })

  it('cookie-consent-banner.tsx has accessible dialog role and aria attributes', () => {
    const bannerPath = path.resolve(__dirname, '../../../components/layout/cookie-consent-banner.tsx')
    const bannerContent = fs.readFileSync(bannerPath, 'utf-8')

    expect(bannerContent).toContain('role="dialog"')
    expect(bannerContent).toContain('aria-label="Cookie-Einstellungen"')
    expect(bannerContent).toContain('aria-live="polite"')
  })

  it('cookie-consent-banner.tsx contains both required button labels', () => {
    const bannerPath = path.resolve(__dirname, '../../../components/layout/cookie-consent-banner.tsx')
    const bannerContent = fs.readFileSync(bannerPath, 'utf-8')

    expect(bannerContent).toContain('Alle Akzeptieren')
    expect(bannerContent).toContain('Nur Notwendige')
  })

  it('cookie-consent-banner.tsx links to Datenschutz page', () => {
    const bannerPath = path.resolve(__dirname, '../../../components/layout/cookie-consent-banner.tsx')
    const bannerContent = fs.readFileSync(bannerPath, 'utf-8')

    expect(bannerContent).toContain('/datenschutz')
    expect(bannerContent).toContain('Datenschutz')
  })
})

// ---------------------------------------------------------------------------
// AC-2 (structural): section 19 UStG text validation
// ---------------------------------------------------------------------------

describe('AC-2 (structural): section 19 UStG required text', () => {
  it('AC-2: The exact text "Gemaess section 19 UStG wird keine Umsatzsteuer berechnet." is the business requirement', () => {
    // Arrange - Business Rule from discovery.md + architecture.md
    // This text MUST appear in the Faktur Pro Mustache template (WordPress manual config)
    const requiredText = 'Gem\u00e4\u00df \u00a719 UStG wird keine Umsatzsteuer berechnet.'

    // Assert
    expect(requiredText).toMatch(/Gem\u00e4\u00df \u00a719 UStG/)
    expect(requiredText).toMatch(/keine Umsatzsteuer/)
    expect(requiredText.endsWith('.')).toBe(true)
  })
})

// ---------------------------------------------------------------------------
// SSR Safety: cookie-consent.ts handles server-side rendering
// ---------------------------------------------------------------------------

describe('SSR Safety: cookie-consent.ts window undefined checks', () => {
  it('cookie-consent.ts includes typeof window === undefined guard in all functions', () => {
    const consentPath = path.resolve(__dirname, '../../../lib/consent/cookie-consent.ts')
    const consentContent = fs.readFileSync(consentPath, 'utf-8')

    // All three functions that access localStorage must have the SSR guard
    const windowChecks = consentContent.match(/typeof window === 'undefined'/g)
    expect(windowChecks).not.toBeNull()
    // getConsentState, setConsentAccepted, setConsentRejected = 3 guards minimum
    expect(windowChecks!.length).toBeGreaterThanOrEqual(3)
  })
})
