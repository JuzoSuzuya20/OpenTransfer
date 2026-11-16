// The main colour of the instance is chosen among a few, each tuned for both themes so that
// coloured text and the white text of buttons stay readable (4.5:1 at least).
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ACCENTS, DEFAULT_ACCENT, accentCss } from '../../resources/js/lib/accents.js'

const VARS = ['accent', 'accent-hover', 'accent-active', 'accent-fill', 'accent-fill-hover', 'accent-fill-active', 'accent-text', 'accent-text-hover', 'on-accent', 'accent-soft', 'line-kbd']
const channel = (v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4)
const luminance = (hex) => {
  const [r, g, b] = [1, 3, 5].map((i) => channel(parseInt(hex.slice(i, i + 2), 16) / 255))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
const contrast = (a, b) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

test('the default colour is the blue violet of OpenTransfer', () => {
  assert.equal(DEFAULT_ACCENT, 'indigo')
  assert.equal(ACCENTS.indigo.dark.accent, '#6a6af4')
})

test('every colour is set for both themes', () => {
  for (const [name, accent] of Object.entries(ACCENTS)) {
    for (const theme of ['light', 'dark']) {
      assert.deepEqual(Object.keys(accent[theme]).sort(), [...VARS].sort(), `${name} ${theme}`)
    }
  }
})

test('coloured text and the white of buttons stay readable in both themes', () => {
  for (const [name, accent] of Object.entries(ACCENTS)) {
    assert.ok(contrast(accent.light['accent-text'], '#ffffff') >= 4.5, `${name}: text on white`)
    assert.ok(contrast('#ffffff', accent.light['accent-fill']) >= 4.5, `${name}: white on a light button`)
    assert.ok(contrast(accent.dark['accent-text'], '#1e1e1e') >= 4.5, `${name}: text on dark`)
    assert.ok(contrast('#ffffff', accent.dark['accent-fill']) >= 4.5, `${name}: white on a dark button`)
  }
})

test('the default colour needs no style of its own', () => {
  assert.equal(accentCss('indigo'), '')
  assert.equal(accentCss('nothing-like-it'), '')
})

test('another colour overrides the accent of both themes', () => {
  const css = accentCss('emerald')
  assert.match(css, new RegExp(`^:root\\{[^}]*--ot-accent:${ACCENTS.emerald.light.accent};`))
  assert.ok(css.includes(`@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){`))
  assert.ok(css.includes(`:root[data-theme="dark"]{`))
  assert.ok(css.includes(`--ot-accent:${ACCENTS.emerald.dark.accent};`))
})

test('the build publishes the mark of every colour for the emails', async () => {
  const { default: config } = await import('../../vite.config.js')
  const resolved = typeof config === 'function' ? await config({ command: 'build', mode: 'production' }) : config
  const plugin = resolved.plugins.flat(Infinity).find((p) => p?.name === 'opentransfer-email-assets')
  const emitted = []
  plugin.generateBundle.call({ emitFile: (file) => emitted.push(file.fileName) })
  for (const id of Object.keys(ACCENTS)) {
    const name = id === DEFAULT_ACCENT ? 'brand-mark.png' : `brand-mark-${id}.png`
    assert.ok(emitted.includes(`email/${name}`), `email/${name}`)
  }
})
