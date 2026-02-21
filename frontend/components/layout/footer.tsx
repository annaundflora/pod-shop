// frontend/components/layout/footer.tsx
import { getAccountUrl } from '@/lib/config/account'

// WP_URL aus env – zeigt auf WooCommerce WordPress-Seite (localhost:8080 oder Produktions-Domain)
const WP_URL = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'

const LEGAL_LINKS = [
  { label: 'Impressum', href: `${WP_URL}/impressum` },
  { label: 'AGB', href: `${WP_URL}/agb` },
  { label: 'Datenschutz', href: `${WP_URL}/datenschutz` },
  { label: 'Widerruf', href: `${WP_URL}/widerruf` },
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
                <a
                  href={link.href}
                  className="hover:text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded underline-offset-2 hover:underline"
                >
                  {link.label}
                </a>
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
          &copy; {currentYear} POD Shop
        </p>
      </div>
    </footer>
  )
}
