// frontend/components/reviews/review-card.tsx
import { memo } from 'react'
import { StarRatingDisplay } from './star-rating-display'
import type { ReviewEdge } from '@/lib/blocks/types'

interface ReviewCardProps {
  edge: ReviewEdge
}

function ReviewCardComponent({ edge }: ReviewCardProps) {
  const { rating, node } = edge
  const authorName = node.author?.node?.name ?? 'Anonym'
  const date = new Date(node.date).toLocaleDateString('de-DE', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })

  // Strip HTML tags from content (WooCommerce returns HTML)
  const content = node.content.replace(/<[^>]*>/g, '').trim()

  return (
    <div className="py-4">
      <div className="flex items-center gap-3 mb-2">
        <StarRatingDisplay rating={rating} />
        <span className="font-medium text-text-primary text-sm">{authorName}</span>
        <span className="text-text-tertiary text-xs">· {date}</span>
      </div>
      <p className="text-text-secondary text-sm leading-relaxed">{content}</p>
    </div>
  )
}

export const ReviewCard = memo(ReviewCardComponent)
