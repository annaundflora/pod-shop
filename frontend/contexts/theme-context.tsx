'use client'

import { createContext, useContext, useState, useEffect, useCallback } from 'react'
import { THEMES, DEFAULT_THEME, THEME_STORAGE_KEY, type ThemeId } from '@/lib/theme/themes'

interface ThemeContextValue {
  theme: ThemeId
  setTheme: (theme: ThemeId) => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setThemeState] = useState<ThemeId>(DEFAULT_THEME)

  useEffect(() => {
    const stored = localStorage.getItem(THEME_STORAGE_KEY)
    if (stored && THEMES.some((t) => t.id === stored)) {
      setThemeState(stored as ThemeId)
    }
  }, [])

  const setTheme = useCallback((newTheme: ThemeId) => {
    setThemeState(newTheme)
    localStorage.setItem(THEME_STORAGE_KEY, newTheme)
    document.documentElement.dataset.theme = newTheme
  }, [])

  useEffect(() => {
    document.documentElement.dataset.theme = theme
  }, [theme])

  return (
    <ThemeContext value={{ theme, setTheme }}>
      {children}
    </ThemeContext>
  )
}

export function useTheme() {
  const context = useContext(ThemeContext)
  if (!context) throw new Error('useTheme must be used within ThemeProvider')
  return context
}
