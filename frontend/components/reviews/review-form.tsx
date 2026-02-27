// frontend/components/reviews/review-form.tsx
'use client'

import { useState } from 'react'
import { StarRatingInput } from './star-rating-input'
import { apolloClient } from '@/lib/apollo/client'
import { WRITE_REVIEW } from '@/lib/graphql/mutations'

export interface ReviewFormInput {
  author: string
  authorEmail: string
  rating: number
  content: string
}

export type ReviewValidationErrors = Partial<Record<keyof ReviewFormInput, string>>

export function validateReviewInput(input: ReviewFormInput): ReviewValidationErrors {
  const errors: ReviewValidationErrors = {}

  if (!input.author || input.author.trim().length < 2) {
    errors.author = 'Bitte gib deinen Namen ein (min. 2 Zeichen)'
  }

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!input.authorEmail || !emailRegex.test(input.authorEmail.trim())) {
    errors.authorEmail = 'Bitte gib eine gültige E-Mail-Adresse ein'
  }

  if (!input.rating || input.rating < 1 || input.rating > 5 || !Number.isInteger(input.rating)) {
    errors.rating = 'Bitte wähle eine Bewertung (1–5 Sterne)'
  }

  if (!input.content || input.content.trim().length < 10) {
    errors.content = 'Bitte schreibe mindestens 10 Zeichen'
  }

  return errors
}

type ReviewFormState = 'hidden' | 'open' | 'loading' | 'success' | 'error'

interface ReviewFormProps {
  productId: number
  formState: ReviewFormState
  onStateChange: (state: ReviewFormState) => void
  onCancel: () => void
}

export function ReviewForm({ productId, formState, onStateChange, onCancel }: ReviewFormProps) {
  const [author, setAuthor] = useState('')
  const [authorEmail, setAuthorEmail] = useState('')
  const [rating, setRating] = useState(0)
  const [content, setContent] = useState('')
  const [errors, setErrors] = useState<ReviewValidationErrors>({})
  const [errorMessage, setErrorMessage] = useState('')

  const isLoading = formState === 'loading'

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    const input: ReviewFormInput = { author, authorEmail, rating, content }
    const validationErrors = validateReviewInput(input)

    if (Object.keys(validationErrors).length > 0) {
      setErrors(validationErrors)
      return
    }

    setErrors({})
    onStateChange('loading')

    try {
      await apolloClient.mutate({
        mutation: WRITE_REVIEW,
        variables: {
          productId,
          content,
          author,
          authorEmail,
          rating,
        },
      })
      onStateChange('success')
    } catch {
      setErrorMessage('Bewertung konnte nicht gespeichert werden. Bitte versuche es erneut.')
      onStateChange('error')
    }
  }

  return (
    <div className="mt-4">
      {(formState === 'error') && errorMessage && (
        <div role="alert" className="mb-4 p-3 bg-red-50 border border-red-200 rounded-card text-red-700 text-sm">
          {errorMessage}
        </div>
      )}
      <form onSubmit={handleSubmit} className="space-y-4 max-w-lg" noValidate>
        <div>
          <label htmlFor="review-author" className="block text-sm font-medium text-text-primary mb-1">
            Name <span aria-hidden="true">*</span>
          </label>
          <input
            id="review-author"
            type="text"
            value={author}
            onChange={e => setAuthor(e.target.value)}
            aria-invalid={!!errors.author}
            aria-describedby={errors.author ? 'review-author-error' : undefined}
            disabled={isLoading}
            className="w-full border border-border rounded-card px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50"
            autoComplete="name"
          />
          {errors.author && (
            <span id="review-author-error" className="text-xs text-red-600 mt-1 block">
              {errors.author}
            </span>
          )}
        </div>

        <div>
          <label htmlFor="review-email" className="block text-sm font-medium text-text-primary mb-1">
            E-Mail <span aria-hidden="true">*</span>
          </label>
          <input
            id="review-email"
            type="email"
            value={authorEmail}
            onChange={e => setAuthorEmail(e.target.value)}
            aria-invalid={!!errors.authorEmail}
            aria-describedby={errors.authorEmail ? 'review-email-error' : undefined}
            disabled={isLoading}
            className="w-full border border-border rounded-card px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50"
            autoComplete="email"
          />
          {errors.authorEmail && (
            <span id="review-email-error" className="text-xs text-red-600 mt-1 block">
              {errors.authorEmail}
            </span>
          )}
        </div>

        <div>
          <p className="text-sm font-medium text-text-primary mb-1">
            Bewertung <span aria-hidden="true">*</span>
          </p>
          <StarRatingInput
            value={rating}
            onChange={setRating}
          />
          {errors.rating && (
            <span className="text-xs text-red-600 mt-1 block">
              {errors.rating}
            </span>
          )}
        </div>

        <div>
          <label htmlFor="review-content" className="block text-sm font-medium text-text-primary mb-1">
            Deine Bewertung <span aria-hidden="true">*</span>
          </label>
          <textarea
            id="review-content"
            value={content}
            onChange={e => setContent(e.target.value)}
            aria-invalid={!!errors.content}
            aria-describedby={errors.content ? 'review-content-error' : undefined}
            aria-label="Bewertungstext"
            disabled={isLoading}
            rows={4}
            className="w-full border border-border rounded-card px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50 resize-none"
          />
          {errors.content && (
            <span id="review-content-error" className="text-xs text-red-600 mt-1 block">
              {errors.content}
            </span>
          )}
        </div>

        <div className="flex gap-3">
          <button
            type="button"
            onClick={onCancel}
            disabled={isLoading}
            className="px-4 py-2 text-sm border border-border rounded-card hover:bg-surface-secondary disabled:opacity-50"
          >
            Abbrechen
          </button>
          <button
            type="submit"
            disabled={isLoading}
            className="px-4 py-2 text-sm bg-primary text-primary-foreground rounded-card hover:opacity-90 disabled:opacity-50 flex items-center gap-2"
          >
            {isLoading && (
              <span
                className="h-4 w-4 border-2 border-white border-t-transparent rounded-full animate-spin"
                aria-hidden="true"
              />
            )}
            Absenden
          </button>
        </div>
      </form>
    </div>
  )
}
