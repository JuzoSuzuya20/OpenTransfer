// The theme follows the system unless someone chose light or dark (account menu): the choice is
// kept by the browser and set on the page before it shows.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { THEMES, storedTheme, applyTheme, switchTheme, followSystemTheme } from '../../resources/js/lib/theme.js'

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

// A page whose colours change: the root's classes, a frame queue, and the view transitions of the
// browser when it has them
const fakePage = ({ viewTransitions = false } = {}) => {
  const classes = new Set()
  const root = { dataset: {}, classList: { add: (c) => classes.add(c), remove: (c) => classes.delete(c) } }
  const page = { doc: { documentElement: root }, root, classes, frames: [], transitions: [] }
  page.frame = (callback) => page.frames.push(callback)
  page.runFrames = () => { while (page.frames.length) page.frames.shift()() }
  if (viewTransitions) {
    page.doc.startViewTransition = (update) => {
      let finish
      const transition = { update, finished: new Promise((resolve) => { finish = resolve }), ready: Promise.resolve() }
      transition.finish = finish
      page.transitions.push(transition)
      return transition
    }
  }
  return page
}

test('switching: every colour changes at once, then the transitions come back', () => {
  const page = fakePage()
  const choice = switchTheme('dark', { doc: page.doc, storage: fakeStorage(), frame: page.frame, reducedMotion: false })

  assert.equal(choice, 'dark')
  assert.equal(page.root.dataset.theme, 'dark')
  assert.ok(page.classes.has('ot-theme-switching'), 'no element fades its colour on its own')
  page.runFrames()
  assert.ok(!page.classes.has('ot-theme-switching'))
})

test('switching where the browser can: one crossfade of the whole page', async () => {
  const page = fakePage({ viewTransitions: true })
  const choice = switchTheme('light', { doc: page.doc, storage: fakeStorage(), frame: page.frame, reducedMotion: false })

  assert.equal(choice, 'light')
  assert.equal(page.transitions.length, 1)
  assert.equal(page.root.dataset.theme, undefined, 'the page is captured before it changes')
  page.transitions[0].update()
  assert.equal(page.root.dataset.theme, 'light')
  assert.ok(page.classes.has('ot-theme-switching'))
  page.transitions[0].finish()
  await page.transitions[0].finished
  await null
  assert.ok(!page.classes.has('ot-theme-switching'))
})

test('less motion asked for: no crossfade, the colours change at once', () => {
  const page = fakePage({ viewTransitions: true })
  switchTheme('dark', { doc: page.doc, storage: fakeStorage(), frame: page.frame, reducedMotion: true })

  assert.equal(page.transitions.length, 0)
  assert.equal(page.root.dataset.theme, 'dark')
})

test('the theme of the system changing changes every colour at once too', () => {
  const page = fakePage()
  const listeners = []
  const query = { addEventListener: (type, listener) => listeners.push(listener) }
  followSystemTheme({ doc: page.doc, query, frame: page.frame })

  listeners.forEach((listener) => listener())
  assert.ok(page.classes.has('ot-theme-switching'))
  page.runFrames()
  assert.ok(!page.classes.has('ot-theme-switching'))

  // A theme chosen does not follow the system
  page.root.dataset.theme = 'light'
  listeners.forEach((listener) => listener())
  assert.ok(!page.classes.has('ot-theme-switching'))
})

test('two switches in a row: the colours stay together until the last one ends', async () => {
  const page = fakePage({ viewTransitions: true })
  const options = { doc: page.doc, storage: fakeStorage(), frame: page.frame, reducedMotion: false }
  switchTheme('light', options)
  switchTheme('dark', options)

  // The browser gives up the first crossfade for the second: its end is not the end of the switch
  page.transitions.forEach((transition) => transition.update())
  page.transitions[0].finish()
  await page.transitions[0].finished
  await null
  assert.ok(page.classes.has('ot-theme-switching'), 'still switching')

  page.transitions[1].finish()
  await page.transitions[1].finished
  await null
  assert.ok(!page.classes.has('ot-theme-switching'))
  assert.equal(page.root.dataset.theme, 'dark')
})
