'use client'

import { useCallback } from 'react'
import {
  hasConsent,
  isTagLoaded,
  firePageVisit,
  fireViewCategory,
  fireAddToCart,
} from '@/lib/tracking/pinterest-tag'
import { generateEventId, storeLastEventId } from '@/lib/tracking/event-id'

export function usePinterestTag() {
  const trackPageVisit = useCallback(() => {
    if (!hasConsent() || !isTagLoaded()) return
    const eventId = generateEventId()
    storeLastEventId(eventId)
    firePageVisit({ event_id: eventId })
  }, [])

  const trackViewCategory = useCallback((categoryName: string) => {
    if (!hasConsent() || !isTagLoaded()) return
    const eventId = generateEventId()
    storeLastEventId(eventId)
    fireViewCategory({ event_id: eventId, category_name: categoryName })
  }, [])

  const trackAddToCart = useCallback(
    (params: { value: number; quantity: number }) => {
      if (!hasConsent() || !isTagLoaded()) return
      const eventId = generateEventId()
      storeLastEventId(eventId)
      fireAddToCart({
        event_id: eventId,
        value: params.value,
        quantity: params.quantity,
        order_quantity: params.quantity,
        currency: 'EUR',
      })
      return eventId
    },
    []
  )

  return { trackPageVisit, trackViewCategory, trackAddToCart }
}
