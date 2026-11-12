// Keeps a list current while its page is open: reloaded every `interval` while the tab is
// visible, at once when the person comes back to it, and never twice at the same time.
// Returns the function that stops it (call it when the page goes away).
export const startLiveRefresh = (load, { interval = 20000, doc = document } = {}) => {
  let timer = null
  let running = false

  const reload = async () => {
    if (running) return
    running = true
    try {
      await load()
    } finally {
      running = false
    }
  }
  const stopTimer = () => {
    clearInterval(timer)
    timer = null
  }
  const startTimer = () => {
    stopTimer()
    timer = setInterval(() => doc.visibilityState === 'visible' && reload(), interval)
  }
  const onVisibility = () => {
    if (doc.visibilityState === 'visible') {
      reload()
      startTimer()
    } else {
      stopTimer()
    }
  }

  doc.addEventListener('visibilitychange', onVisibility)
  startTimer()

  return () => {
    stopTimer()
    doc.removeEventListener('visibilitychange', onVisibility)
  }
}
