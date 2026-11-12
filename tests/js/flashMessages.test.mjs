// After a round trip to the identity provider, the server says what happened in a sentence of
// its own (English). The screen shows it in the language of the reader when it knows it.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { flashMessageKey } from '../../resources/js/lib/flashMessages.js'

test('a sentence the app knows becomes the key of its translation', () => {
  assert.equal(flashMessageKey('This account is disabled'), 'flash.account_disabled')
  assert.equal(flashMessageKey('Account linked successfully'), 'flash.account_linked')
  assert.equal(
    flashMessageKey('Your identity provider has not verified your email address. Ask your administrator to verify it there, or link this account from your profile.'),
    'flash.email_not_verified'
  )
})

test('a sentence it does not know is shown as it came', () => {
  assert.equal(flashMessageKey('Something new from the server'), null)
  assert.equal(flashMessageKey(''), null)
})
