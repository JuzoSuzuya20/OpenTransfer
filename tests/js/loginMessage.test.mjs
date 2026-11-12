// The message under the brand on the sign-in screen: the one shipped with the app (stored in
// English at installation) or none shows in the reader's language; what an admin wrote stays.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loginMessageFor } from '../../resources/js/lib/loginMessage.js'

const FRENCH = 'Connectez-vous pour commencer.'

test('the message stored at installation reads in the reader language', () => {
  assert.equal(loginMessageFor('Login to your account to get started.', FRENCH), FRENCH)
})

test('no message reads in the reader language', () => {
  assert.equal(loginMessageFor('', FRENCH), FRENCH)
  assert.equal(loginMessageFor('   ', FRENCH), FRENCH)
  assert.equal(loginMessageFor(null, FRENCH), FRENCH)
})

test('a message written by an admin is shown as written', () => {
  assert.equal(loginMessageFor('Bienvenue sur le portail de la mairie.', FRENCH), 'Bienvenue sur le portail de la mairie.')
})
