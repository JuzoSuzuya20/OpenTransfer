// An upload left unfinished is offered again to the person who started it, for a week. The
// browser keeps it (file names, the name and message of the share): on a computer shared by
// several accounts, nobody else sees it.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { interruptedUploadFor } from '../../resources/js/lib/interruptedUpload.js'

const now = Date.UTC(2026, 10, 16, 12)
const state = { userId: 7, timestamp: now - 3600 * 1000, files: [{ name: 'devis.pdf' }] }

test('the person who started it sees it again', () => {
  assert.equal(interruptedUploadFor(state, 7, now), state)
  assert.equal(interruptedUploadFor(state, '7', now), state)
})

test('another account does not', () => {
  assert.equal(interruptedUploadFor(state, 8, now), null)
  assert.equal(interruptedUploadFor(state, null, now), null)
})

test('an upload kept before it was tied to an account is not shown', () => {
  assert.equal(interruptedUploadFor({ timestamp: now, files: [] }, 7, now), null)
})

test('after a week it is gone', () => {
  assert.equal(interruptedUploadFor({ ...state, timestamp: now - 8 * 24 * 3600 * 1000 }, 7, now), null)
})

test('what is not an upload is ignored', () => {
  assert.equal(interruptedUploadFor(null, 7, now), null)
  assert.equal(interruptedUploadFor('texte', 7, now), null)
})
