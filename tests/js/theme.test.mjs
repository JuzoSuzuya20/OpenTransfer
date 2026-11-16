// The theme follows the system unless someone chose light or dark (account menu): the choice is
// kept by the browser and set on the page before it shows.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { THEMES, storedTheme, applyTheme } from '../../resources/js/lib/theme.js'

const fakeStorage = () => {
  const data = new Map()
  return { getItem: (k) => (data.has(k) ? data.get(k) : null), setItem: (k, v) => data.set(k, String(v)), removeItem: (k) => data.delete(k) }
}
const fakeRoot = () => ({ dataset: {} })

test('the system by default', () => {
  assert.deepEqual(THEMES, ['system', 'light', 'dark'])
  assert.equal(storedTheme(fakeStorage()), 'system')
})

test('a light or dark choice is set on the page and kept', () => {
  const storage = fakeStorage()
  const root = fakeRoot()
  applyTheme('dark', { root, storage })
  assert.equal(root.dataset.theme, 'dark')
  assert.equal(storedTheme(storage), 'dark')
})

test('back to the system: the page follows it again', () => {
  const storage = fakeStorage()
  const root = fakeRoot()
  applyTheme('light', { root, storage })
  applyTheme('system', { root, storage })
  assert.equal(root.dataset.theme, undefined)
  assert.equal(storedTheme(storage), 'system')
})

test('what is not a theme is the system', () => {
  const storage = fakeStorage()
  storage.setItem('ot-theme', 'purple')
  assert.equal(storedTheme(storage), 'system')
})

test('without storage the choice still applies to the page', () => {
  const root = fakeRoot()
  const broken = { getItem: () => { throw new Error('blocked') }, setItem: () => { throw new Error('blocked') }, removeItem: () => { throw new Error('blocked') } }
  applyTheme('dark', { root, storage: broken })
  assert.equal(root.dataset.theme, 'dark')
  assert.equal(storedTheme(broken), 'system')
})

test('the dark tokens apply to a dark system not set to light, and to a dark choice', async () => {
  const { readFileSync } = await import('node:fs')
  const css = readFileSync(new URL('../../resources/js/style.scss', import.meta.url), 'utf8')
  assert.match(css, /@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \{\s*@include ot-dark-tokens;/)
  assert.match(css, /:root\[data-theme="dark"\] \{\s*@include ot-dark-tokens;/)
})
