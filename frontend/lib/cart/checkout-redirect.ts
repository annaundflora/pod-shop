import { TokenManager } from '@/lib/apollo/token-manager'
import { getLastEventId } from '@/lib/tracking/event-id'

const CART_BACKUP_KEY = 'pod-cart-backup'
const WC_CHECKOUT_URL = process.env.NEXT_PUBLIC_WC_CHECKOUT_URL ?? 'http://localhost:8080/checkout'

/**
 * Leitet den Nutzer zum WooCommerce Checkout weiter.
 * Uebergibt den WooGraphQL Session Token und die Pinterest event_id als URL-Parameter.
 */
export function checkoutRedirect(): void {
  const token = TokenManager.getToken()
  const pinterestEventId = getLastEventId()

  const params = new URLSearchParams()
  if (token) params.set('session-token', token)
  if (pinterestEventId) params.set('pinterest_event_id', pinterestEventId)

  const queryString = params.toString()
  window.location.href = queryString
    ? `${WC_CHECKOUT_URL}?${queryString}`
    : WC_CHECKOUT_URL
}

/**
 * Prueft ob ein gespeicherter Cart-Backup vorhanden ist (localStorage).
 * Wird fuer Robustheit bei Session-Verlust verwendet.
 */
export function hasCartBackup(): boolean {
  try {
    const backup = localStorage.getItem(CART_BACKUP_KEY)
    if (!backup) return false
    const parsed = JSON.parse(backup)
    return Array.isArray(parsed) && parsed.length > 0
  } catch {
    return false
  }
}
