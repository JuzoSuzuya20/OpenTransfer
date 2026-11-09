// A bug in this hash would lock everyone out once the anti-robot check is on:
// it is compared with the reference implementation, on the sizes where padding changes.
import assert from 'node:assert/strict'
import { createHash, randomBytes } from 'node:crypto'
import { test } from 'node:test'
import { sha256, solveChallenge } from '../../resources/js/proofOfWork.js'

const reference = (message) => createHash('sha256').update(message).digest('hex')

test('known vectors', () => {
  assert.equal(sha256(''), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
  assert.equal(sha256('abc'), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')
})

test('every length around the block boundaries matches the reference', () => {
  for (let length = 0; length <= 300; length++) {
    const message = randomBytes(length).toString('hex').slice(0, length)
    assert.equal(sha256(message), reference(message), `length ${length}`)
  }
})

test('text beyond ASCII is hashed as UTF-8', () => {
  assert.equal(sha256('déjà vu ñ 日本'), reference('déjà vu ñ 日本'))
})

test('a challenge in the format of the server is solved', async () => {
  const salt = 'abcdef?expires=1800000000'
  const challenge = reference(salt + '4242')

  const payload = await solveChallenge({ algorithm: 'SHA-256', challenge, maxnumber: 10000, salt, signature: 'signed' })

  assert.deepEqual(JSON.parse(atob(payload)), { algorithm: 'SHA-256', challenge, number: 4242, salt, signature: 'signed' })
})

test('the last number allowed is tried', async () => {
  const challenge = reference('salt' + '100')

  await solveChallenge({ algorithm: 'SHA-256', challenge, maxnumber: 100, salt: 'salt', signature: 's' })
})

test('a challenge without a solution ends in an error', async () => {
  await assert.rejects(solveChallenge({ algorithm: 'SHA-256', challenge: 'nothing', maxnumber: 100, salt: 'salt', signature: 's' }))
})

test('the default difficulty takes well under a second', async () => {
  const salt = 'abcdef?expires=1800000000'
  const started = performance.now()

  await solveChallenge({ algorithm: 'SHA-256', challenge: reference(salt + '100000'), maxnumber: 100000, salt, signature: 's' })

  assert.ok(performance.now() - started < 1000, `took ${Math.round(performance.now() - started)} ms`)
})
