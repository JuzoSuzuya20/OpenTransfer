// The member who asks for files chooses how long the files they receive stay online: a few usual
// lengths, never past the longest a share may stay (the administration's limit, then a choice of
// its own), starting on the default lifetime of a share.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { keepFilesChoices } from '../../resources/js/lib/keepFilesDays.js'

test('the choices stop at the limit of the administration, which is one of them', () => {
  assert.deepEqual(keepFilesChoices({ max: 10, fallback: 7 }), { days: [1, 3, 7, 10], chosen: 7 })
})

test('without a limit every usual length is offered', () => {
  assert.deepEqual(keepFilesChoices({ max: '', fallback: 7 }), { days: [1, 3, 7, 14, 30, 60, 90], chosen: 7 })
})

test('a default past the limit starts on the limit', () => {
  assert.deepEqual(keepFilesChoices({ max: 5, fallback: 7 }), { days: [1, 3, 5], chosen: 5 })
})

test('a default of its own is one of the choices', () => {
  assert.deepEqual(keepFilesChoices({ max: null, fallback: 45 }).days, [1, 3, 7, 14, 30, 45, 60, 90])
})

test('an empty or zero default starts on a week', () => {
  assert.equal(keepFilesChoices({ max: 30, fallback: 0 }).chosen, 7)
  assert.equal(keepFilesChoices({ max: 30, fallback: '' }).chosen, 7)
})
