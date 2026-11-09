// Passes WebAuthn options and credentials between the server (JSON, base64url)
// and navigator.credentials (ArrayBuffers).

const fromBase64Url = (value) => {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4)
  return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0)).buffer
}

const toBase64Url = (buffer) => {
  if (!buffer) return null
  const binary = String.fromCharCode(...new Uint8Array(buffer))
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

const descriptors = (list = []) => list.map((item) => ({ ...item, id: fromBase64Url(item.id) }))

export const passkeysSupported = () => !!window.PublicKeyCredential && !!navigator.credentials

// The user dismissed the browser's dialog, or let it time out
export const passkeyCancelled = (error) => error?.name === 'NotAllowedError' || error?.name === 'AbortError'

export const createPasskey = async (options) => {
  const credential = await navigator.credentials.create({
    publicKey: {
      ...options,
      challenge: fromBase64Url(options.challenge),
      user: { ...options.user, id: fromBase64Url(options.user.id) },
      excludeCredentials: descriptors(options.excludeCredentials)
    }
  })

  return {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: toBase64Url(credential.response.clientDataJSON),
      attestationObject: toBase64Url(credential.response.attestationObject),
      transports: credential.response.getTransports ? credential.response.getTransports() : []
    }
  }
}

export const getPasskeyAssertion = async (options) => {
  const credential = await navigator.credentials.get({
    publicKey: {
      ...options,
      challenge: fromBase64Url(options.challenge),
      allowCredentials: descriptors(options.allowCredentials)
    }
  })

  return {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: toBase64Url(credential.response.clientDataJSON),
      authenticatorData: toBase64Url(credential.response.authenticatorData),
      signature: toBase64Url(credential.response.signature),
      userHandle: toBase64Url(credential.response.userHandle)
    }
  }
}
