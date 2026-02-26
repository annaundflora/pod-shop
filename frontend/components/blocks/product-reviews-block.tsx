// frontend/components/blocks/product-reviews-block.tsx
'use client'

import { useState } from 'react'
import { StarRatingDisplay } from '@/components/reviews/star-rating-display'
import { ReviewCard } from '@/components/reviews/review-card'
import { ReviewForm } from '@/components/reviews/review-form'
import type { BlockComponentProps, ProductReviewsResult } from '@/lib/blocks/types'

// Re-export validateReviewInput for tests
export { validateReviewInput } from '@/components/reviews/review-form'

type ReviewFormState = 'hidden' | 'open' | 'loading' | 'success' | 'error'

export function ProductReviewsBlock({ data }: BlockComponentProps<ProductReviewsResult | null>) {
  const [formState, setFormState] = useState<ReviewFormState>('hidden')

  // Guard: keine Reviews erlaubt → Block unsichtbar
  if (!data || !data.reviewsAllowed) return null

  const { averageRating, reviewCount, reviews, productId } = data

  return (
    <section aria-labelledby="reviews-heading">
      <h2 id="reviews-heading" className="text-2xl font-semibold tracking-tight mb-6">
        Kundenbewertungen
      </h2>

      {/* Sterne-Zusammenfassung */}
      <div
        role="img"
        aria-label={`${averageRating.toFixed(1).replace('.', ',')} von 5 Sternen, ${reviewCount} Bewertungen`}
        className="flex items-center gap-3 mb-6"
      >
        <StarRatingDisplay rating={averageRating} />
        <span className="text-lg font-medium">{averageRating.toFixed(1).replace('.', ',')}</span>
        <span className="text-text-secondary">· {reviewCount} Bewertungen</span>
      </div>

      {/* Review-Liste */}
      {reviews.edges.length > 0 ? (
        <ul className="divide-y divide-border mb-8" aria-label="Bewertungsliste">
          {reviews.edges.map((edge) => (
            <li key={edge.node.id}>
              <ReviewCard edge={edge} />
            </li>
          ))}
        </ul>
      ) : null}

      {/* Review-Form Toggle */}
      <div aria-live="polite" aria-atomic="true">
        {formState === 'success' ? (
          <p className="text-green-700 font-medium">
            Danke für deine Bewertung! Sie wird nach Prüfung angezeigt.
          </p>
        ) : (
          <>
            {formState === 'hidden' ? (
              <button
                type="button"
                aria-expanded={false}
                onClick={() => setFormState('open')}
                className="px-4 py-2 text-sm border border-border rounded-card hover:bg-surface-secondary touch-manipulation"
                style={{ touchAction: 'manipulation' }}
              >
                Bewertung schreiben
              </button>
            ) : (
              <ReviewForm
                productId={productId}
                formState={formState}
                onStateChange={setFormState}
                onCancel={() => setFormState('hidden')}
              />
            )}
          </>
        )}
      </div>
    </section>
  )
}

export function ProductReviewsBlockSkeleton() {
  return (
    <section aria-labelledby="reviews-heading-loading">
      <div className="h-8 w-48 bg-surface-sunken rounded animate-pulse mb-6" />
      <div className="h-6 w-64 bg-surface-sunken rounded animate-pulse mb-6" />
      {[0, 1].map((i) => (
        <div key={i} className="py-4 space-y-2 border-b border-border">
          <div className="h-4 w-32 bg-surface-sunken rounded animate-pulse" />
          <div className="h-4 w-full bg-surface-sunken rounded animate-pulse" />
        </div>
      ))}
    </section>
  )
}
