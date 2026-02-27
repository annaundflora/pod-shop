// frontend/components/reviews/star-rating-input.tsx
'use client'

import { useState } from 'react'
import { Star } from 'lucide-react'

interface StarRatingInputProps {
  value: number
  onChange: (rating: number) => void
  name?: string
}

export function StarRatingInput({ value, onChange, name = 'rating' }: StarRatingInputProps) {
  const [hovered, setHovered] = useState(0)
  const maxStars = 5

  return (
    <div
      className="flex items-center gap-1"
      role="group"
      aria-label="Sternebewertung"
    >
      {Array.from({ length: maxStars }, (_, i) => {
        const starValue = i + 1
        const isActive = hovered ? starValue <= hovered : starValue <= value
        return (
          <button
            key={starValue}
            type="button"
            aria-label={`${starValue} ${starValue === 1 ? 'Stern' : 'Sterne'}`}
            aria-pressed={value === starValue}
            onClick={() => onChange(starValue)}
            onMouseEnter={() => setHovered(starValue)}
            onMouseLeave={() => setHovered(0)}
            className="min-h-[2.75rem] min-w-[2.75rem] flex items-center justify-center hover:scale-110 transition-transform focus-visible:ring-2 focus-visible:ring-primary rounded"
            style={{ touchAction: 'manipulation' }}
          >
            <Star
              className={`h-6 w-6 ${isActive ? 'text-yellow-400 fill-yellow-400' : 'text-text-tertiary'}`}
              aria-hidden={true}
            />
          </button>
        )
      })}
      <input type="hidden" name={name} value={value} />
    </div>
  )
}
