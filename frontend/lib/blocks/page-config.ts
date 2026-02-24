// frontend/lib/blocks/page-config.ts
import { readFileSync, existsSync } from 'fs'
import { resolve } from 'path'
import { parse } from 'yaml'
import type { PageConfig } from './types'

const FRONTEND_ROOT = resolve(process.cwd())

/**
 * Loads page block configuration from YAML.
 * Falls back to themes/default/pages/{slug}.yaml if shop theme config is missing.
 *
 * IMPORTANT: Uses fs.readFileSync — only works in Node.js RSC context, NOT Edge runtime.
 */
export function loadPageConfig(slug: string, theme: string = 'default'): PageConfig {
  const themePath = resolve(FRONTEND_ROOT, 'themes', theme, 'pages', `${slug}.yaml`)
  const defaultPath = resolve(FRONTEND_ROOT, 'themes', 'default', 'pages', `${slug}.yaml`)

  let configPath: string

  if (existsSync(themePath)) {
    configPath = themePath
  } else if (existsSync(defaultPath)) {
    if (theme !== 'default') {
      console.warn(`Page config for "${slug}" not found in theme "${theme}", using default`)
    }
    configPath = defaultPath
  } else {
    throw new Error(`Page config not found for slug "${slug}" in theme "${theme}" or default`)
  }

  const content = readFileSync(configPath, 'utf-8')
  const config = parse(content) as PageConfig

  if (!config.blocks || !Array.isArray(config.blocks) || config.blocks.length === 0) {
    throw new Error(`Page config must have at least one block (slug: ${slug})`)
  }

  return config
}
