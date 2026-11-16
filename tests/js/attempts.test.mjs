// A wrong password says the usual sentence the first time, then how many tries remain, then how
// long to wait: the sign-in, the password of a share, the password of a file request (four tries)
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { attemptsNotice } from '../../resources/js/lib/attempts.js'

test('the first miss only says the password is wrong', () => {
  assert.deepEqual(attemptsNotice({ attemptsLeft: 3 }), { kind: 'wrong' })
})

test('the next misses say how many tries remain', () => {
  assert.deepEqual(attemptsNotice({ attemptsLeft: 2 }), { kind: 'left', count: 2 })
  assert.deepEqual(attemptsNotice({ attemptsLeft: 1 }), { kind: 'left', count: 1 })
})

test('the last miss and the refusals after it say how long to wait, in whole minutes', () => {
  assert.deepEqual(attemptsNotice({ attemptsLeft: 0, retryAfter: 300 }), { kind: 'locked', minutes: 5 })
  assert.deepEqual(attemptsNotice({ retryAfter: 59 }), { kind: 'locked', minutes: 1 })
  assert.deepEqual(attemptsNotice({ retryAfter: 61 }), { kind: 'locked', minutes: 2 })
  assert.deepEqual(attemptsNotice({ locked: true }), { kind: 'locked', minutes: 1 })
})

test('a refusal without a count (an older server) only says the password is wrong', () => {
  assert.deepEqual(attemptsNotice({}), { kind: 'wrong' })
  assert.deepEqual(attemptsNotice({ attemptsLeft: '2' }), { kind: 'left', count: 2 })
})
