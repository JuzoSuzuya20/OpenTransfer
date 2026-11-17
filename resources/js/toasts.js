// The notifications of the whole application (lib/toasts), shown by components/layout/ToastHost.vue.
// useToast() gives the same calls as before: toast.success(), toast.error(), toast.info().
import { reactive } from 'vue'
import { createToasts } from './lib/toasts'

export const toasts = createToasts({ wrap: reactive })

export const useToast = () => toasts
