// A refresh answers in a blink: its spinning icon would stop half a turn in. The wait lasts at
// least one turn; a slower answer is not delayed any further.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { atLeast } from '../../resources/js/lib/atLeast.js'

const clock = () => {
  let now = 0
  const waits = []
  return {
    now: () => now,
    wait: (ms) => { waits.push(ms); now += ms; return Promise.resolve() },
    advance: (ms) => { now += ms },
    waits
  }
}

test('a quick answer waits out the rest of the turn', async () => {
  const c = clock()
  const value = await atLeast(700, (async () => { await null; c.advance(100); return 'ok' })(), c)
  assert.equal(value, 'ok')
  assert.deepEqual(c.waits, [600])
})

test('a slow answer is not delayed', async () => {
  const c = clock()
  await atLeast(700, (async () => { await null; c.advance(900); return 'ok' })(), c)
  assert.deepEqual(c.waits, [])
})

test('a failure waits the same, then fails', async () => {
  const c = clock()
  await assert.rejects(atLeast(700, (async () => { await null; c.advance(50); throw new Error('offline') })(), c), /offline/)
  assert.deepEqual(c.waits, [650])
})
