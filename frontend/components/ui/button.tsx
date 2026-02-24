import * as React from 'react'
import { Slot } from '@radix-ui/react-slot'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const buttonVariants = cva(
  // Base styles — gelten für alle Variants
  [
    'inline-flex items-center justify-center gap-2',
    'font-semibold text-sm',
    'rounded-[var(--radius-button,0.5rem)]',
    'transition-all duration-200',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-primary',
    'disabled:pointer-events-none disabled:opacity-50',
    '[touch-action:manipulation]',
  ],
  {
    variants: {
      variant: {
        default: 'bg-primary text-white hover:bg-primary-hover shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-card-hover)]',
        destructive: 'bg-error text-white hover:opacity-90 focus-visible:ring-error',
        outline: 'border border-border bg-transparent text-text-primary hover:bg-surface-elevated',
        ghost: 'bg-transparent text-text-primary hover:bg-surface-elevated',
        link: 'bg-transparent text-primary underline-offset-4 hover:underline p-0 h-auto',
        secondary: 'bg-surface-elevated text-text-primary hover:bg-border',
      },
      size: {
        default: 'h-[var(--min-touch-target,2.75rem)] px-5 py-2',
        sm: 'h-8 px-3 py-1 text-xs',
        lg: 'h-12 px-8 py-3 text-base',
        icon: 'h-[var(--min-touch-target,2.75rem)] w-[var(--min-touch-target,2.75rem)]',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
)

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean
  isLoading?: boolean
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, asChild = false, isLoading = false, children, disabled, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button'
    return (
      <Comp
        className={cn(buttonVariants({ variant, size, className }))}
        ref={ref}
        disabled={disabled || isLoading}
        aria-disabled={disabled || isLoading}
        data-slot="button"
        {...props}
      >
        {isLoading ? (
          <>
            <svg
              aria-hidden="true"
              className="animate-spin h-4 w-4"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path
                className="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
              />
            </svg>
            <span>{children}</span>
          </>
        ) : (
          children
        )}
      </Comp>
    )
  }
)
Button.displayName = 'Button'

export { Button, buttonVariants }
