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
        <div className="lg:grid lg:grid-cols-2 lg:gap-12">
          {/* Bild-Galerie */}
          <ProductImageGallery
            images={allImages}
            productName={product.name}
          />

          {/* Produkt-Infos */}
          <div className="mt-6 lg:mt-0">
            <h1 className="text-2xl font-bold tracking-tight text-text-primary">
              {product.name}
            </h1>

            <p className="mt-2 text-2xl font-semibold text-text-primary">
              {product.price}
            </p>
            <p className="mt-1 text-sm text-text-secondary">inkl. Versandkosten</p>

            <hr className="my-6 border-border" />

            {/* Variant-Selector als Client Component */}
            <ProductVariantSelector
              product={product}
              variantOptions={variantOptions}
            />
          </div>
        </div>

        {/* Beschreibung */}
        {product.description && (
          <div className="mt-10">
            <hr className="mb-6 border-border" />
            <h2 className="text-lg font-semibold mb-3">Beschreibung</h2>
            <div
              className="prose prose-sm text-text-secondary max-w-none"
              dangerouslySetInnerHTML={{ __html: product.description }}
            />
          </div>
        )}
      </main>
    </>
  )
}
