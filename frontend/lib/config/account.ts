// frontend/lib/config/account.ts
// Zentrale Konfiguration für WooCommerce Account-URLs
// Verwendet NEXT_PUBLIC_WP_URL aus .env.local (aus Slice 1 bekannt)

export function getAccountUrl(): string {
  const wpUrl = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'
  return `${wpUrl}/mein-konto`
}

export function getAccountOrdersUrl(): string {
  const wpUrl = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'
  return `${wpUrl}/mein-konto/orders`
}

export function getAccountAddressesUrl(): string {
  const wpUrl = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'
  return `${wpUrl}/mein-konto/edit-address`
}
