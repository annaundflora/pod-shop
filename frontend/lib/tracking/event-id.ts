// UUID v4 Generator für Pinterest event_id Deduplizierung
// Verwendet crypto.randomUUID() (modern) mit Fallback auf Math.random()

export function generateEventId(): string {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID()
  }
  // Fallback für ältere Browser (unwahrscheinlich, aber sicher)
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    const v = c === 'x' ? r : (r & 0x3) | 0x8
    return v.toString(16)
  })
}

const LAST_EVENT_ID_KEY = 'pinterest-last-event-id'

export function storeLastEventId(eventId: string): void {
  if (typeof window === 'undefined') return
  localStorage.setItem(LAST_EVENT_ID_KEY, eventId)
}

export function getLastEventId(): string | null {
  if (typeof window === 'undefined') return null
  return localStorage.getItem(LAST_EVENT_ID_KEY)
}
