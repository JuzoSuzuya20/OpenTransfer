<script setup>
// The notifications, at the bottom right (lib/toasts for how long, how many, the same one once).
// The pointer or the focus on them holds their countdown; Escape closes the one that has the
// focus. Screen readers get each message from two regions always in the page (an error at once).
import { useTranslate } from '@tolgee/vue'
import { CircleCheck, CircleAlert, Info, TriangleAlert, X } from 'lucide-vue-next'
import { toasts } from '../../toasts'

const { t } = useTranslate()
const ICONS = { success: CircleCheck, error: CircleAlert, info: Info, warning: TriangleAlert }

// The focus leaving the notifications, not going from one to another
const onFocusOut = (event) => {
  if (!event.currentTarget.contains(event.relatedTarget)) toasts.resume()
}
</script>

<template>
  <Teleport to="body">
    <section
      class="ot-toasts"
      :aria-label="t('toast.region')"
      @mouseenter="toasts.pause()"
      @mouseleave="toasts.resume()"
      @focusin="toasts.pause()"
      @focusout="onFocusOut"
    >
      <TransitionGroup name="ot-toast" tag="ol" class="ot-toasts-list">
        <li
          v-for="toast in toasts.items"
          :key="toast.id"
          class="ot-toast"
          :class="`is-${toast.type}`"
          @keydown.esc="toasts.dismiss(toast.id)"
        >
          <div class="ot-toast-card">
            <span class="ot-toast-icon" aria-hidden="true"><component :is="ICONS[toast.type]" /></span>
            <p class="ot-toast-text">
              {{ toast.message }}
              <span v-if="toast.count > 1" :key="toast.count" class="ot-toast-count">×{{ toast.count }}</span>
            </p>
            <button type="button" class="ot-toast-close" :aria-label="t('toast.close')" @click="toasts.dismiss(toast.id)">
              <X aria-hidden="true" />
            </button>
          </div>
        </li>
      </TransitionGroup>
    </section>
    <div class="visually-hidden-label" role="status" aria-live="polite">
      <span :key="`polite-${toasts.live.politeSeq}`">{{ toasts.live.polite }}</span>
    </div>
    <div class="visually-hidden-label" role="alert" aria-live="assertive">
      <span :key="`assertive-${toasts.live.assertiveSeq}`">{{ toasts.live.assertive }}</span>
    </div>
  </Teleport>
</template>
