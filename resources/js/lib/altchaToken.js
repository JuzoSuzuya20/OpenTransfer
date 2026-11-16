// The anti-robot proof (ALTCHA format, solved in the browser) is prepared ahead, as soon as a form
// that needs it shows: the person sees the check pass, and sending does not wait for it. Each
// proof is spent once, and is not kept longer than its challenge lasts (ten minutes on the server).
export const createAltchaToken = ({ solve, now = () => Date.now(), lifetime = 9 * 60 * 1000, onState = () => {} }) => {
  let ready = null

  const prepare = () => {
    if (ready && now() - ready.at < lifetime) return ready.promise
    onState('verifying')
    const promise = solve().then(
      (payload) => {
        onState('verified')
        return payload
      },
      (error) => {
        ready = null
        onState('failed')
        throw error
      }
    )
    ready = { promise, at: now() }
    return promise
  }

  // The proof for one sending: the prepared one while it is fresh, else a new one
  const take = async () => {
    const payload = await prepare()
    ready = null
    onState('idle')
    return payload
  }

  return { prepare, take }
}
