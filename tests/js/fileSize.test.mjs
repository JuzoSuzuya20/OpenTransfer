// Sizes read in the reader's language: its units (Mo in French), its decimal separator, as many
// decimals as the size needs, a no-break space before the unit.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formatFileSize } from '../../resources/js/lib/fileSize.js'

const NBSP = ' '
const MB = 1024 * 1024
const GB = 1024 * MB

test('French reads octets, Ko, Mo, Go with a comma', () => {
  assert.equal(formatFileSize(486 * MB, 'fr'), `486${NBSP}Mo`)
  assert.equal(formatFileSize(1.35 * GB, 'fr'), `1,35${NBSP}Go`)
  assert.equal(formatFileSize(512, 'fr'), `512${NBSP}octets`)
})

test('English reads bytes, KB, MB, GB with a point', () => {
  assert.equal(formatFileSize(612 * MB, 'en'), `612${NBSP}MB`)
  assert.equal(formatFileSize(1536, 'en'), `1.5${NBSP}KB`)
  assert.equal(formatFileSize(0, 'en'), `0${NBSP}bytes`)
})

test('the decimals follow the size: two under 10, one under 100, none above', () => {
  assert.equal(formatFileSize(2.5 * GB, 'de'), `2,5${NBSP}GB`)
  assert.equal(formatFileSize(48.25 * MB, 'en'), `48.3${NBSP}MB`)
  assert.equal(formatFileSize(280.4 * MB, 'en'), `280${NBSP}MB`)
})

test('an unknown language reads as English', () => {
  assert.equal(formatFileSize(3 * MB, 'xx'), `3${NBSP}MB`)
})
