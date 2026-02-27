// frontend/components/blocks/pagination-block.tsx
import type { BlockComponentProps } from '@/lib/blocks/types'

interface PaginationData {
  currentPage: number
  totalPages: number
  baseUrl: string
  currentSort?: string
  currentQuery?: string
}

function buildPageUrl(
  baseUrl: string,
  page: number,
  currentSort?: string,
  currentQuery?: string
): string {
  // If baseUrl already contains query params, append with & instead of ?
  const [base, existingQuery] = baseUrl.split('?')
  const params = new URLSearchParams(existingQuery ?? '')
  params.set('page', String(page))
  // Only set sort/q when NOT already in baseUrl (avoid duplicates)
  if (!existingQuery?.includes('sort=') && currentSort && currentSort !== 'default') {
    params.set('sort', currentSort)
  }
  if (!existingQuery?.includes('q=') && currentQuery) {
    params.set('q', currentQuery)
  }
  return `${base}?${params.toString()}`
}

function getVisiblePages(currentPage: number, totalPages: number): (number | '...')[] {
  if (totalPages <= 7) {
    return Array.from({ length: totalPages }, (_, i) => i + 1)
  }
  const pages: (number | '...')[] = [1]
  if (currentPage > 3) pages.push('...')
  for (let p = Math.max(2, currentPage - 1); p <= Math.min(totalPages - 1, currentPage + 1); p++) {
    pages.push(p)
  }
  if (currentPage < totalPages - 2) pages.push('...')
  pages.push(totalPages)
  return pages
}

export function PaginationBlock({ data }: BlockComponentProps<PaginationData>) {
  const { currentPage, totalPages, baseUrl, currentSort, currentQuery } = data

  if (!totalPages || totalPages <= 1) return null

  const visiblePages = getVisiblePages(currentPage, totalPages)
  const isFirstPage = currentPage <= 1
  const isLastPage = currentPage >= totalPages

  return (
    <nav aria-label="Seitennavigation">
      <ol className="flex items-center gap-1 flex-wrap justify-center">
        {/* Prev Button */}
        <li>
          <a
            href={isFirstPage ? '#' : buildPageUrl(baseUrl, currentPage - 1, currentSort, currentQuery)}
            aria-label="Vorige Seite"
            aria-disabled={isFirstPage ? 'true' : undefined}
            className={`flex items-center justify-center min-h-[2.75rem] min-w-[2.75rem] px-3 rounded-card text-sm font-medium transition-colors
              ${isFirstPage
                ? 'aria-disabled:pointer-events-none opacity-40 cursor-default'
                : 'hover:bg-surface-secondary'
              }`}
            style={{ touchAction: 'manipulation' }}
            tabIndex={isFirstPage ? -1 : undefined}
          >
            ‹
          </a>
        </li>

        {/* Page Numbers */}
        {visiblePages.map((page, index) => {
          if (page === '...') {
            return (
              <li key={`ellipsis-${index}`} aria-hidden="true">
                <span className="flex items-center justify-center min-h-[2.75rem] min-w-[2.75rem] px-2 text-sm text-text-secondary">
                  …
                </span>
              </li>
            )
          }

          const isCurrentPage = page === currentPage
          return (
            <li key={page}>
              <a
                href={isCurrentPage ? '#' : buildPageUrl(baseUrl, page, currentSort, currentQuery)}
                aria-current={isCurrentPage ? 'page' : undefined}
                aria-label={String(page)}
                className={`flex items-center justify-center min-h-[2.75rem] min-w-[2.75rem] px-3 rounded-card text-sm font-medium transition-colors
                  ${isCurrentPage
                    ? 'bg-primary text-primary-foreground pointer-events-none'
                    : 'hover:bg-surface-secondary'
                  }`}
                style={{ touchAction: 'manipulation' }}
                tabIndex={isCurrentPage ? -1 : undefined}
              >
                {page}
              </a>
            </li>
          )
        })}

        {/* Next Button */}
        <li>
          <a
            href={isLastPage ? '#' : buildPageUrl(baseUrl, currentPage + 1, currentSort, currentQuery)}
            aria-label="Nächste Seite"
            aria-disabled={isLastPage ? 'true' : undefined}
            className={`flex items-center justify-center min-h-[2.75rem] min-w-[2.75rem] px-3 rounded-card text-sm font-medium transition-colors
              ${isLastPage
                ? 'aria-disabled:pointer-events-none opacity-40 cursor-default'
                : 'hover:bg-surface-secondary'
              }`}
            style={{ touchAction: 'manipulation' }}
            tabIndex={isLastPage ? -1 : undefined}
          >
            ›
          </a>
        </li>
      </ol>
    </nav>
  )
}
