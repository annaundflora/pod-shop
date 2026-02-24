// frontend/.storybook/preview.ts
import type { Preview } from '@storybook/react'
import '../app/globals.css'
import '../app/generated-theme.css'

const preview: Preview = {
  parameters: {
    backgrounds: {
      default: 'light',
      values: [
        { name: 'light', value: '#ffffff' },
        { name: 'surface-elevated', value: '#f5f5f5' },
      ],
    },
    viewport: {
      viewports: {
        mobile: {
          name: 'Mobile (375px)',
          styles: { width: '375px', height: '812px' },
        },
        tablet: {
          name: 'Tablet (768px)',
          styles: { width: '768px', height: '1024px' },
        },
        desktop: {
          name: 'Desktop (1280px)',
          styles: { width: '1280px', height: '900px' },
        },
      },
      defaultViewport: 'desktop',
    },
    controls: {
      matchers: {
        color: /(background|color)$/i,
        date: /Date$/i,
      },
    },
  },
}

export default preview
