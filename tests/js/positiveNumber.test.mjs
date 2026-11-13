// A number field of the administration can be cleared, and then saves an empty value. The send
// screen reads it as "no limit" (the longest time online) or the shipped default, never as zero.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { positiveNumber } from '../../resources/js/lib/positiveNumber.js'

test('a number above zero is kept', () => {
  assert.equal(positiveNumber(10), 10)
  assert.equal(positiveNumber('7'), 7)
  assert.equal(positiveNumber(2.5), 2.5)
})

test('an empty, zero, negative or unreadable value gives the fallback', () => {
  for (const value of ['', null, undefined, 0, '0', -3, 'abc']) {
    assert.equal(positiveNumber(value, 7), 7, String(value))
  }
  assert.equal(positiveNumber('', null), null)
})
