export function formatPrice(price: string | null | undefined): string {
  if (!price) return ''
  return price.replace(/&nbsp;/g, ' ')
}
