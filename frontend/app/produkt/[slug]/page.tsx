import { notFound } from 'next/navigation'
import { getClient } from '@/lib/apollo/server-client'
import { GET_PRODUCT, GET_ALL_PRODUCT_SLUGS } from '@/lib/graphql/queries'
import { ProductImageGallery } from '@/components/product/product-image-gallery'
import { ProductVariantSelector } from './product-variant-selector'
import { generateProductJsonLd } from '@/lib/seo/json-ld'
import { extractVariantOptions } from '@/lib/product/variant-utils'
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
    title: `${product.name} | POD Shop`,
    description: product.shortDescription ?? product.description?.substring(0, 160),
    openGraph: {
      images: product.image ? [{ url: product.image.sourceUrl }] : [],
    },
  }
}

interface ProductPageProps {
  params: Promise<{ slug: string }>
}

export default async function ProductPage({ params }: ProductPageProps) {
  const { slug } = await params
  const { data } = await getClient().query<{ product: ProductDetailData }>({
    query: GET_PRODUCT,
    variables: { slug },
  })

  const product = data?.product
  if (!product) notFound()

  const allImages = [
    ...(product.image ? [product.image] : []),
    ...(product.galleryImages?.nodes ?? []),
  ]

  const variantOptions = product.variations
    ? extractVariantOptions(product.variations.nodes)
    : { sizes: [], colors: [] }

  const pageUrl = `${process.env.NEXT_PUBLIC_SHOP_URL ?? 'http://localhost:3000'}/produkt/${slug}`
  const jsonLd = generateProductJsonLd(product, pageUrl)

  return (
    <>
      {/* JSON-LD für SEO */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: jsonLd }}
      />

      <main id="main-content">
        {/* Mobile: Stack. Desktop: 2-Spalten Grid */}
        <div className="lg:grid lg:grid-cols-2 lg:gap-16">
          {/* Bild-Galerie */}
          <ProductImageGallery
            images={allImages}
            productName={product.name}
          />

          {/* Produkt-Infos */}
          <div className="mt-8 lg:mt-0">
            <h1 className="text-3xl font-bold tracking-tight text-text-primary">
              {product.name}
            </h1>

            <div className="mt-4 flex items-baseline gap-3">
              <p className="text-3xl font-bold text-[var(--color-accent,var(--color-primary))]">
                {product.price}
              </p>
            </div>
            <p className="mt-1 text-sm text-text-secondary">inkl. Versandkosten</p>

            <hr className="my-8 border-border" />

            {/* Variant-Selector als Client Component */}
            <ProductVariantSelector
              product={product}
              variantOptions={variantOptions}
            />
          </div>
        </div>

        {/* Beschreibung */}
        {product.description && (
          <section
            className="mt-12 p-6 bg-[var(--color-surface-elevated)] rounded-[var(--radius-card,0.75rem)]"
            style={{ boxShadow: 'var(--shadow-card)' }}
          >
            <h2 className="text-lg font-semibold mb-4 text-text-primary">Beschreibung</h2>
            <div
              className="prose prose-sm text-text-secondary max-w-none"
              dangerouslySetInnerHTML={{ __html: product.description }}
            />
          </section>
        )}
      </main>
    </>
  )
}
