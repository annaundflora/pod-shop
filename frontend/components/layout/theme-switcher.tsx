'use client'

import { useTheme } from '@/contexts/theme-context'
import { THEMES } from '@/lib/theme/themes'

const THEME_COLORS: Record<string, string> = {
  modern: 'bg-purple-600',
  bold: 'bg-orange-500',
  soft: 'bg-emerald-500',
}

export function ThemeSwitcher() {
  const { theme, setTheme } = useTheme()

  return (
    <div className="flex items-center gap-1.5" role="radiogroup" aria-label="Theme wechseln">
      {THEMES.map((t) => (
        <button
          key={t.id}
          type="button"
          role="radio"
          aria-checked={theme === t.id}
          aria-label={`${t.label} Theme`}
          onClick={() => setTheme(t.id)}
          className={[
            'w-6 h-6 rounded-full transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
            THEME_COLORS[t.id],
            theme === t.id
              ? 'ring-2 ring-text-primary ring-offset-2 scale-110'
              : 'opacity-60 hover:opacity-100 hover:scale-105',
          ].join(' ')}
          style={{ minHeight: 'var(--min-touch-target)', minWidth: '24px' }}
        />
      ))}
    </div>
  )
}
