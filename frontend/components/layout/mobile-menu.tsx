// frontend/components/layout/mobile-menu.tsx
'use client'

import Link from 'next/link'
import type { ProductCategory } from '@/lib/graphql/types'
import { getAccountUrl } from '@/lib/config/account'
import { Sheet, SheetTrigger, SheetContent } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'

interface MobileMenuProps {
  categories: ProductCategory[]
}

export function MobileMenu({ categories }: MobileMenuProps) {
  return (
    <Sheet>
      {/* Hamburger Button */}
      <SheetTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          aria-label="Menü öffnen"
        >
          <svg
            aria-hidden="true"
            className="w-6 h-6"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M4 6h16M4 12h16M4 18h16"
            />
          </svg>
        </Button>
      </SheetTrigger>

      <SheetContent side="left">
        {/* Kategorie-Links */}
        <nav aria-label="Mobile Navigation" className="flex flex-col h-full">
          <div className="flex-1 overflow-y-auto py-4 mt-8">
            <p className="px-4 text-xs font-semibold text-text-secondary uppercase tracking-wider mb-2">
              Kategorien
            </p>
            {categories.map((category) => (
              <Link
                key={category.id}
                href={`/kategorie/${category.slug}`}
                className="block px-4 py-3 text-text-primary hover:bg-surface-elevated transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center"
              >
                {category.name}
              </Link>
            ))}

            <hr className="my-4 border-border" />

            <p className="px-4 text-xs font-semibold text-text-secondary uppercase tracking-wider mb-2">
              Info
            </p>
            <a
              href={`${process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'}/impressum`}
              className="block px-4 py-3 text-text-secondary hover:bg-surface-elevated hover:text-text-primary transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center"
            >
              Impressum
            </a>
            <a
              href={`${process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'}/datenschutz`}
              className="block px-4 py-3 text-text-secondary hover:bg-surface-elevated hover:text-text-primary transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center"
            >
              Datenschutz
            </a>
            <a
              href={getAccountUrl()}
              className="block px-4 py-3 text-text-secondary hover:bg-surface-elevated hover:text-text-primary transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center"
            >
              Mein Konto
            </a>
          </div>
        </nav>
      </SheetContent>
    </Sheet>
  )
}
