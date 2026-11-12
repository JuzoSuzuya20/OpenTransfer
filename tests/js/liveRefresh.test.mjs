// The lists of shares stay current while their page is open: reloaded on a timer while the tab
// is visible, at once when the person comes back to it, never twice at the same time.
import assert from 'node:assert/strict'
import { test, mock } from 'node:test'
import { startLiveRefresh } from '../../resources/js/lib/liveRefresh.js'

// One interval after the other, letting each reload finish (as time does)
const advance = async (intervals, ms = 20000) => {
  for (let i = 0; i < intervals; i++) {
    mock.timers.tick(ms)
    await new Promise((resolve) => setImmediate(resolve))
  }
}

const fakeDocument = (state = 'visible') => {
  const doc = new EventTarget()
  doc.visibilityState = state
  doc.show = (next) => {
    doc.visibilityState = next
    doc.dispatchEvent(new Event('visibilitychange'))
  }
  return doc
}

test('the list reloads on its interval while the tab is visible', async (t) => {
  mock.timers.enable({ apis: ['setInterval'] })
  t.after(() => mock.timers.reset())
  let loads = 0
  const stop = startLiveRefresh(() => loads++, { interval: 20000, doc: fakeDocument() })
  await advance(3)
  assert.equal(loads, 3)
  stop()
})

test('a hidden tab does not reload, and reloads at once when shown again', async (t) => {
  mock.timers.enable({ apis: ['setInterval'] })
  t.after(() => mock.timers.reset())
  let loads = 0
  const doc = fakeDocument()
  const stop = startLiveRefresh(() => loads++, { interval: 20000, doc })
  doc.show('hidden')
  await advance(3)
  assert.equal(loads, 0)
  doc.show('visible')
  assert.equal(loads, 1)
  stop()
})

test('a reload still running is not started again', async (t) => {
  mock.timers.enable({ apis: ['setInterval'] })
  t.after(() => mock.timers.reset())
  let loads = 0
  let finish
  const load = () => {
    loads++
    return new Promise((resolve) => (finish = resolve))
  }
  const stop = startLiveRefresh(load, { interval: 20000, doc: fakeDocument() })
  await advance(2)
  assert.equal(loads, 1)
  finish()
  await new Promise((resolve) => setImmediate(resolve))
  await advance(1)
  assert.equal(loads, 2)
  stop()
})

test('once stopped, nothing reloads any more', async (t) => {
  mock.timers.enable({ apis: ['setInterval'] })
  t.after(() => mock.timers.reset())
  let loads = 0
  const doc = fakeDocument()
  const stop = startLiveRefresh(() => loads++, { interval: 20000, doc })
  stop()
  await advance(3)
  doc.show('visible')
  assert.equal(loads, 0)
})
