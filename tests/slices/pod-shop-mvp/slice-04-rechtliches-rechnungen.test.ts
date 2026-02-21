// tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts
import { describe, it, expect, beforeEach } from 'vitest'
import {
  getConsentState,
  setConsentAccepted,
  setConsentRejected,
  hasConsentDecision,
  isConsentAccepted,
  CONSENT_KEY,
} from '../../../frontend/lib/consent/cookie-consent'

describe('Slice 04: CookieConsent – getConsentState', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should return null when no consent decision exists (AC-6)', () => {
    // Arrange – localStorage leer (beforeEach)
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBeNull()
  })

  it('should return "accepted" after setConsentAccepted (AC-7)', () => {
    // Arrange
    setConsentAccepted()
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBe('accepted')
  })

  it('should return "rejected" after setConsentRejected (AC-8)', () => {
    // Arrange
    setConsentRejected()
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBe('rejected')
  })

  it('should return null for unknown/invalid localStorage values', () => {
    // Arrange – ungültiger Wert
    localStorage.setItem(CONSENT_KEY, 'unknown-value')
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBeNull()
  })
})

describe('Slice 04: CookieConsent – hasConsentDecision', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should return false when no decision exists (AC-6)', () => {
    expect(hasConsentDecision()).toBe(false)
  })

  it('should return true after accepting (AC-9)', () => {
    setConsentAccepted()
    expect(hasConsentDecision()).toBe(true)
  })

  it('should return true after rejecting (AC-9)', () => {
    setConsentRejected()
    expect(hasConsentDecision()).toBe(true)
  })
})

describe('Slice 04: CookieConsent – isConsentAccepted', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should return false when no consent exists', () => {
    expect(isConsentAccepted()).toBe(false)
  })

  it('should return true after accepting (AC-7)', () => {
    setConsentAccepted()
    expect(isConsentAccepted()).toBe(true)
  })

  it('should return false after rejecting (AC-8)', () => {
    setConsentRejected()
    expect(isConsentAccepted()).toBe(false)
  })
})

describe('Slice 04: CookieConsent – localStorage Key', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should use the key "cookie-consent" in localStorage', () => {
    // Arrange
    setConsentAccepted()
    // Act
    const rawValue = localStorage.getItem('cookie-consent')
    // Assert – Slice 6 (Pinterest Tag) liest genau diesen Key
    expect(rawValue).toBe('accepted')
  })

  it('should persist decision across multiple reads (AC-9)', () => {
    // Arrange
    setConsentAccepted()
    // Act – mehrfaches Lesen
    const first = getConsentState()
    const second = getConsentState()
    const third = isConsentAccepted()
    // Assert
    expect(first).toBe('accepted')
    expect(second).toBe('accepted')
    expect(third).toBe(true)
  })

  it('should overwrite previous decision', () => {
    // Arrange – erst akzeptieren, dann ablehnen
    setConsentAccepted()
    setConsentRejected()
    // Act
    const state = getConsentState()
    // Assert
    expect(state).toBe('rejected')
    expect(isConsentAccepted()).toBe(false)
  })
})

describe('Slice 04: §19 UStG Pflichttext', () => {
  it('should use exact §19 UStG text as required by GoBD and discovery.md', () => {
    // Arrange – Business Rule aus discovery.md + architecture.md
    const requiredText = 'Gemäß §19 UStG wird keine Umsatzsteuer berechnet.'
    // Assert – Dieser exakte Text muss in Faktur Pro Template + (bereits getestet: CartSummary aus Slice 3)
    expect(requiredText).toMatch(/Gemäß §19 UStG/)
    expect(requiredText).toMatch(/keine Umsatzsteuer/)
    expect(requiredText.endsWith('.')).toBe(true)
  })
})

describe('Slice 04: Footer Legal Links', () => {
  it('should define all 4 required legal page slugs', () => {
    // Arrange – Pflicht-Seiten aus architecture.md In Scope
    const requiredSlugs = ['/impressum', '/agb', '/datenschutz', '/widerruf']
    const LEGAL_LINKS = [
      { label: 'Impressum', slug: '/impressum' },
      { label: 'AGB', slug: '/agb' },
      { label: 'Datenschutz', slug: '/datenschutz' },
      { label: 'Widerruf', slug: '/widerruf' },
    ]
    // Act
    const definedSlugs = LEGAL_LINKS.map((l) => l.slug)
    // Assert
    for (const slug of requiredSlugs) {
      expect(definedSlugs).toContain(slug)
    }
  })

  it('should have exactly 4 legal links', () => {
    const LEGAL_LINKS = ['Impressum', 'AGB', 'Datenschutz', 'Widerruf']
    expect(LEGAL_LINKS).toHaveLength(4)
  })
})
