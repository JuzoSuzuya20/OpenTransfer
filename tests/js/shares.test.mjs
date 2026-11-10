// What the send screen and "My shares" say about shares: their state, the figures above the
// send form, the summary of the month and the grouping by week. Every figure comes from the shares.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { shareState, sendSummary, monthSummary, groupByWeek } from '../../resources/js/lib/shares.js'

// Wednesday 11 November 2026, 10:00 local time
const now = new Date(2026, 10, 11, 10, 0, 0)
const hours = (h) => new Date(now.getTime() + h * 3600 * 1000).toISOString()
const daysAgo = (d) => new Date(now.getTime() - d * 24 * 3600 * 1000).toISOString()

const share = (overrides = {}) => ({
  id: 1,
  status: 'complete',
  expires_at: hours(24 * 7),
  created_at: daysAgo(1),
  download_count: 0,
  download_limit: null,
  size: 1000,
  file_count: 1,
  shared_with_me: false,
  ...overrides
})

test('a share within its time and its downloads is active', () => {
  assert.equal(shareState(share(), now), 'active')
})

test('a share that ends within two days is ending', () => {
  assert.equal(shareState(share({ expires_at: hours(30) }), now), 'ending')
})

test('a share one download away from its limit is ending', () => {
  assert.equal(shareState(share({ download_limit: 5, download_count: 4 }), now), 'ending')
})

test('a share past its date is expired', () => {
  assert.equal(shareState(share({ expires_at: hours(-1) }), now), 'expired')
})

test('a share that reached its download limit is expired', () => {
  assert.equal(shareState(share({ download_limit: 5, download_count: 5 }), now), 'expired')
})

test('a deleted share stays deleted, a failed one failed', () => {
  assert.equal(shareState(share({ status: 'deleted' }), now), 'deleted')
  assert.equal(shareState(share({ status: 'failed' }), now), 'failed')
})

test('the send screen counts the shares still online and what they hold', () => {
  const summary = sendSummary(
    [
      share({ size: 400, download_count: 3 }),
      share({ size: 100, expires_at: hours(5) }),
      share({ size: 999, expires_at: hours(-2), download_count: 50 }),
      share({ size: 777, shared_with_me: true })
    ],
    now
  )

  assert.deepEqual(summary, { active: 2, endingWithinADay: 1, onlineBytes: 500, downloads: 3, neverOpened: 1 })
})

test('the month summary covers the shares created since the first of the month', () => {
  const summary = monthSummary(
    [
      share({ created_at: daysAgo(2), download_count: 4, size: 300 }),
      share({ created_at: daysAgo(9), download_count: 0, size: 200 }),
      share({ created_at: daysAgo(12), download_count: 9, size: 999 }),
      share({ created_at: daysAgo(3), shared_with_me: true, file_count: 6 })
    ],
    now
  )

  assert.deepEqual(summary, { created: 2, opened: 1, downloads: 4, bytes: 500, receivedFiles: 6 })
})

test('shares are grouped by week, then by month, newest first', () => {
  const groups = groupByWeek(
    [
      share({ id: 1, created_at: daysAgo(30) }),
      share({ id: 2, created_at: daysAgo(1) }),
      share({ id: 3, created_at: daysAgo(8) }),
      share({ id: 4, created_at: daysAgo(2) })
    ],
    now
  )

  assert.deepEqual(
    groups.map((group) => [group.kind, group.shares.map((item) => item.id)]),
    [
      ['this-week', [2, 4]],
      ['last-week', [3]],
      ['month', [1]]
    ]
  )
  assert.equal(groups[2].month.getMonth(), 9)
})

test('the week starts on Monday', () => {
  // Monday 9 November 2026 at 00:30 belongs to this week, Sunday the 8th to the last one
  const groups = groupByWeek(
    [share({ id: 1, created_at: new Date(2026, 10, 9, 0, 30).toISOString() }), share({ id: 2, created_at: new Date(2026, 10, 8, 23, 0).toISOString() })],
    now
  )

  assert.deepEqual(groups.map((group) => group.kind), ['this-week', 'last-week'])
})
