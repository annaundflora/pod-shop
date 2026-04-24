// frontend/components/product/product-image-gallery.tsx
'use client'

import { useState } from 'react'
import Image from 'next/image'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { ProductImage } from '@/lib/graphql/types'

interface ProductImageGalleryProps {
  images: ProductImage[]
  productName: string
}

export function ProductImageGallery({ images, productName }: ProductImageGalleryProps) {
  const [activeIndex, setActiveIndex] = useState(0)
  const [touchStart, setTouchStart] = useState<number | null>(null)

  if (images.length === 0) {
    return (
      <div
        className="aspect-product bg-[var(--color-surface-elevated)] rounded-[var(--radius-card,0.75rem)] flex items-center justify-center text-text-secondary"
        style={{ boxShadow: 'var(--shadow-card)' }}
      >
        Kein Bild vorhanden
      </div>
    )
  }

  const activeImage = images[activeIndex]

  const handleTouchStart = (e: React.TouchEvent) => {
    setTouchStart(e.touches[0].clientX)
  }

  const handleTouchEnd = (e: React.TouchEvent) => {
    if (touchStart === null) return
    const delta = touchStart - e.changedTouches[0].clientX
    if (Math.abs(delta) > 50) {
      if (delta > 0) {
        setActiveIndex((prev) => Math.min(prev + 1, images.length - 1))
      } else {
        setActiveIndex((prev) => Math.max(prev - 1, 0))
      }
    }
    setTouchStart(null)
  }

  return (
    <div>
      {/* Hauptbild mit Touch-Swipe-Unterstuetzung (Mobile) */}
      <div
        className="relative aspect-product rounded-[var(--radius-card,0.75rem)] overflow-hidden bg-[var(--color-surface-elevated)] transition-shadow duration-300 hover:[box-shadow:var(--shadow-card-hover)]"
        style={{ boxShadow: 'var(--shadow-card)' }}
        role="region"
        aria-label="Produktbilder"
        onTouchStart={handleTouchStart}
        onTouchEnd={handleTouchEnd}
      >
        <Image
          src={activeImage.sourceUrl}
          alt={activeImage.altText || `${productName} – Bild ${activeIndex + 1}`}
          fill
          priority={activeIndex === 0}
          sizes="(max-width: 1024px) 100vw, 50vw"
          className="object-cover"
        />
      </div>

      {/* Dots (Mobile) / Thumbnails (Desktop) */}
      {images.length > 1 && (
        <>
          {/* Mobile: Dot-Navigation — bleibt native button (Out of Scope) */}
          <div
            className="flex lg:hidden justify-center gap-2 mt-3"
            role="tablist"
            aria-label="Bilder-Navigation"
          >
            {images.map((_, index) => (
              <button
                key={index}
                role="tab"
                aria-selected={index === activeIndex}
                aria-label={`Bild ${index + 1}`}
                onClick={() => setActiveIndex(index)}
                className={[
                  'w-2.5 h-2.5 rounded-full transition-all focus-visible:ring-2 focus-visible:ring-primary',
                  index === activeIndex
                    ? 'bg-primary scale-110'
                    : 'bg-border hover:bg-text-secondary',
                ].join(' ')}
              />
            ))}
          </div>

          {/* Desktop: Thumbnail-Reihe */}
          <div
            className="hidden lg:flex gap-3 mt-4"
            role="tablist"
            aria-label="Bilder-Navigation"
          >
            {images.map((image, index) => (
              <Button
                key={index}
                variant="ghost"
                size="icon"
                role="tab"
                aria-selected={index === activeIndex}
                aria-label={image.altText || `Bild ${index + 1}`}
                onClick={() => setActiveIndex(index)}
                className={cn(
                  'relative w-18 h-18 rounded-[var(--radius-button,0.5rem)] overflow-hidden border-2 transition-all',
                  index === activeIndex
                    ? 'border-primary shadow-[var(--shadow-card)] scale-105'
                    : 'border-border hover:border-text-secondary hover:shadow-[var(--shadow-card)]'
                )}
              >
                <Image
                  src={image.sourceUrl}
                  alt={image.altText || `${productName} – Thumbnail ${index + 1}`}
                  fill
                  sizes="72px"
                  className="object-cover"
                />
              </Button>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
