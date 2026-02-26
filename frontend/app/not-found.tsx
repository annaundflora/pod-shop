// frontend/app/not-found.tsx
import Link from 'next/link'
import type { Metadata } from 'next'

export const metadata: Metadata = {
  title: 'Seite nicht gefunden | POD Shop',
  robots: { index: false, follow: false },
}

export default function NotFound() {
  return (
    <main
      id="main-content"
      className="container mx-auto px-4 py-24 text-center"
    >
      <p className="text-8xl font-bold text-primary mb-6" aria-hidden="true">
        404
      </p>
      <h1 className="text-3xl font-bold text-text-primary mb-4">
        Diese Seite wurde nicht gefunden
      </h1>
      <p className="text-text-secondary mb-8 max-w-md mx-auto">
        Der Link ist möglicherweise abgelaufen oder die Seite wurde verschoben.
      </p>
      <div className="flex flex-col sm:flex-row gap-4 justify-center">
        <Link
          href="/"
          className="px-6 py-3 bg-primary text-white rounded-card hover:opacity-90 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          style={{ touchAction: 'manipulation' }}
        >
          Zurück zur Startseite
        </Link>
        <Link
          href="/kategorie/alle"
          className="px-6 py-3 border border-border text-text-primary rounded-card hover:bg-secondary focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          style={{ touchAction: 'manipulation' }}
        >
          Zum Shop
        </Link>
      </div>
    </main>
  )
}
