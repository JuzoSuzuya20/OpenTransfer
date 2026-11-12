// The pages a signed-in person may open next are fetched once the browser is idle: never in the
// way of what the page is doing, and still fetched where the browser cannot tell (Safari).
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { whenIdle } from '../../resources/js/lib/idle.js'

test('the work waits for the browser to be idle', () => {
  const calls = []
  const win = { requestIdleCallback: (callback, options) => calls.push({ callback, options }), setTimeout: () => assert.fail('no timer') }
  let done = false
  whenIdle(() => (done = true), { win })

  assert.equal(done, false)
  assert.equal(calls.length, 1)
  assert.ok(calls[0].options.timeout > 0, 'a busy page still gets its pages in the end')
  calls[0].callback()
  assert.equal(done, true)
})

test('without idle callbacks the work runs after a short delay', () => {
  const timers = []
  const win = { setTimeout: (callback, delay) => timers.push({ callback, delay }) }
  let done = false
  whenIdle(() => (done = true), { win })

  assert.equal(done, false)
  assert.equal(timers.length, 1)
  timers[0].callback()
  assert.equal(done, true)
})
