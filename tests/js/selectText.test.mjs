// The value shown in a dropdown list (OtSelect) cuts what goes beyond its box, for the ellipsis.
// Buttons have a line height of 1: the tails of g, j, p, q, y went beyond it and were cut
// ("English", "Chaque jour"). Its own line height must leave them room.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync } from 'node:fs'

const css = readFileSync(new URL('../../resources/js/styles/_select.scss', import.meta.url), 'utf8')

test('the value of a dropdown list leaves room for the tails of letters', () => {
  const block = css.match(/\.ot-select-value\s*\{([^}]*)\}/)
  assert.ok(block, '.ot-select-value is styled')
  const lineHeight = block[1].match(/line-height:\s*([\d.]+)\s*;/)
  assert.ok(lineHeight, 'it sets its own line height')
  assert.ok(Number(lineHeight[1]) >= 1.3, `line height ${lineHeight[1]} is enough for g, j, p, q, y`)
})
