// The notifications (lib/toasts.js): how long each stays, the same message once, three at most,
// the countdown held while the pointer or the focus is on them, every message read out.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createToasts, DURATIONS, MAX_TOASTS } from '../../resources/js/lib/toasts.js'

// A clock of its own: timers run when the test moves the time on
const fakeClock = () => {
  let time = 0
  let next = 1
  const timers = new Map()
  return {
    now: () => time,
    setTimer: (callback, delay) => {
      const id = next++
      timers.set(id, { at: time + delay, callback })
      return id
    },
    clearTimer: (id) => timers.delete(id),
    advance(ms) {
      const until = time + ms
      for (;;) {
        const due = [...timers.entries()].filter(([, t]) => t.at <= until).sort((a, b) => a[1].at - b[1].at)[0]
        if (!due) break
        timers.delete(due[0])
        time = due[1].at
        due[1].callback()
      }
      time = until
    }
  }
}
const setup = () => {
  const clock = fakeClock()
  return { clock, toasts: createToasts(clock) }
}

test('a confirmation leaves after its time, an error stays longer', () => {
  const { clock, toasts } = setup()
  toasts.success('Saved')
  toasts.error('Not saved')
  assert.ok(DURATIONS.error > DURATIONS.success)

  clock.advance(DURATIONS.success)
  assert.deepEqual(toasts.items.map((t) => t.message), ['Not saved'])
  clock.advance(DURATIONS.error - DURATIONS.success)
  assert.equal(toasts.items.length, 0)
})

test('the countdown holds while the pointer or the focus is on the toasts', () => {
  const { clock, toasts } = setup()
  toasts.success('Saved')
  clock.advance(DURATIONS.success - 1000)

  toasts.pause()
  clock.advance(60_000)
  assert.equal(toasts.items.length, 1, 'held')

  toasts.resume()
  clock.advance(999)
  assert.equal(toasts.items.length, 1, 'what was left, not a new full time')
  clock.advance(1)
  assert.equal(toasts.items.length, 0)
})

test('the same message twice is one toast, counted, its time started again', () => {
  const { clock, toasts } = setup()
  toasts.error('Network error')
  clock.advance(DURATIONS.error - 100)
  toasts.error('Network error')

  assert.equal(toasts.items.length, 1)
  assert.equal(toasts.items[0].count, 2)
  clock.advance(DURATIONS.error - 1)
  assert.equal(toasts.items.length, 1)
  clock.advance(1)
  assert.equal(toasts.items.length, 0)
})

test('three at most: the oldest makes room', () => {
  const { toasts } = setup()
  assert.equal(MAX_TOASTS, 3)
  for (const message of ['one', 'two', 'three', 'four']) toasts.info(message)

  assert.deepEqual(toasts.items.map((t) => t.message), ['two', 'three', 'four'])
})

test('closing one is at once, and clears its countdown', () => {
  const { clock, toasts } = setup()
  const id = toasts.warning('Careful')
  toasts.dismiss(id)
  assert.equal(toasts.items.length, 0)
  clock.advance(60_000)
  assert.equal(toasts.items.length, 0)
})

test('every message is read out, an error at once', () => {
  const { toasts } = setup()
  toasts.success('Saved')
  assert.equal(toasts.live.polite, 'Saved')
  toasts.error('Not saved')
  assert.equal(toasts.live.assertive, 'Not saved')
  const before = toasts.live.seq
  toasts.error('Not saved')
  assert.ok(toasts.live.seq > before, 'the same message is read again')
})

test('a message is text, never markup', () => {
  const { toasts } = setup()
  toasts.info(42)
  assert.equal(toasts.items[0].message, '42')
  assert.equal(toasts.items[0].type, 'info')
})

test('the last one gone while held: the next one counts down all the same', () => {
  const { clock, toasts } = setup()
  const id = toasts.success('Saved')
  toasts.pause()
  // Closed under the pointer: the stack is empty, no "pointer left" ever comes
  toasts.dismiss(id)

  toasts.success('Saved again')
  clock.advance(DURATIONS.success)
  assert.equal(toasts.items.length, 0)
})

test('an error is read without reading the last confirmation again', () => {
  const { toasts } = setup()
  toasts.success('Saved')
  const polite = toasts.live.politeSeq
  toasts.error('Not saved')
  assert.equal(toasts.live.politeSeq, polite)
  assert.equal(toasts.live.assertiveSeq, 1)
})
