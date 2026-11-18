// The audit of the JS dependencies in the CI: any high or critical advisory fails it, except one
// accepted in .github/npm-audit-exceptions.json, with its reason, until its date.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync } from 'node:fs'
import { refused } from '../../.github/npm-audit.mjs'

// The shape of `npm audit --json` (npm 7 and later)
const report = {
  vulnerabilities: {
    picomatch: { severity: 'high', via: [{ source: 1, name: 'picomatch', title: 'ReDoS', url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf', severity: 'high' }] },
    vite: { severity: 'high', via: ['picomatch', 'fdir'] },
    lodash: { severity: 'critical', via: [{ source: 2, name: 'lodash', title: 'Prototype pollution', url: 'https://github.com/advisories/GHSA-aaaa-bbbb-cccc', severity: 'critical' }] },
    debug: { severity: 'moderate', via: [{ source: 3, name: 'debug', title: 'Slow', url: 'https://github.com/advisories/GHSA-dddd-eeee-ffff', severity: 'moderate' }] },
  },
}
const exception = { id: 'GHSA-fc5q-39xc-vjpf', package: 'picomatch', until: '2026-12-18', reason: 'build only' }

test('a high or critical advisory fails the audit', () => {
  assert.deepEqual(refused(report, [], '2026-11-18').map((a) => a.id).sort(), ['GHSA-aaaa-bbbb-cccc', 'GHSA-fc5q-39xc-vjpf'])
})

test('an advisory accepted until a date passes until then, and only that one', () => {
  assert.deepEqual(refused(report, [exception], '2026-11-18').map((a) => a.id), ['GHSA-aaaa-bbbb-cccc'])
  assert.deepEqual(refused(report, [exception], '2026-12-18').map((a) => a.id), ['GHSA-aaaa-bbbb-cccc'])
})

test('past its date, the advisory fails the audit again', () => {
  assert.ok(refused(report, [exception], '2026-12-19').some((a) => a.id === 'GHSA-fc5q-39xc-vjpf'))
})

test('a moderate advisory does not fail it, as npm audit --audit-level=high', () => {
  assert.ok(!refused(report, [], '2026-11-18').some((a) => a.id === 'GHSA-dddd-eeee-ffff'))
})

test('an exception names the package it accepts, its reason and a date', () => {
  const exceptions = JSON.parse(readFileSync(new URL('../../.github/npm-audit-exceptions.json', import.meta.url), 'utf8'))
  for (const e of exceptions) {
    assert.match(e.id, /^GHSA-[\w]{4}-[\w]{4}-[\w]{4}$/)
    assert.ok(e.package && e.reason && e.reason.length > 40, `${e.id}: a package and a reason`)
    assert.match(e.until, /^\d{4}-\d{2}-\d{2}$/)
    // An exception is for a while, not for good
    assert.ok(new Date(e.until) - new Date('2026-11-18') <= 62 * 86400e3, `${e.id}: two months at most`)
  }
})
