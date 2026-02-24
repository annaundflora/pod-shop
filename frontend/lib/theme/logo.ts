// frontend/lib/theme/logo.ts
import { existsSync } from 'fs'
import { resolve } from 'path'

/**
 * Returns the public URL path to the shop logo SVG.
 * Falls back to the default theme logo if the shop-specific logo doesn't exist.
 *
 * IMPORTANT: Assets must be placed in frontend/public/themes/{shop}/assets/
 * Next.js serves files from public/ only.
 *
 * @param theme - Theme name from NEXT_PUBLIC_THEME (e.g. "zweiter-shop" or "default")
 * @returns Public URL path (e.g. "/themes/zweiter-shop/assets/logo.svg")
 */
export function getLogoPath(theme: string = 'default'): string {
  const shopLogoPublic = resolve(process.cwd(), 'public', 'themes', theme, 'assets', 'logo.svg')
  const defaultLogoPublic = resolve(process.cwd(), 'public', 'themes', 'default', 'assets', 'logo.svg')

  if (theme !== 'default' && existsSync(shopLogoPublic)) {
    return `/themes/${theme}/assets/logo.svg`
  }

  if (existsSync(defaultLogoPublic)) {
    return `/themes/default/assets/logo.svg`
  }

  // No logo found — return empty string (caller must handle gracefully)
  return ''
}

/**
 * Returns the public URL path to the shop favicon.
 * Falls back to the default theme favicon if the shop-specific one doesn't exist.
 *
 * @param theme - Theme name from NEXT_PUBLIC_THEME
 * @returns Public URL path (e.g. "/themes/zweiter-shop/assets/favicon.ico")
 */
export function getFaviconPath(theme: string = 'default'): string {
  const shopFaviconPublic = resolve(process.cwd(), 'public', 'themes', theme, 'assets', 'favicon.ico')
  const defaultFaviconPublic = resolve(process.cwd(), 'public', 'themes', 'default', 'assets', 'favicon.ico')

  if (theme !== 'default' && existsSync(shopFaviconPublic)) {
    return `/themes/${theme}/assets/favicon.ico`
  }

  if (existsSync(defaultFaviconPublic)) {
    return `/themes/default/assets/favicon.ico`
  }

  return ''
}
