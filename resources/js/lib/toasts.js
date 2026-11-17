// The notifications of the application: a short line that confirms, informs, warns or tells what
// failed. How long each stays (an error longer), the same message once (counted, its time started
// again), three at most (the oldest makes room), the countdown held while the pointer or the focus
// is on them, and what screen readers read (an error at once). The display is ToastHost.vue; the
// clock and the reactivity are given, for the tests to run without a browser.
export const DURATIONS = { success: 4000, info: 5000, warning: 7000, error: 8000 }
export const MAX_TOASTS = 3
const TYPES = Object.keys(DURATIONS)

export const createToasts = ({
  now = () => Date.now(),
  setTimer = (callback, delay) => setTimeout(callback, delay),
  clearTimer = (id) => clearTimeout(id),
  wrap = (value) => value,
  max = MAX_TOASTS
} = {}) => {
  const items = wrap([])
  // What screen readers read: one channel for errors (at once), one for the rest
  const live = wrap({ polite: '', assertive: '', seq: 0, politeSeq: 0, assertiveSeq: 0 })
  const timers = new Map()
  let paused = false
  let nextId = 1

  const stop = (toast) => {
    if (timers.has(toast.id)) {
      clearTimer(timers.get(toast.id))
      timers.delete(toast.id)
    }
  }
  const run = (toast) => {
    stop(toast)
    if (paused || toast.timeout <= 0) return
    toast.startedAt = now()
    timers.set(toast.id, setTimer(() => dismiss(toast.id), toast.remaining))
  }
  const dismiss = (id) => {
    const index = items.findIndex((toast) => toast.id === id)
    if (index === -1) return
    stop(items[index])
    items.splice(index, 1)
    // Closed under the pointer: no "pointer left" comes for an empty stack, the next one counts down
    if (items.length === 0) paused = false
  }
  const announce = (type, message) => {
    live.seq++
    if (type === 'error') {
      live.assertive = message
      live.assertiveSeq++
    } else {
      live.polite = message
      live.politeSeq++
    }
  }

  const show = (type, message, { timeout } = {}) => {
    const kind = TYPES.includes(type) ? type : 'info'
    const text = String(message ?? '')
    const time = Number.isFinite(timeout) ? timeout : DURATIONS[kind]
    announce(kind, text)

    const same = items.find((toast) => toast.type === kind && toast.message === text)
    if (same) {
      same.count++
      same.timeout = time
      same.remaining = time
      run(same)
      return same.id
    }

    items.push({ id: nextId++, type: kind, message: text, count: 1, timeout: time, remaining: time, startedAt: now() })
    while (items.length > max) dismiss(items[0].id)
    run(items[items.length - 1])
    return items[items.length - 1].id
  }

  // The pointer or the focus on the toasts: every countdown holds, then goes on from where it was
  const pause = () => {
    if (paused) return
    paused = true
    for (const toast of items) {
      if (!timers.has(toast.id)) continue
      stop(toast)
      toast.remaining = Math.max(0, toast.remaining - (now() - toast.startedAt))
    }
  }
  const resume = () => {
    if (!paused) return
    paused = false
    for (const toast of items) run(toast)
  }
  const clear = () => {
    for (const toast of [...items]) dismiss(toast.id)
  }

  return {
    items,
    live,
    show,
    dismiss,
    pause,
    resume,
    clear,
    success: (message, options) => show('success', message, options),
    info: (message, options) => show('info', message, options),
    warning: (message, options) => show('warning', message, options),
    error: (message, options) => show('error', message, options)
  }
}
