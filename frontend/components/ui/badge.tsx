import * as React from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const badgeVariants = cva(
  [
    'inline-flex items-center justify-center',
    'rounded-full px-2.5 py-0.5',
    'text-xs font-medium',
    'transition-colors duration-200',
    'border',
  ],
  {
    variants: {
      variant: {
        default: 'bg-primary text-white border-transparent',
        secondary: 'bg-surface-elevated text-text-secondary border-border',
        destructive: 'bg-error text-white border-transparent',
        outline: 'bg-transparent text-text-primary border-border',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
)

export interface BadgeProps
  extends React.HTMLAttributes<HTMLSpanElement>,
    VariantProps<typeof badgeVariants> {}

function Badge({ className, variant, ...props }: BadgeProps) {
  return (
    <span
      className={cn(badgeVariants({ variant, className }))}
      data-slot="badge"
      {...props}
    />
  )
}

export { Badge, badgeVariants }
