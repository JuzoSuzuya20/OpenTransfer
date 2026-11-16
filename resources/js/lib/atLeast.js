// A wait shown by a spinning icon lasts at least one of its turns: a refresh that answers in a
// blink would stop it half a turn in, and the button would seem to flicker. A slower answer is not
// delayed any further; a failure waits the same, then fails.
const later = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

export const atLeast = async (ms, work, { now = () => Date.now(), wait = later } = {}) => {
  const start = now()
  const settle = async () => {
    const left = ms - (now() - start)
    if (left > 0) await wait(left)
  }
  try {
    const value = await work
    await settle()
    return value
  } catch (error) {
    await settle()
    throw error
  }
}
