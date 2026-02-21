/**
 * Acceptance & Unit Tests for Slice 07: User-Accounts einrichten
 *
 * Tests are derived from the GIVEN/WHEN/THEN Acceptance Criteria
 * in docs/features/pod-shop-mvp/slices/slice-07-user-accounts.md
 *
 * AC-1: WooCommerce "Mein Konto" page shows login form (WordPress - manual test)
 * AC-2: User registration on WooCommerce creates account (WordPress - manual test)
 * AC-3: User login redirects to account dashboard (WordPress - manual test)
 * AC-4: Order history visible after test purchase (WordPress - manual test)
 * AC-5: Browser navigation from Next.js to WooCommerce (manual test)
 * AC-6: Mobile Menu "Mein Konto" link href points to {NEXT_PUBLIC_WP_URL}/mein-konto
 * AC-7: Footer "Mein Konto" link href points to {NEXT_PUBLIC_WP_URL}/mein-konto
 *
 * Note: AC-1 through AC-5 are WooCommerce configurations / browser navigation
 * that require manual verification. They cannot be tested via Vitest.
 * AC-6 and AC-7 are validated through getAccountUrl() unit tests below.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

describe('Slice 07: User-Accounts – getAccountUrl()', () => {
  const originalEnv = process.env

  beforeEach(() => {
    vi.resetModules()
    process.env = { ...originalEnv }
  })

  afterEach(() => {
    process.env = originalEnv
  })

  it('should return WooCommerce account URL with NEXT_PUBLIC_WP_URL env variable', async () => {
    // Arrange
    process.env.NEXT_PUBLIC_WP_URL = 'http://localhost:8080'
    const { getAccountUrl } = await import('../../../lib/config/account')
    // Act
    const url = getAccountUrl()
    // Assert
    expect(url).toBe('http://localhost:8080/mein-konto')
  })

  it('should fall back to localhost:8080 when NEXT_PUBLIC_WP_URL is not set', async () => {
    // Arrange
    delete process.env.NEXT_PUBLIC_WP_URL
    const { getAccountUrl } = await import('../../../lib/config/account')
    // Act
    const url = getAccountUrl()
    // Assert
    expect(url).toBe('http://localhost:8080/mein-konto')
  })

  it('should return orders URL pointing to WooCommerce orders page', async () => {
    // Arrange
    process.env.NEXT_PUBLIC_WP_URL = 'http://localhost:8080'
    const { getAccountOrdersUrl } = await import('../../../lib/config/account')
    // Act
    const url = getAccountOrdersUrl()
    // Assert
    expect(url).toBe('http://localhost:8080/mein-konto/orders')
  })

  it('should return addresses URL pointing to WooCommerce address page', async () => {
    // Arrange
    process.env.NEXT_PUBLIC_WP_URL = 'http://localhost:8080'
    const { getAccountAddressesUrl } = await import('../../../lib/config/account')
    // Act
    const url = getAccountAddressesUrl()
    // Assert
    expect(url).toBe('http://localhost:8080/mein-konto/edit-address')
  })

  it('should use production URL when NEXT_PUBLIC_WP_URL is set to production domain', async () => {
    // Arrange
    process.env.NEXT_PUBLIC_WP_URL = 'https://meinshop.de'
    const { getAccountUrl } = await import('../../../lib/config/account')
    // Act
    const url = getAccountUrl()
    // Assert
    expect(url).toBe('https://meinshop.de/mein-konto')
  })

  it('should not contain trailing slash duplication in URL', async () => {
    // Arrange – WP_URL ohne trailing slash
    process.env.NEXT_PUBLIC_WP_URL = 'http://localhost:8080'
    const { getAccountUrl } = await import('../../../lib/config/account')
    // Act
    const url = getAccountUrl()
    // Assert
    expect(url).not.toContain('//mein-konto')
    expect(url).toContain('/mein-konto')
  })
})
