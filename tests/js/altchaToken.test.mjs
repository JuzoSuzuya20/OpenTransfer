// The anti-robot proof is solved ahead, as soon as a form that needs it shows: the person sees it
// pass, and sending does not wait. Each proof is spent once, and lasts less than its challenge.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createAltchaToken } from '../../resources/js/lib/altchaToken.js'

const counting = () => {
  let count = 0
  return { solve: async () => `proof-${++count}`, solved: () => count }
}

test('a proof solved ahead is the one the next sending takes', async () => {
  const { solve, solved } = counting()
  const token = createAltchaToken({ solve })

  await token.prepare()
  assert.equal(await token.take(), 'proof-1')
  assert.equal(solved(), 1)
})

test('a proof is spent once: the next sending solves another', async () => {
  const { solve } = counting()
  const token = createAltchaToken({ solve })

  assert.equal(await token.take(), 'proof-1')
  assert.equal(await token.take(), 'proof-2')
})

test('preparing twice solves once', async () => {
  const { solve, solved } = counting()
  const token = createAltchaToken({ solve })

  await Promise.all([token.prepare(), token.prepare()])
  assert.equal(solved(), 1)
})

test('a proof kept too long is solved again before it is sent', async () => {
  const { solve } = counting()
  let clock = 0
  const token = createAltchaToken({ solve, now: () => clock, lifetime: 1000 })

  await token.prepare()
  clock = 1001
  assert.equal(await token.take(), 'proof-2')
})

test('the state goes from verifying to verified, and back to idle once spent', async () => {
  const states = []
  const token = createAltchaToken({ solve: async () => 'proof', onState: (state) => states.push(state) })

  await token.prepare()
  await token.take()
  assert.deepEqual(states, ['verifying', 'verified', 'idle'])
})

test('a failed proof can be tried again', async () => {
  const states = []
  let fail = true
  const token = createAltchaToken({
    solve: async () => {
      if (fail) throw new Error('offline')
      return 'proof'
    },
    onState: (state) => states.push(state)
  })

  await assert.rejects(token.prepare())
  fail = false
  assert.equal(await token.take(), 'proof')
  assert.deepEqual(states, ['verifying', 'failed', 'verifying', 'verified', 'idle'])
})
