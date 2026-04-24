import { Work_Sans, Source_Serif_4 } from 'next/font/google'

export const bodyFont = Work_Sans({
  subsets: ['latin'],
  weight: ['400', '500', '600', '700'],
  variable: '--font-body',
  display: 'swap',
})

export const headingFont = Source_Serif_4({
  subsets: ['latin'],
  weight: ['600', '700'],
  variable: '--font-heading',
  display: 'swap',
})
