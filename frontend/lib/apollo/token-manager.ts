// WooGraphQL Session Token Manager
// Speichert woocommerce-session Token in localStorage
// Wird als Header bei jeder GraphQL-Anfrage mitgesendet

const TOKEN_KEY = 'woo-session-token'

export const TokenManager = {
  getToken(): string | null {
    if (typeof window === 'undefined') return null
    return localStorage.getItem(TOKEN_KEY)
  },

  setToken(token: string): void {
    if (typeof window === 'undefined') return
    localStorage.setItem(TOKEN_KEY, token)
  },

  clearToken(): void {
    if (typeof window === 'undefined') return
    localStorage.removeItem(TOKEN_KEY)
  },

  hasToken(): boolean {
    return TokenManager.getToken() !== null
  },
}
