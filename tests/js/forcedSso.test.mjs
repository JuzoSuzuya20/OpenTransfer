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

// --- The page of a forced provider, and the moment it leads to it ---------------------------------
import { afterTheSessionSettles, forcedSsoOnly, forcedProviderId } from '../../resources/js/lib/forcedSso.js'
import { settleWithin } from '../../resources/js/lib/settleWithin.js'

// Vue shows the sign-in page (the "shown" of auth.vue) only once it rendered again, after the end of
// the wait of the session: decided at once, the page still read "not shown" and never led anywhere
test('the forced provider is decided once the page shows the sign-in again', async () => {
  let shown = false
  let rendered = Promise.resolve()
  // Like Vue: a change of state renders on a later microtask, which nextTick waits for
  const endTheWait = () => { rendered = Promise.resolve().then(() => { shown = true }) }
  const nextTick = () => rendered
  const restored = Promise.resolve()
  settleWithin(restored, 4000, endTheWait, { setTimer: () => 0, clearTimer: () => {} })

  let readAtOnce
  const atOnce = restored.finally(() => { readAtOnce = shown })
  let readOnceRendered
  await afterTheSessionSettles(restored, nextTick, () => { readOnceRendered = shown })
  await atOnce

  assert.equal(readAtOnce, false, 'what the page read before')
  assert.equal(readOnceRendered, true)
})

test('a session that failed to renew leads too', async () => {
  let led = false
  await afterTheSessionSettles(Promise.reject(new Error('expired')), () => Promise.resolve(), () => { led = true })
  assert.equal(led, true)
})

test('with a forced provider, the sign-in page shows its button alone', () => {
  assert.equal(forcedSsoOnly({ url, path: '/', search: '' }), true)
  // Not led to it (a sign-out just made, a message of the server): its button alone all the same
  assert.equal(forcedSsoOnly({ url, path: '/', search: '', signedOut: true, flash: true }), true)
})

test('the form of local accounts stays where it is needed', () => {
  assert.equal(forcedSsoOnly({ url: null, path: '/', search: '' }), false)
  for (const search of ['?noAUTO', '?invite_token=x', '?invite_id=3']) {
    assert.equal(forcedSsoOnly({ url, path: '/', search }), false, search)
  }
  assert.equal(forcedSsoOnly({ url, path: '/reset-password/abc', search: '' }), false)
})

test('the forced provider is read from its address', () => {
  assert.equal(forcedProviderId(url), 3)
  assert.equal(forcedProviderId(null), null)
  assert.equal(forcedProviderId('/elsewhere'), null)
})
