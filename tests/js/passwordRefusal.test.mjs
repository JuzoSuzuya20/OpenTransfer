// A 401 that refuses a password (of a share) answers the request: it is not a session that ran
// out. Renewing the session and sending the request again counted each wrong password twice, and
// a visitor without a session saw no message at all.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { isPasswordRefusal } from '../../resources/js/lib/passwordRefusal.js'

test('a wrong or missing share password is an answer', () => {
  assert.equal(isPasswordRefusal({ code: 'invalid_password', message: 'Invalid password' }), true)
  assert.equal(isPasswordRefusal({ code: 'password_required' }), true)
})

test('a session that ran out is not', () => {
  assert.equal(isPasswordRefusal({ message: 'Unauthenticated.' }), false)
  assert.equal(isPasswordRefusal(null), false)
  assert.equal(isPasswordRefusal(undefined), false)
})
