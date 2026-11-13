// Moving within the application scrolls the window: to the anchor an address names, or back to
// the top of a new page. A link may keep the window where it is: the tabs of the administration
// name a part with their anchor, they do not point at a place to scroll to.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { scrollAfterNavigation } from '../../resources/js/lib/navigationScroll.js'

test('an address with an anchor scrolls to it', () => {
  assert.deepEqual(scrollAfterNavigation({ hash: '#linked_accounts', changed: true }), { to: 'anchor', id: 'linked_accounts' })
  assert.deepEqual(scrollAfterNavigation({ hash: '#d%C3%A9j%C3%A0', changed: true }), { to: 'anchor', id: 'déjà' })
})

test('a new page without an anchor opens at its top, the same page stays put', () => {
  assert.deepEqual(scrollAfterNavigation({ hash: '', changed: true }), { to: 'top' })
  assert.deepEqual(scrollAfterNavigation({ hash: '', changed: false }), { to: 'none' })
})

test('a link that keeps the window never scrolls it', () => {
  assert.deepEqual(scrollAfterNavigation({ hash: '#shares', changed: true, keepScroll: true }), { to: 'none' })
  assert.deepEqual(scrollAfterNavigation({ hash: '', changed: true, keepScroll: true }), { to: 'none' })
})
