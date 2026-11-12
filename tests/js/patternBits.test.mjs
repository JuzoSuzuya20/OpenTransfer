// The admin screen says how much chance a link pattern carries, with the reckoning the server
// applies before saving it (PatternGenerator::bits): under 45 bits, links could be guessed.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { patternBits, MIN_PATTERN_BITS } from '../../resources/js/lib/patternBits.js'

const close = (actual, expected) => assert.ok(Math.abs(actual - expected) < 0.01, `${actual} is not ${expected}`)

test('each random token carries the choice it draws from', () => {
  close(patternBits('******'), 6 * Math.log2(62))
  close(patternBits('###'), 3 * Math.log2(10))
  close(patternBits('Aa'), 2 * Math.log2(26))
  close(patternBits('XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX'), 128)
})

test('literal characters and escapes carry none, a class carries its choices', () => {
  close(patternBits('doc-\\#\\*'), 0)
  close(patternBits('[ABC][0-9]'), Math.log2(3) + Math.log2(10))
  close(patternBits('[a-z0-9]'), Math.log2(36))
})

test('eight alphanumeric characters are the shortest strong pattern', () => {
  assert.equal(MIN_PATTERN_BITS, 45)
  assert.ok(patternBits('********') >= MIN_PATTERN_BITS)
  assert.ok(patternBits('*******') < MIN_PATTERN_BITS)
})
