import { notFound } from 'next/navigation'
import { getClient } from '@/lib/apollo/server-client'
import { GET_PRODUCT, GET_ALL_PRODUCT_SLUGS } from '@/lib/graphql/queries'
import { generateProductJsonLd } from '@/lib/seo/json-ld'
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { ProductGalleryBlockSkeleton } from '@/components/blocks/product-gallery-block'
import { ProductPurchaseBlockSkeleton } from '@/components/blocks/product-purchase-block'
import { ProductDescriptionBlockSkeleton } from '@/components/blocks/product-description-block'
import type { ProductDetailData } from '@/lib/graphql/types'

export const revalidate = 60

export async function generateStaticParams() {
  const { data } = await getClient().query<{
    products: { nodes: Array<{ slug: string }> }
  }>({
    query: GET_ALL_PRODUCT_SLUGS,
  })
  return (data?.products?.nodes ?? []).map((product) => ({
    slug: product.slug,
  }))
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params
  const { data } = await getClient().query<{ product: ProductDetailData }>({
    query: GET_PRODUCT,
    variables: { slug },
  })
  const product = data?.product
  if (!product) return { title: 'Produkt nicht gefunden' }
  return {
    title: `${product.name} | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`,
    description: product.shortDescription ?? product.description?.substring(0, 160),
    openGraph: {
      images: product.image ? [{ url: product.image.sourceUrl }] : [],
    },
  }
}

const SKELETON_MAP: Record<string, React.ReactNode> = {
  'product-gallery': <ProductGalleryBlockSkeleton />,
  'product-purchase': <ProductPurchaseBlockSkeleton />,
  'product-description': <ProductDescriptionBlockSkeleton />,
  // Slice 07 — Kleinstadtpflanze Layout-Flair (PDP):
  'trust-badges': null,           // inline-sync
  'service-box': null,            // inline-sync
  'motif-cross-sell': null,       // inline-sync (graceful-null)
  'product-recommendations': null,
  'faq-accordion': null,          // inline-sync
  'brand-recap': null,            // inline-sync
}

interface ProductPageProps {
  params: Promise<{ slug: string }>
}

export default async function ProductPage({ params }: ProductPageProps) {
  const { slug } = await params

  // notFound-Pruefung: Produkt muss existieren (generateMetadata hat es bereits gecacht)
  const { data } = await getClient().query<{ product: ProductDetailData }>({
    query: GET_PRODUCT,
    variables: { slug },
  })
  if (!data?.product) notFound()

  const product = data.product
  const pageUrl = `${process.env.NEXT_PUBLIC_SHOP_URL ?? 'http://localhost:3000'}/produkt/${slug}`
  const jsonLd = generateProductJsonLd(product, pageUrl)

  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('product', theme, { slug })

  return (
    <>
      {/* JSON-LD fuer SEO (bleibt in page.tsx — Slice 1 Constraint) */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: jsonLd }}
      />

      <main id="main-content" className="space-y-12">
        <SectionRenderer
          sections={pageConfig.sections}
          skeletonMap={SKELETON_MAP}
        />
      </main>
    </>
  )
}
