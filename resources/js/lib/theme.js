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
