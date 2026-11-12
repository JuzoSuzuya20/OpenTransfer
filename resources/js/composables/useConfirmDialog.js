// One question at a time, asked in the app's own window (components/ConfirmDialog.vue), never in
// the browser's popup. show() resolves to true when the person confirms, false otherwise.
import { ref } from 'vue'

const isActive = ref(false)
const title = ref('')
const message = ref('')
const okText = ref('OK')
const cancelText = ref('Cancel')
// 'danger' for what cannot be undone: the confirming button takes the danger colour
const tone = ref('default')
let resolvePromise = null

export function useConfirmDialog() {
  const show = (options = {}) => {
    // A question still open is answered "no" before the next one
    if (resolvePromise) resolvePromise(false)
    return new Promise((resolve) => {
      title.value = options.title || 'Confirm'
      message.value = options.message || ''
      okText.value = options.okText || 'OK'
      cancelText.value = options.cancelText || 'Cancel'
      tone.value = options.tone || 'default'
      isActive.value = true
      resolvePromise = resolve
    })
  }

  const answer = (value) => {
    if (resolvePromise) {
      resolvePromise(value)
      resolvePromise = null
    }
    isActive.value = false
  }

  return {
    isActive,
    title,
    message,
    okText,
    cancelText,
    tone,
    show,
    confirm: () => answer(true),
    cancel: () => answer(false)
  }
}
