// The administration saves each setting as text. An empty one goes out empty: sent as the text
// "null", the checks of the server read it as an entry and refused the whole page.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { settingValueAsText } from '../../resources/js/lib/settingValue.js'

test('an empty setting goes out empty', () => {
  assert.equal(settingValueAsText(null), '')
  assert.equal(settingValueAsText(undefined), '')
  assert.equal(settingValueAsText(''), '')
})

test('other values go out as the text they read', () => {
  assert.equal(settingValueAsText(true), 'true')
  assert.equal(settingValueAsText(false), 'false')
  assert.equal(settingValueAsText(10), '10')
  assert.equal(settingValueAsText('entreprise.fr'), 'entreprise.fr')
  assert.equal(settingValueAsText(['a', 'b']), 'a,b')
  assert.equal(settingValueAsText({ links: '#fff' }), '{"links":"#fff"}')
})
