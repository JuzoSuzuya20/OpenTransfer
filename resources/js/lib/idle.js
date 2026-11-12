// Runs work once the browser is idle, or after a short delay where it cannot tell (Safari)
export const whenIdle = (work, { win = globalThis, timeout = 2000 } = {}) => {
  if (typeof win.requestIdleCallback === 'function') {
    win.requestIdleCallback(() => work(), { timeout })
  } else {
    win.setTimeout(() => work(), 200)
  }
}
