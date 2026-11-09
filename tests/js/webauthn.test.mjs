// The contract between the JSON of the server and navigator.credentials: an encoding
// mistake here would break every passkey, and no test on the PHP side would see it.
// The options below are what web-auth/webauthn-lib really serializes.
import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { createPasskey, getPasskeyAssertion, passkeyCancelled, passkeysSupported } from '../../resources/js/webauthn.js'

const CREATE_OPTIONS = {
  challenge: '-_8AY2hhbA',
  timeout: 60000,
  rp: { id: 'localhost', name: 'OT' },
  user: { id: 'MQ', name: 'a@b.c', displayName: 'A' },
  pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
  authenticatorSelection: { userVerification: 'preferred', residentKey: 'preferred' },
  attestation: 'none',
  excludeCredentials: [{ type: 'public-key', id: 'AQL7_w' }]
}

const GET_OPTIONS = {
  challenge: '-_8AY2hhbA',
  timeout: 60000,
  rpId: 'localhost',
  allowCredentials: [{ type: 'public-key', id: 'AQL7_w' }],
  userVerification: 'preferred'
}

const CHALLENGE = [0xfb, 0xff, 0x00, 0x63, 0x68, 0x61, 0x6c]
const CREDENTIAL_ID = [0x01, 0x02, 0xfb, 0xff]

const buffer = (bytes) => new Uint8Array(bytes).buffer
const bytesOf = (value) => [...new Uint8Array(value)]

let received
const originalNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator')

const stubBrowser = (credential) => {
  received = null
  const answer = async (options) => {
    received = options.publicKey
    return credential
  }
  Object.defineProperty(globalThis, 'navigator', { value: { credentials: { create: answer, get: answer } }, configurable: true })
  globalThis.window = { PublicKeyCredential: class {} }
}

beforeEach(() => stubBrowser(null))

afterEach(() => {
  Object.defineProperty(globalThis, 'navigator', originalNavigator)
  delete globalThis.window
})

test('registration options reach the browser as bytes, the rest untouched', async () => {
  stubBrowser({ id: 'x', rawId: buffer(CREDENTIAL_ID), type: 'public-key', response: { clientDataJSON: buffer([1]), attestationObject: buffer([2]) } })

  await createPasskey(CREATE_OPTIONS)

  assert.deepEqual(bytesOf(received.challenge), CHALLENGE)
  assert.deepEqual(bytesOf(received.user.id), [0x31])
  assert.equal(received.user.name, 'a@b.c')
  assert.deepEqual(bytesOf(received.excludeCredentials[0].id), CREDENTIAL_ID)
  assert.equal(received.rp.id, 'localhost')
  assert.deepEqual(received.pubKeyCredParams, CREATE_OPTIONS.pubKeyCredParams)
})

test('a new passkey goes back to the server in base64url, without padding', async () => {
  stubBrowser({
    id: 'AQL7_w',
    rawId: buffer(CREDENTIAL_ID),
    type: 'public-key',
    response: { clientDataJSON: buffer(CHALLENGE), attestationObject: buffer([0xfb]), getTransports: () => ['internal'] }
  })

  assert.deepEqual(await createPasskey(CREATE_OPTIONS), {
    id: 'AQL7_w',
    rawId: 'AQL7_w',
    type: 'public-key',
    response: { clientDataJSON: '-_8AY2hhbA', attestationObject: '-w', transports: ['internal'] }
  })
})

test('browsers without getTransports are supported', async () => {
  stubBrowser({ id: 'x', rawId: buffer([1]), type: 'public-key', response: { clientDataJSON: buffer([1]), attestationObject: buffer([1]) } })

  assert.deepEqual((await createPasskey(CREATE_OPTIONS)).response.transports, [])
})

test('sign-in options reach the browser as bytes', async () => {
  stubBrowser({ id: 'x', rawId: buffer([1]), type: 'public-key', response: { clientDataJSON: buffer([1]), authenticatorData: buffer([1]), signature: buffer([1]), userHandle: null } })

  await getPasskeyAssertion(GET_OPTIONS)

  assert.deepEqual(bytesOf(received.challenge), CHALLENGE)
  assert.deepEqual(bytesOf(received.allowCredentials[0].id), CREDENTIAL_ID)
  assert.equal(received.rpId, 'localhost')
})

test('a signature goes back to the server in base64url', async () => {
  stubBrowser({
    id: 'AQL7_w',
    rawId: buffer(CREDENTIAL_ID),
    type: 'public-key',
    response: { clientDataJSON: buffer(CHALLENGE), authenticatorData: buffer([0xff]), signature: buffer([0xfb]), userHandle: buffer([0x31]) }
  })

  assert.deepEqual(await getPasskeyAssertion(GET_OPTIONS), {
    id: 'AQL7_w',
    rawId: 'AQL7_w',
    type: 'public-key',
    response: { clientDataJSON: '-_8AY2hhbA', authenticatorData: '_w', signature: '-w', userHandle: 'MQ' }
  })
})

test('no user handle stays null, as authenticators may not send one for a second factor', async () => {
  stubBrowser({ id: 'x', rawId: buffer([1]), type: 'public-key', response: { clientDataJSON: buffer([1]), authenticatorData: buffer([1]), signature: buffer([1]), userHandle: null } })

  assert.equal((await getPasskeyAssertion(GET_OPTIONS)).response.userHandle, null)
})

test('signing in without a password sends no list of keys', async () => {
  stubBrowser({ id: 'x', rawId: buffer([1]), type: 'public-key', response: { clientDataJSON: buffer([1]), authenticatorData: buffer([1]), signature: buffer([1]), userHandle: buffer([0x31]) } })
  const { allowCredentials, ...withoutList } = GET_OPTIONS

  await getPasskeyAssertion({ ...withoutList, userVerification: 'required' })

  assert.deepEqual(received.allowCredentials, [])
  assert.equal(received.userVerification, 'required')
})

test('support is detected from the browser', () => {
  assert.equal(passkeysSupported(), true)

  globalThis.window = {}

  assert.equal(passkeysSupported(), false)
})

test('a dismissed dialog is told apart from a real failure', () => {
  assert.equal(passkeyCancelled({ name: 'NotAllowedError' }), true)
  assert.equal(passkeyCancelled({ name: 'AbortError' }), true)
  assert.equal(passkeyCancelled({ name: 'InvalidStateError' }), false)
  assert.equal(passkeyCancelled(new Error('Network')), false)
  assert.equal(passkeyCancelled(undefined), false)
})
