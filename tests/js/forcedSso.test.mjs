// With a forced identity provider (like the "forced" OpenID Connect of ITSM-NG), the sign-in page
// leads straight to it, except where that would trap someone: the form asked for (?noAUTO), a
// sign-out just made (the provider may still hold a session and sign straight back in), a message
// of the server (an SSO that failed would loop), a guest link, a password reset.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { forcedSsoTarget } from '../../resources/js/lib/forcedSso.js'

const url = '/auth/provider/3/login'

test('the sign-in page leads to the forced provider', () => {
  assert.equal(forcedSsoTarget({ url, path: '/', search: '' }), url)
  assert.equal(forcedSsoTarget({ url, path: '/admin/system', search: '' }), url)
})

test('nothing is forced without a provider', () => {
  assert.equal(forcedSsoTarget({ url: null, path: '/', search: '' }), null)
})

test('?noAUTO keeps the form, as in ITSM-NG', () => {
  for (const search of ['?noAUTO', '?noAUTO=1', '?noauto=1', '?x=1&noAUTO=1']) {
    assert.equal(forcedSsoTarget({ url, path: '/', search }), null, search)
  }
})

test('a sign-out just made shows the form once', () => {
  assert.equal(forcedSsoTarget({ url, path: '/', search: '', signedOut: true }), null)
})

test('a message of the server stays on the page', () => {
  assert.equal(forcedSsoTarget({ url, path: '/', search: '', flash: true }), null)
})

test('a guest link, an invite or a password reset is not taken away', () => {
  assert.equal(forcedSsoTarget({ url, path: '/', search: '?invite_token=abc' }), null)
  assert.equal(forcedSsoTarget({ url, path: '/', search: '?invite_id=4' }), null)
  assert.equal(forcedSsoTarget({ url, path: '/reset-password/abc', search: '?email=a%40b.fr' }), null)
})

test('nothing happens while the sign-in page is not the one shown', () => {
  assert.equal(forcedSsoTarget({ url, path: '/shares/abc', search: '', shown: false }), null)
})
