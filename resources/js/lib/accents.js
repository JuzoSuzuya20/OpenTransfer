// The main colour of the instance (Appearance), among a few tuned for both themes. accents.json is
// read by the server too (App\Support\Accents): the page arrives in its colour, and the emails take
// it. The default colour is the one of the styles themselves: nothing to override.
import ACCENTS from './accents.json' with { type: 'json' }

export { ACCENTS }
export const DEFAULT_ACCENT = 'indigo'

const block = (vars) => Object.entries(vars).map(([name, value]) => `--ot-${name}:${value};`).join('')

// The same rules as the tokens of style.scss: the light ones, then the dark ones for the system
// and for a dark choice (lib/theme)
export const accentCss = (id) => {
  const accent = id !== DEFAULT_ACCENT ? ACCENTS[id] : null
  if (!accent) return ''
  const dark = block(accent.dark)
  return `:root{${block(accent.light)}}@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){${dark}}}:root[data-theme="dark"]{${dark}}`
}

// Shown at once in the page (a choice being tried in Appearance)
export const applyAccent = (id, doc = globalThis.document) => {
  if (!doc) return
  let style = doc.getElementById('ot-accent')
  if (!style) {
    style = doc.createElement('style')
    style.id = 'ot-accent'
    doc.head.appendChild(style)
  }
  style.textContent = accentCss(id)
}
