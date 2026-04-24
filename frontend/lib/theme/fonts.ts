import { Inter, Work_Sans, Source_Serif_4 } from 'next/font/google'

const isKleinstadtpflanze = process.env.NEXT_PUBLIC_THEME === 'kleinstadtpflanze'

const interBody = Inter({
  subsets: ['latin'],
  variable: '--font-body',
  display: 'swap',
})

const interHeading = Inter({
  subsets: ['latin'],
  variable: '--font-heading',
  display: 'swap',
})

const workSansBody = Work_Sans({
  subsets: ['latin'],
  weight: ['400', '500', '600', '700'],
  variable: '--font-body',
  display: 'swap',
})

const sourceSerifHeading = Source_Serif_4({
  subsets: ['latin'],
  weight: ['600', '700'],
  variable: '--font-heading',
  display: 'swap',
})

export const bodyFont = isKleinstadtpflanze ? workSansBody : interBody
export const headingFont = isKleinstadtpflanze ? sourceSerifHeading : interHeading
