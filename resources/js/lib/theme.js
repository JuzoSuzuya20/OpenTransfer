// Light, dark, or the system (by default): chosen in the account menu, kept by the browser, set on
// the page as data-theme before it shows (public/theme.js does the same at the very start).
export const THEMES = ['system', 'light', 'dark']
const KEY = 'ot-theme'

export const storedTheme = (storage = globalThis.localStorage) => {
  try {
    const value = storage?.getItem(KEY)
    return THEMES.includes(value) ? value : 'system'
  } catch (error) {
    return 'system'
  }
}

export const applyTheme = (theme, { root = globalThis.document?.documentElement, storage = globalThis.localStorage } = {}) => {
  const choice = THEMES.includes(theme) ? theme : 'system'
  if (root) {
    if (choice === 'system') delete root.dataset.theme
    else root.dataset.theme = choice
  }
  try {
    if (choice === 'system') storage?.removeItem(KEY)
    else storage?.setItem(KEY, choice)
  } catch (error) {
    // No storage: the choice holds for this page only
  }
  return choice
}

// While the theme changes, no element fades its colour on its own (buttons and fields have their
// transitions): every colour changes at once, under one crossfade of the whole page where the
// browser can (style.scss, .ot-theme-switching and ::view-transition)
const SWITCHING = 'ot-theme-switching'
const lessMotion = () => Boolean(globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches)
const nextFrames = (frame, callback) => frame(() => frame(callback))
// Only the latest switch ends it: a switch right after another one cancels the crossfade before
let latestSwitch = 0

export const switchTheme = (
  theme,
  {
    doc = globalThis.document,
    storage = globalThis.localStorage,
    frame = globalThis.requestAnimationFrame?.bind(globalThis) ?? ((callback) => setTimeout(callback, 16)),
    reducedMotion = lessMotion()
  } = {}
) => {
  const choice = THEMES.includes(theme) ? theme : 'system'
  const root = doc?.documentElement
  const apply = () => applyTheme(choice, { root, storage })
  if (!root) return apply()

  root.classList.add(SWITCHING)
  const id = ++latestSwitch
  const settle = () => {
    if (id === latestSwitch) root.classList.remove(SWITCHING)
  }
  if (typeof doc.startViewTransition === 'function' && !reducedMotion) {
    const transition = doc.startViewTransition(apply)
    // Skipped (page hidden, another switch right after): the theme applies all the same
    transition.ready?.catch(() => {})
    transition.finished.then(settle, settle)
  } else {
    apply()
    nextFrames(frame, settle)
  }
  return choice
}

// The system turning dark or light while the theme follows it: every colour at once there too
export const followSystemTheme = ({
  doc = globalThis.document,
  query = globalThis.matchMedia?.('(prefers-color-scheme: dark)'),
  frame = globalThis.requestAnimationFrame?.bind(globalThis) ?? ((callback) => setTimeout(callback, 16))
} = {}) => {
  const root = doc?.documentElement
  query?.addEventListener?.('change', () => {
    if (!root || root.dataset.theme) return
    root.classList.add(SWITCHING)
    nextFrames(frame, () => root.classList.remove(SWITCHING))
  })
}
