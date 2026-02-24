import * as React from 'react'
import { cn } from '@/lib/utils'

export interface SkeletonProps extends React.HTMLAttributes<HTMLDivElement> {}

function Skeleton({ className, ...props }: SkeletonProps) {
  return (
    <div
      className={cn('animate-pulse rounded-md bg-surface-elevated', className)}
      aria-label="Wird geladen..."
      aria-busy="true"
      data-slot="skeleton"
      {...props}
    />
  )
}

export { Skeleton }
