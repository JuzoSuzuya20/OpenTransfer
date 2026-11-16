// A block that opens or closes: its height follows. Opening decelerates (ease-out, the slow
// duration); closing accelerates and goes sooner than it came (ease-in, the exit duration). With
// less motion asked for, it only fades. The curves and durations of styles/_motion.scss.
const EASE_OUT = 'cubic-bezier(0.16, 1, 0.3, 1)'
const EASE_IN = 'cubic-bezier(0.55, 0, 0.8, 0.2)'
const OPEN_MS = 320
const CLOSE_MS = 140

const lessMotion = () => globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true

const run = (el, frames, options) =>
  new Promise((resolve) => {
    if (typeof el.animate !== 'function') return resolve()
    const animation = el.animate(frames, options)
    animation.onfinish = animation.oncancel = () => resolve()
  })

export const expandOpen = async (el) => {
  if (lessMotion()) return run(el, [{ opacity: 0 }, { opacity: 1 }], { duration: OPEN_MS, easing: EASE_OUT })
  const height = el.scrollHeight
  el.style.overflow = 'hidden'
  await run(el, [{ height: '0px', opacity: 0 }, { height: `${height}px`, opacity: 1 }], { duration: OPEN_MS, easing: EASE_OUT })
  el.style.overflow = ''
}

export const expandClose = async (el) => {
  if (lessMotion()) return run(el, [{ opacity: 1 }, { opacity: 0 }], { duration: CLOSE_MS, easing: EASE_IN })
  el.style.overflow = 'hidden'
  await run(el, [{ height: `${el.offsetHeight}px`, opacity: 1 }, { height: '0px', opacity: 0 }], { duration: CLOSE_MS, easing: EASE_IN })
  el.style.overflow = ''
}

// <details v-expand-details>: its content (the element after the summary) opens and closes the
// same way, instead of appearing at once. The keyboard goes through the click of the summary.
export const vExpandDetails = {
  mounted(details) {
    const summary = details.querySelector(':scope > summary')
    if (!summary) return
    let busy = false
    summary.addEventListener('click', async (event) => {
      const content = details.querySelector(':scope > :not(summary)')
      if (!content) return
      event.preventDefault()
      if (busy) return
      busy = true
      if (details.open) {
        await expandClose(content)
        details.open = false
      } else {
        details.open = true
        await expandOpen(content)
      }
      busy = false
    })
  }
}
