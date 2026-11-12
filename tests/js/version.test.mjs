// The About page links the running version to what it is on GitHub: a release for a tagged
// version, the commit for a build of main in between two releases.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { versionUrl } from '../../resources/js/lib/version.js'

const REPO = 'https://github.com/JuzoSuzuya20/OpenTransfer'

test('a released version links to its release', () => {
  assert.equal(versionUrl('1.0.0'), `${REPO}/releases/tag/v1.0.0`)
})

test('a build after a release links to its commit', () => {
  assert.equal(versionUrl('1.0.0-3-g096bfb7'), `${REPO}/commit/096bfb7`)
})

test('a build before any release links to its commit', () => {
  assert.equal(versionUrl('096bfb7'), `${REPO}/commit/096bfb7`)
})

test('an unknown version links nowhere', () => {
  assert.equal(versionUrl('unknown'), null)
  assert.equal(versionUrl('latest'), null)
  assert.equal(versionUrl(''), null)
})
