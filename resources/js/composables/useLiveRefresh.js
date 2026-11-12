// A page whose data changes without it (downloads, expiries): reloaded while it is open
// (lib/liveRefresh.js), and a clock that moves so states change on time.
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { startLiveRefresh } from '../lib/liveRefresh'

export function useLiveRefresh(load, { interval = 20000 } = {}) {
  const now = ref(new Date())
  let stop = null
  let clock = null

  onMounted(() => {
    stop = startLiveRefresh(load, { interval })
    clock = setInterval(() => (now.value = new Date()), 15000)
  })
  onBeforeUnmount(() => {
    stop?.()
    clearInterval(clock)
  })

  return { now }
}
