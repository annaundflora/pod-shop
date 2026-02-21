import type { ProductDetailData } from '../graphql/types'

/**
 * Generiert JSON-LD Product Schema für SEO und Pinterest Rich Pins.
 * Referenz: https://schema.org/Product
 */
export function generateProductJsonLd(product: ProductDetailData, pageUrl: string): string {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.name,
    description: product.shortDescription ?? product.description,
    image: product.image?.sourceUrl ?? '',
    url: pageUrl,
    offers: {
      '@type': 'Offer',
      price: product.price?.replace(/[^\d,]/g, '').replace(',', '.') ?? '0',
      priceCurrency: 'EUR',
      availability: 'https://schema.org/InStock',
      priceValidUntil: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000)
        .toISOString()
        .split('T')[0],
    },
  }

  return JSON.stringify(schema)
}
