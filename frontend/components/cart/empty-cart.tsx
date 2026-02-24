import Link from 'next/link'
import { Button } from '@/components/ui/button'

export function EmptyCart() {
  return (
    <div className="text-center py-16">
      <p className="text-text-secondary text-lg mb-4">Dein Warenkorb ist leer.</p>
      <Button asChild>
        <Link href="/">
          Weiter shoppen
        </Link>
      </Button>
    </div>
  )
}
