// frontend/components/product/product-image-gallery.tsx
'use client'

import { useState } from 'react'
import Image from 'next/image'
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
      <div className="aspect-square bg-surface-elevated rounded-xl flex items-center justify-center text-text-secondary">
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
        className="relative aspect-square rounded-xl overflow-hidden bg-surface-elevated"
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
          {/* Mobile: Dot-Navigation */}
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
                  'w-2 h-2 rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-primary',
                  index === activeIndex
                    ? 'bg-text-primary'
                    : 'bg-border hover:bg-text-secondary',
                ].join(' ')}
              />
            ))}
          </div>

          {/* Desktop: Thumbnail-Reihe */}
          <div
            className="hidden lg:flex gap-2 mt-3"
            role="tablist"
            aria-label="Bilder-Navigation"
          >
            {images.map((image, index) => (
              <button
                key={index}
                role="tab"
                aria-selected={index === activeIndex}
                aria-label={image.altText || `Bild ${index + 1}`}
                onClick={() => setActiveIndex(index)}
                className={[
                  'relative w-16 h-16 rounded-lg overflow-hidden border-2 transition-colors focus-visible:ring-2 focus-visible:ring-primary',
                  index === activeIndex ? 'border-text-primary' : 'border-border hover:border-text-secondary',
                ].join(' ')}
              >
                <Image
                  src={image.sourceUrl}
                  alt={image.altText || `${productName} – Thumbnail ${index + 1}`}
                  fill
                  sizes="64px"
                  className="object-cover"
                />
              </button>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
