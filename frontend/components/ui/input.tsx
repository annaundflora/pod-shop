import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const inputVariants = cva(
  [
    'flex w-full',
    'h-[var(--min-touch-target,2.75rem)] px-3 py-2',
    'rounded-[var(--radius-button,0.5rem)]',
    'border bg-surface',
    'text-sm text-text-primary',
    'placeholder:text-text-secondary',
    'transition-colors duration-200',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-0 focus-visible:ring-primary focus-visible:border-primary',
    'disabled:pointer-events-none disabled:opacity-50 disabled:cursor-not-allowed',
    'file:border-0 file:bg-transparent file:text-sm file:font-medium',
  ],
  {
    variants: {
      variant: {
        default: 'border-border',
        error: 'border-error focus-visible:ring-error',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
)

export interface InputProps
  extends React.InputHTMLAttributes<HTMLInputElement>,
    VariantProps<typeof inputVariants> {
  errorMessage?: string
}

const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, variant, type, errorMessage, id, ...props }, ref) => {
    const inputId = id
    const errorId = errorMessage && inputId ? `${inputId}-error` : undefined

    return (
      <div className="w-full" data-slot="input-wrapper">
        <input
          type={type}
          className={cn(inputVariants({ variant, className }))}
          ref={ref}
          id={inputId}
          aria-invalid={variant === 'error' || !!errorMessage}
          aria-describedby={errorId}
          data-slot="input"
          style={{ touchAction: 'manipulation' }}
          {...props}
        />
        {errorMessage && (
          <p
            id={errorId}
            className="mt-1 text-xs text-error"
            role="alert"
            data-slot="input-error"
          >
            {errorMessage}
          </p>
        )}
      </div>
    )
  }
)
Input.displayName = 'Input'

export { Input, inputVariants }
