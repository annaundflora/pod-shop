// Pinterest Tag (pintrk) Wrapper
// Consent-Gate: Kein Feuern ohne localStorage['cookie-consent'] === 'accepted'

declare global {
  interface Window {
    pintrk: ((...args: unknown[]) => void) & {
      queue?: unknown[]
      version?: string
    }
  }
}

const CONSENT_KEY = 'cookie-consent'
const PINTEREST_TAG_ID = process.env.NEXT_PUBLIC_PINTEREST_TAG_ID ?? ''

export function hasConsent(): boolean {
  if (typeof window === 'undefined') return false
  return localStorage.getItem(CONSENT_KEY) === 'accepted'
}

export function isTagLoaded(): boolean {
  return typeof window !== 'undefined' && typeof window.pintrk === 'function'
}

export function initPinterestTag(): void {
  if (!hasConsent()) return
  if (isTagLoaded()) return
  if (!PINTEREST_TAG_ID) return

  // Pinterest Tag initialisieren (entspricht dem offiziellen Pinterest Tag Snippet)
  window.pintrk = function (...args: unknown[]) {
    window.pintrk.queue = window.pintrk.queue || []
    window.pintrk.queue.push(args)
  }
  window.pintrk.version = '3.0'

  window.pintrk('load', PINTEREST_TAG_ID, { np: 'next' })
}

export interface PinterestPageVisitEvent {
  event_id: string
}

export interface PinterestViewCategoryEvent {
  event_id: string
  category_name: string
}

export interface PinterestAddToCartEvent {
  event_id: string
  value: number
  quantity: number
  order_quantity: number
  currency: 'EUR'
}

export function firePageVisit(event: PinterestPageVisitEvent): void {
  if (!hasConsent() || !isTagLoaded()) return
  window.pintrk('page', { event_id: event.event_id })
}

export function fireViewCategory(event: PinterestViewCategoryEvent): void {
  if (!hasConsent() || !isTagLoaded()) return
  window.pintrk('viewcategory', {
    event_id: event.event_id,
    category_name: event.category_name,
  })
}

export function fireAddToCart(event: PinterestAddToCartEvent): void {
  if (!hasConsent() || !isTagLoaded()) return
  window.pintrk('addtocart', {
    event_id: event.event_id,
    value: event.value,
    quantity: event.quantity,
    order_quantity: event.order_quantity,
    currency: event.currency,
  })
}
