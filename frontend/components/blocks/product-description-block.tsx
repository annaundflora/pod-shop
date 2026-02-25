import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductDetailData } from '@/lib/graphql/types'

export function ProductDescriptionBlock({ data }: BlockComponentProps<ProductDetailData | null>) {
  if (!data?.description) return null

  return (
    <Card>
      <CardHeader>
        <CardTitle>Beschreibung</CardTitle>
      </CardHeader>
      <CardContent>
        <div
          className="prose prose-sm text-text-secondary max-w-none"
          dangerouslySetInnerHTML={{ __html: data.description }}
        />
      </CardContent>
    </Card>
  )
}

export function ProductDescriptionBlockSkeleton() {
  return (
    <Card>
      <CardHeader>
        <div className="h-6 bg-surface-elevated rounded-card animate-pulse w-1/4" />
      </CardHeader>
      <CardContent>
        <div className="space-y-2">
          <div className="h-4 bg-surface-elevated rounded animate-pulse w-full" />
          <div className="h-4 bg-surface-elevated rounded animate-pulse w-5/6" />
          <div className="h-4 bg-surface-elevated rounded animate-pulse w-4/6" />
        </div>
      </CardContent>
    </Card>
  )
}
