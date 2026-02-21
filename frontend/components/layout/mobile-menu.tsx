// frontend/components/layout/mobile-menu.tsx
'use client'

import { useState } from 'react'
import Link from 'next/link'
import type { ProductCategory } from '@/lib/graphql/types'
import { getAccountUrl } from '@/lib/config/account'

interface MobileMenuProps {
  categories: ProductCategory[]
}

export function MobileMenu({ categories }: MobileMenuProps) {
  const [isOpen, setIsOpen] = useState(false)

  return (
    <>
      {/* Hamburger Button */}
      <button
        type="button"
        onClick={() => setIsOpen(true)}
        aria-label="Menü öffnen"
        aria-expanded={isOpen}
        className="p-2 min-h-[44px] min-w-[44px] flex items-center justify-center text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
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
      </button>

      {/* Overlay / Drawer */}
      {isOpen && (
        <div className="fixed inset-0 z-50 flex">
          {/* Backdrop */}
          <div
            className="absolute inset-0 bg-black/40"
            onClick={() => setIsOpen(false)}
            aria-hidden="true"
          />

          {/* Menu Panel */}
          <nav
            aria-label="Mobile Navigation"
            className="relative bg-surface w-72 max-w-full h-full flex flex-col shadow-xl"
          >
            {/* Header */}
            <div className="flex items-center justify-between px-4 h-14 border-b border-border">
              <span className="font-bold text-lg text-text-primary">Menü</span>
              <button
                type="button"
                onClick={() => setIsOpen(false)}
                aria-label="Menü schließen"
                className="p-2 min-h-[44px] min-w-[44px] flex items-center justify-center text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
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
                    d="M6 18L18 6M6 6l12 12"
                  />
                </svg>
              </button>
            </div>

            {/* Kategorie-Links */}
            <div className="flex-1 overflow-y-auto py-4">
              <p className="px-4 text-xs font-semibold text-text-secondary uppercase tracking-wider mb-2">
                Kategorien
              </p>
              {categories.map((category) => (
                <Link
                  key={category.id}
                  href={`/kategorie/${category.slug}`}
                  onClick={() => setIsOpen(false)}
                  className="block px-4 py-3 text-text-primary hover:bg-surface-elevated transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center"
                >
                  {category.name}
                </Link>
              ))}

              <hr className="my-4 border-border" />

              {/* Legal Links */}
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
        </div>
      )}
    </>
  )
}
