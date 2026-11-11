// White text on the accent (main buttons, the header button, the skip link) reaches WCAG AA,
// 4.5:1, in the light and in the dark scheme, at rest, on hover and when pressed.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'

const css = fs.readFileSync(new URL('../../resources/js/style.scss', import.meta.url), 'utf8')
const darkStart = css.indexOf('@media (prefers-color-scheme: dark)')
const light = css.slice(0, darkStart)
const dark = css.slice(darkStart)

const token = (block, name) => {
  const match = block.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})\\s*;`))
  assert.ok(match, `--${name} is defined as a colour`)
  return match[1]
}

const luminance = (hex) => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
  const lin = (c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
}
const ratio = (a, b) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x)
  return (hi + 0.05) / (lo + 0.05)
}

for (const [scheme, block] of [['light', light], ['dark', dark]]) {
  for (const state of ['ot-accent-fill', 'ot-accent-fill-hover', 'ot-accent-fill-active']) {
    test(`white on ${state} reaches 4.5:1 in the ${scheme} scheme`, () => {
      const value = ratio(token(block, 'ot-on-accent'), token(block, state))
      assert.ok(value >= 4.5, `${state}: ${value.toFixed(2)}:1`)
    })
  }
}

test('main buttons are filled with the accent meant for text', () => {
  assert.match(css, /--primary-button-background-color:\s*var\(--ot-accent-fill\)/)
  assert.match(css, /--primary-button-background-color-hover:\s*var\(--ot-accent-fill-hover\)/)
  assert.match(css, /--primary-button-background-color-active:\s*var\(--ot-accent-fill-active\)/)
})
