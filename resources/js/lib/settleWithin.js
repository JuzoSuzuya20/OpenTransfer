// Calls done() once: when the promise settles, either way, or after ms if it never does
export const settleWithin = (promise, ms, done, { setTimer = setTimeout, clearTimer = clearTimeout } = {}) => {
  let finished = false
  const finish = () => {
    if (finished) return
    finished = true
    clearTimer(timer)
    done()
  }
  const timer = setTimer(finish, ms)
  Promise.resolve(promise).then(finish, finish)
}
