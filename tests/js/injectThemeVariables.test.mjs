// The branding settings preview a theme by writing its variables on <body>, while the
// active theme keeps its own on :root. A previewed theme without a page background must
// show its panel background there: neither the page of the theme previewed before, nor
// the one <body> would inherit from the active theme.
import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import injectThemeVariables from '../../resources/js/lib/injectThemeVariables.js'

const baseTheme = () => ({
  links: { default: '#111', hover: '#222', active: '#333', disabled: '#444' },
  buttons: {
    primary: {
      default: { background: '#111', text: '#fff' },
      hover: { background: '#111', text: '#fff' },
      active: { background: '#111', text: '#fff' },
      disabled: { background: '#111', text: '#fff' }
    },
    secondary: {
      default: { background: '#111', text: '#fff' },
      hover: { background: '#111', text: '#fff' },
      active: { background: '#111', text: '#fff' },
      disabled: { background: '#111', text: '#fff' }
    }
  }
})

let properties

beforeEach(() => {
  properties = new Map()
  globalThis.document = {
    querySelector: () => ({
      style: {
        setProperty: (name, value) => properties.set(name, value),
        removeProperty: (name) => properties.delete(name)
      }
    })
  }
})

afterEach(() => {
  delete globalThis.document
})

test('the page background of the theme is written on the page', () => {
  injectThemeVariables('body', { ...baseTheme(), page: { background: '#161616' } })

  assert.equal(properties.get('--page-background-color'), '#161616')
})

test('a theme without page background puts its panel background behind the page', () => {
  injectThemeVariables('body', { ...baseTheme(), page: { background: '#161616' } })
  injectThemeVariables('body', baseTheme())

  assert.equal(properties.get('--page-background-color'), 'var(--panel-background-color)')
})

test('the heading colour of the theme is written on the page', () => {
  injectThemeVariables('body', { ...baseTheme(), page: { background: '#161616', heading: '#ffffff' } })

  assert.equal(properties.get('--heading-color'), '#ffffff')
})

test('a theme without heading colour writes its headings in the text colour', () => {
  injectThemeVariables('body', { ...baseTheme(), page: { background: '#161616', heading: '#ffffff' } })
  injectThemeVariables('body', baseTheme())

  assert.equal(properties.get('--heading-color'), 'var(--panel-text-color)')
})
