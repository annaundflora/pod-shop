export const THEMES = [
  { id: 'modern', label: 'Modern', icon: '◻' },
  { id: 'bold', label: 'Bold', icon: '◼' },
  { id: 'soft', label: 'Soft', icon: '○' },
] as const

export type ThemeId = (typeof THEMES)[number]['id']

export const DEFAULT_THEME: ThemeId = 'modern'
export const THEME_STORAGE_KEY = 'pod-theme'
