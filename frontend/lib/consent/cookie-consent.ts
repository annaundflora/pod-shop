// Cookie Consent State Utilities
// localStorage Key: "cookie-consent"
// Werte: "accepted" | "rejected" | null (nicht gesetzt = erster Besuch)

export const CONSENT_KEY = 'cookie-consent' as const

export type ConsentState = 'accepted' | 'rejected' | null

export function getConsentState(): ConsentState {
  if (typeof window === 'undefined') return null
  const value = localStorage.getItem(CONSENT_KEY)
  if (value === 'accepted' || value === 'rejected') return value
  return null
}

export function setConsentAccepted(): void {
  if (typeof window === 'undefined') return
  localStorage.setItem(CONSENT_KEY, 'accepted')
}

export function setConsentRejected(): void {
  if (typeof window === 'undefined') return
  localStorage.setItem(CONSENT_KEY, 'rejected')
}

export function hasConsentDecision(): boolean {
  return getConsentState() !== null
}

export function isConsentAccepted(): boolean {
  return getConsentState() === 'accepted'
}
