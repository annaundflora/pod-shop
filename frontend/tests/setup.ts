import { vi } from 'vitest'
import '@testing-library/jest-dom'

// Slice 07 — Kleinstadtpflanze Layout-Flair:
// Default the theme env to 'kleinstadtpflanze' for the suite.
// Tests under tests/slices/kleinstadtpflanze-layout-flair/ load YAML from
// themes/kleinstadtpflanze and assert theme-specific behavior; without this
// default, loadPageConfig falls back to themes/default and tests fail noisily.
// Tests that need a different theme (e.g. design-e) override per-call.
process.env.NEXT_PUBLIC_THEME = process.env.NEXT_PUBLIC_THEME ?? 'kleinstadtpflanze'

// Mock localStorage für Tests (jsdom hat limitierte Implementierung)
const localStorageMock = (() => {
  let store: Record<string, string> = {}
  return {
    getItem: (key: string) => store[key] ?? null,
    setItem: (key: string, value: string) => { store[key] = value },
    removeItem: (key: string) => { delete store[key] },
    clear: () => { store = {} },
  }
})()

Object.defineProperty(window, 'localStorage', { value: localStorageMock })
