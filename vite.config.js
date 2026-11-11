import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import autoprefixer from 'autoprefixer'
import fs from 'node:fs'

// The emails load the typeface and the mark of the application from the instance itself
// (never from a third party, which would see the address of every reader), at addresses that
// do not change from one build to the next: public/build/email/…
const EMAIL_ASSETS = {
  'plus-jakarta-sans-latin-wght-normal.woff2': 'node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-wght-normal.woff2',
  'plus-jakarta-sans-latin-ext-wght-normal.woff2': 'node_modules/@fontsource-variable/plus-jakarta-sans/files/plus-jakarta-sans-latin-ext-wght-normal.woff2',
  'OFL.txt': 'node_modules/@fontsource-variable/plus-jakarta-sans/LICENSE',
  'brand-mark.png': 'resources/images/email/brand-mark.png'
}

const emailAssets = () => ({
  name: 'opentransfer-email-assets',
  apply: 'build',
  generateBundle() {
    for (const [name, source] of Object.entries(EMAIL_ASSETS)) {
      this.emitFile({ type: 'asset', fileName: `email/${name}`, source: fs.readFileSync(source) })
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
      input: ['resources/js/app.js'],
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