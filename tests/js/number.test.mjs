// The − and + of the number fields (expiry, sizes, logo width): one increment at a time, within
// the bounds of the field, starting from something sensible when the field is empty.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { stepValue } from '../../resources/js/lib/number.js'

test('a press moves the value by its increment', () => {
  assert.equal(stepValue(7, 1, { increment: 1 }), 8)
  assert.equal(stepValue(100, -1, { increment: 10 }), 90)
})

test('the value stops at the bounds of the field', () => {
  assert.equal(stepValue(10, 1, { min: 1, max: 10 }), 10)
  assert.equal(stepValue(1, -1, { min: 1, max: 10 }), 1)
  assert.equal(stepValue(95, 1, { max: 100, increment: 10 }), 100)
})

test('an empty field starts from its minimum, or from one increment', () => {
  assert.equal(stepValue('', 1, { min: 1 }), 1)
  assert.equal(stepValue(null, -1, { min: 1000, increment: 10000 }), 1000)
  assert.equal(stepValue('', 1, { increment: 10 }), 10)
  assert.equal(stepValue('', -1, {}), 0)
})

test('a value typed as text steps as a number', () => {
  assert.equal(stepValue('41', 1, {}), 42)
})
