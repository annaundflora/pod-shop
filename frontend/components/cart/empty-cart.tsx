import Link from 'next/link'

export function EmptyCart() {
  return (
    <div className="text-center py-16">
      <p className="text-text-secondary text-lg mb-4">Dein Warenkorb ist leer.</p>
      <Link
        href="/"
        className="inline-flex items-center px-5 py-2.5 bg-primary text-white rounded-lg font-medium hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 transition-colors"
        style={{ touchAction: 'manipulation' }}
      >
        Weiter shoppen
      </Link>
    </div>
  )
}
