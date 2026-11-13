// "Delete expired shares" runs in the background. The page follows it: a share of the batch is
// done once the list no longer has it, or has it as deleted (with "show deleted shares" on).
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { pruneProgress, sharesToDelete } from '../../resources/js/lib/pruneProgress.js'

test('the batch is the expired shares not deleted yet', () => {
  const returned = [{ id: 1, status: 'ready' }, { id: 2, status: 'deleted' }, { id: 3, status: 'failed' }]

  assert.deepEqual(sharesToDelete(returned), [1, 3])
})

test('a share is done once it left the list or shows as deleted', () => {
  const batch = [1, 3, 5]

  assert.deepEqual(pruneProgress(batch, [{ id: 1, status: 'ready' }, { id: 3, status: 'ready' }, { id: 5, status: 'ready' }]), { done: 0, total: 3, over: false })
  assert.deepEqual(pruneProgress(batch, [{ id: 3, status: 'deleted' }, { id: 5, status: 'ready' }]), { done: 2, total: 3, over: false })
  assert.deepEqual(pruneProgress(batch, []), { done: 3, total: 3, over: true })
})

test('an empty batch is over at once', () => {
  assert.deepEqual(pruneProgress([], [{ id: 1, status: 'ready' }]), { done: 0, total: 0, over: true })
})
