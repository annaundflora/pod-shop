// frontend/components/reviews/star-rating-display.tsx
import { Star } from 'lucide-react'

interface StarRatingDisplayProps {
  rating: number
  maxStars?: number
}

export function StarRatingDisplay({ rating, maxStars = 5 }: StarRatingDisplayProps) {
  return (
    <div className="flex items-center gap-0.5" aria-hidden={true}>
      {Array.from({ length: maxStars }, (_, i) => {
        const filled = i + 1 <= rating
        const halfFilled = !filled && i + 0.5 <= rating
        return (
          <Star
            key={i}
            className={`h-4 w-4 ${filled || halfFilled ? 'text-yellow-400 fill-yellow-400' : 'text-text-tertiary'}`}
          />
        )
      })}
    </div>
  )
}
