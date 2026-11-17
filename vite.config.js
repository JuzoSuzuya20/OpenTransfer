import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import autoprefixer from 'autoprefixer'
import fs from 'node:fs'

// The emails, the favicon and the main colours (lib/accents) load the typeface and the logo of the
// application from the instance itself
// (never from a third party, which would see the address of every reader), at addresses that
// do not change from one build to the next: public/build/email/…
const ACCENT_IDS = Object.keys(JSON.parse(fs.readFileSync('resources/js/lib/accents.json', 'utf8')))
const EMAIL_ASSETS = {
  'plus-jakarta-sans-latin-wght-normal.woff2': 'node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-wght-normal.woff2',
  'plus-jakarta-sans-latin-ext-wght-normal.woff2': 'node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-ext-wght-normal.woff2',
  'OFL.txt': 'node_modules/@fontsource-variable/plus-jakarta-sans/LICENSE',
  'brand-mark.png': 'resources/images/email/brand-mark.png',
  // The mark in each main colour (Appearance), the default one being brand-mark.png
  ...Object.fromEntries(
    ACCENT_IDS.filter((id) => id !== 'indigo').map((id) => [`brand-mark-${id}.png`, `resources/images/email/brand-mark-${id}.png`])
  )
}
// The logo in each main colour, published as build/brand/logo-<colour>.png
const BRAND_ASSETS = Object.fromEntries(ACCENT_IDS.map((id) => [`logo-${id}.png`, `resources/images/brand/logo-${id}.png`]))

const emailAssets = () => ({
  name: 'opentransfer-email-assets',
  apply: 'build',
  generateBundle() {
    for (const [name, source] of Object.entries(EMAIL_ASSETS)) {
      this.emitFile({ type: 'asset', fileName: `email/${name}`, source: fs.readFileSync(source) })
    }
    for (const [name, source] of Object.entries(BRAND_ASSETS)) {
      this.emitFile({ type: 'asset', fileName: `brand/${name}`, source: fs.readFileSync(source) })
    }
  }
})

export default defineConfig({
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    cors: true,
    hmr: {
      host: process.env.VITE_DEV_HOST || 'localhost'
    }
  },
  plugins: [
    laravel({
      // status.js: the pages of resources/views/shares, which need the styles but not the application
      input: ['resources/js/app.js', 'resources/js/status.js'],
      refresh: true
    }),
    vue(),
    emailAssets()
  ],
  css: {
    postcss: {
      plugins: [autoprefixer]
    }
  }
})