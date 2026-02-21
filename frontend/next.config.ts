import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  images: {
    remotePatterns: [
      {
        protocol: 'http',
        hostname: 'localhost',
        port: '8080',
        pathname: '/wp-content/uploads/**',
      },
    ],
  },
  env: {
    NEXT_PUBLIC_GRAPHQL_URL: process.env.NEXT_PUBLIC_GRAPHQL_URL ?? 'http://localhost:8080/graphql',
    NEXT_PUBLIC_WP_URL: process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080',
  },
}

export default nextConfig
