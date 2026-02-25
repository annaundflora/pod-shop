// frontend/components/layout/footer.tsx
import Link from 'next/link'
import { getAccountUrl } from '@/lib/config/account'

const LEGAL_LINKS = [
  { label: 'Impressum', href: '/impressum' },
  { label: 'AGB', href: '/agb' },
  { label: 'Datenschutz', href: '/datenschutz' },
  { label: 'Widerruf', href: '/widerruf' },
] as const

export function Footer() {
  const currentYear = new Date().getFullYear()

  return (
    <footer className="border-t border-border mt-auto py-8 px-4">
      <div className="max-w-5xl mx-auto">
        {/* Rechtliche Links */}
        <nav aria-label="Rechtliche Seiten">
          <ul className="flex flex-wrap gap-x-4 gap-y-2 justify-center text-sm text-text-secondary">
            {LEGAL_LINKS.map((link) => (
              <li key={link.href}>
                <Link
                  href={link.href}
                  className="hover:text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded underline-offset-2 hover:underline"
                >
                  {link.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        {/* Mein Konto Link */}
        <div className="text-center mt-3">
          <a
            href={getAccountUrl()}
            className="text-sm text-text-secondary hover:text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
          >
            Mein Konto
          </a>
        </div>

        {/* Copyright */}
        <p className="text-center text-xs text-text-secondary mt-4" suppressHydrationWarning>
          &copy; {currentYear} {process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}
        </p>
      </div>
    </footer>
  )
}
