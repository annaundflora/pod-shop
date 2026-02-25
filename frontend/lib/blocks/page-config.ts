// frontend/lib/blocks/page-config.ts
import { readFileSync, existsSync } from 'fs'
import { resolve } from 'path'
import { parse } from 'yaml'
import type { PageConfig, SectionConfig, BlockConfig, LoaderParams } from './types'

const FRONTEND_ROOT = resolve(process.cwd())

export interface RouteContext {
  slug?: string
  [key: string]: string | undefined
}

/**
 * Ersetzt $route.{key} Platzhalter in YAML-Params mit URL-Werten.
 * Unbekannte Platzhalter werden zu leerem String aufgeloest.
 */
export function resolveParams(
  params: Record<string, unknown>,
  routeContext: RouteContext
): Record<string, unknown> {
  const resolved: Record<string, unknown> = {}

  for (const [key, value] of Object.entries(params)) {
    if (typeof value === 'string' && value.startsWith('$route.')) {
      const routeKey = value.slice('$route.'.length)  // "slug" aus "$route.slug"
      const resolved_value = routeContext[routeKey]
      if (resolved_value === undefined) {
        console.warn(`resolveParams: unknown route key "${routeKey}" in param "${key}"`)
        resolved[key] = ''
      } else {
        resolved[key] = resolved_value
      }
    } else if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
      // Rekursiv fuer verschachtelte Objekte
      resolved[key] = resolveParams(value as Record<string, unknown>, routeContext)
    } else {
      resolved[key] = value
    }
  }

  return resolved
}

/**
 * Laedt YAML-Seitenkonfiguration mit 3-tier Template-Override:
 * 1. themes/{theme}/pages/{pageType}/{slug}.yaml   (slug-spezifisch)
 * 2. themes/{theme}/pages/{pageType}.yaml          (theme-generisch)
 * 3. themes/default/pages/{pageType}.yaml          (Fallback)
 *
 * WICHTIG: Nur fuer RSC (Node.js) geeignet, nicht Edge-Runtime.
 */
export function loadPageConfig(
  pageType: string,
  theme: string = 'default',
  routeParams?: RouteContext
): PageConfig {
  const slug = routeParams?.slug

  // 3-tier Lookup (exakt gemaess architecture.md "Template Override Resolution")
  // Tier 1: themes/{theme}/pages/{pageType}/{slug}.yaml  (slug-spezifisch, theme)
  // Tier 2: themes/{theme}/pages/{pageType}.yaml         (theme-generisch)
  // Tier 3: themes/default/pages/{pageType}.yaml         (Fallback, immer letzter Kandidat)
  //
  // WICHTIG: Genau 3 Kandidaten moeglich (je nach theme und slug). Kein weiterer Tier.
  // Tier 3 ist immer vorhanden. Tier 1+2 nur wenn theme !== 'default'.
  const candidates: string[] = []

  // Tier 1: slug-spezifisch (themes/{theme}/pages/{pageType}/{slug}.yaml)
  if (slug) {
    candidates.push(resolve(FRONTEND_ROOT, 'themes', theme, 'pages', pageType, `${slug}.yaml`))
  }
  // Tier 2: theme-generisch (themes/{theme}/pages/{pageType}.yaml)
  // Nur hinzufuegen wenn theme nicht schon 'default' ist (vermeidet Duplikat mit Tier 3)
  if (theme !== 'default') {
    candidates.push(resolve(FRONTEND_ROOT, 'themes', theme, 'pages', `${pageType}.yaml`))
  }
  // Tier 3: default-Fallback (themes/default/pages/{pageType}.yaml) — immer letzter Kandidat
  candidates.push(resolve(FRONTEND_ROOT, 'themes', 'default', 'pages', `${pageType}.yaml`))

  let configPath: string | undefined
  for (const candidate of candidates) {
    if (existsSync(candidate)) {
      configPath = candidate
      break
    }
  }

  if (!configPath) {
    throw new Error(`Page config not found for pageType "${pageType}", theme "${theme}", slug "${slug}"`)
  }

  const content = readFileSync(configPath, 'utf-8')
  const raw = parse(content) as { sections?: SectionConfig[]; blocks?: BlockConfig[] }

  // Backwards-Compat: Altes flat blocks[] Format in sections[] konvertieren
  if (raw.blocks && !raw.sections) {
    console.warn(`Page config "${configPath}" uses deprecated flat blocks[] format. Migrate to sections[].`)
    return { sections: [{ columns: 1, blocks: raw.blocks }] }
  }

  const config = raw as PageConfig

  if (!config.sections || !Array.isArray(config.sections) || config.sections.length === 0) {
    throw new Error(`Page config must have at least one section (pageType: ${pageType})`)
  }

  // resolveParams fuer alle Block-Params anwenden
  if (routeParams) {
    for (const section of config.sections) {
      section.blocks = section.blocks.map(block => ({
        ...block,
        params: resolveParams(block.params as unknown as Record<string, unknown>, routeParams) as unknown as LoaderParams,
      }))
    }
  }

  return config
}
