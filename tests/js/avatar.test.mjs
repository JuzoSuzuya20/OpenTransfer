// The avatar of an account is drawn in the browser, in the DiceBear "Line Face" style, from the seed
// the server gives: no request leaves for DiceBear, and the same seed always gives the same face.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { avatarDataUri } from '../../resources/js/lib/avatar.js'

test('an avatar is an SVG image the page can show', () => {
  assert.match(avatarDataUri('3f9a0c1d2e4b5a6c7d8e'), /^data:image\/svg\+xml/)
})

test('the same seed always gives the same face', () => {
  assert.equal(avatarDataUri('3f9a0c1d2e4b5a6c7d8e'), avatarDataUri('3f9a0c1d2e4b5a6c7d8e'))
})

test('two seeds give two faces', () => {
  assert.notEqual(avatarDataUri('3f9a0c1d2e4b5a6c7d8e'), avatarDataUri('0000aaaa1111bbbb2222'))
})

test('without a seed there is no avatar', () => {
  assert.equal(avatarDataUri(''), null)
  assert.equal(avatarDataUri(null), null)
})
