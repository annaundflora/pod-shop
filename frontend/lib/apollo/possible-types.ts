// Auto-generiert aus WooGraphQL Schema Introspection
// Notwendig damit Apollo InMemoryCache Fragment-Typ-Bedingungen auf abstrakten Typen korrekt auflösen kann
// Ohne diese Config: fragment "on Product" matcht nicht für VariableProduct → id/slug = undefined
export const possibleTypes = {
  Product: ['SimpleProductVariation', 'SimpleProduct', 'VariableProduct', 'ExternalProduct', 'GroupProduct'],
  ProductUnion: ['SimpleProductVariation', 'SimpleProduct', 'VariableProduct', 'ExternalProduct', 'GroupProduct'],
  ProductVariation: ['SimpleProductVariation'],
  CartItem: ['SimpleCartItem'],
  CartError: ['CartItemError'],
}
