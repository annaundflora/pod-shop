// frontend/.storybook/main.ts
import type { StorybookConfig } from '@storybook/nextjs-vite'

const config: StorybookConfig = {
  stories: ['../stories/**/*.stories.@(ts|tsx)'],
  addons: [],
  framework: {
    name: '@storybook/nextjs-vite',
    options: {},
  },
}

export default config
