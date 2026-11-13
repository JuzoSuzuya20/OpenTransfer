// On a reload the app first renews the session (the access token lived in memory): until that
// answer, it shows neither the sign-in screen nor a page. The wait ends once, when the renewal
// settles either way, or after a few seconds if the network never answers.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { settleWithin } from '../../resources/js/lib/settleWithin.js'

const fakeTimers = () => {
  const timers = []
  return {
    timers,
    setTimer: (callback, delay) => timers.push({ callback, delay, cleared: false }) - 1,
    clearTimer: (id) => (timers[id].cleared = true),
    fire: () => timers.filter((t) => !t.cleared).forEach((t) => t.callback())
  }
}
const tick = () => new Promise((resolve) => setImmediate(resolve))

test('the wait ends when the renewal succeeds', async () => {
  const clock = fakeTimers()
  let ended = 0
  settleWithin(Promise.resolve('signed in'), 4000, () => ended++, clock)

  await tick()
  assert.equal(ended, 1)
  assert.equal(clock.timers[0].cleared, true)
})

test('the wait ends when the renewal fails', async () => {
  const clock = fakeTimers()
  let ended = 0
  settleWithin(Promise.reject(new Error('no session')), 4000, () => ended++, clock)

  await tick()
  assert.equal(ended, 1)
})

test('a network that never answers does not leave the page empty', async () => {
  const clock = fakeTimers()
  let ended = 0
  let answer
  settleWithin(new Promise((resolve) => (answer = resolve)), 4000, () => ended++, clock)

  assert.equal(clock.timers[0].delay, 4000)
  clock.fire()
  assert.equal(ended, 1)

  // The late answer does not end it a second time
  answer('signed in')
  await tick()
  assert.equal(ended, 1)
})
