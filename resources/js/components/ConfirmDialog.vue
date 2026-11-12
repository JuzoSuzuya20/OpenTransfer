<script setup>
import { ref, watch, nextTick } from 'vue'
import { CircleX, AlertTriangle } from 'lucide-vue-next'
import { useConfirmDialog } from '../composables/useConfirmDialog'

const { isActive, title, message, okText, cancelText, tone, confirm, cancel } = useConfirmDialog()

const form = ref(null)
const cancelButton = ref(null)
const okButton = ref(null)
let returnFocus = null

// The window takes the focus (on "cancel" when the action cannot be undone) and gives it back
watch(isActive, async (active) => {
  if (active) {
    returnFocus = document.activeElement
    await nextTick()
    ;(tone.value === 'danger' ? cancelButton : okButton).value?.focus()
  } else if (returnFocus) {
    returnFocus.focus?.()
    returnFocus = null
  }
})

const handleClickOutside = (event) => {
  if (!event.target.closest('.confirm-dialog-form')) cancel()
}

// Tab stays within the window while it is open
const onKeydown = (event) => {
  if (event.key === 'Escape') {
    event.preventDefault()
    cancel()
    return
  }
  if (event.key !== 'Tab') return
  const buttons = [cancelButton.value, okButton.value]
  const index = buttons.indexOf(document.activeElement)
  event.preventDefault()
  buttons[(index + (event.shiftKey ? -1 : 1) + buttons.length) % buttons.length]?.focus()
}
</script>

<template>
  <div class="confirm-dialog-overlay" :class="{ active: isActive }" @click="handleClickOutside" @keydown="onKeydown">
    <div
      ref="form"
      class="confirm-dialog-form"
      :class="{ 'is-danger': tone === 'danger' }"
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="confirm-dialog-title"
      :aria-describedby="message ? 'confirm-dialog-message' : undefined"
      :aria-hidden="!isActive"
    >
      <h2 id="confirm-dialog-title">
        <AlertTriangle aria-hidden="true" />
        {{ title }}
      </h2>
      <p v-if="message" id="confirm-dialog-message">{{ message }}</p>
      <div class="confirm-dialog-actions">
        <button type="button" class="ghost" ref="cancelButton" :tabindex="isActive ? 0 : -1" @click="cancel">
          <CircleX aria-hidden="true" />
          {{ cancelText }}
        </button>
        <button type="button" class="confirm-dialog-ok" ref="okButton" :tabindex="isActive ? 0 : -1" @click="confirm">
          {{ okText }}
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
// A question before something that cannot be undone: a card over the page, centred.
// It comes up decelerating and goes away accelerating, sooner than it came.
.confirm-dialog-overlay {
  position: fixed;
  inset: 0;
  z-index: 230;
  display: grid;
  place-items: center;
  padding: 16px;
  background: var(--overlay-background-color);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition:
    opacity var(--ot-dur-exit) var(--ot-ease-in),
    visibility 0s var(--ot-dur-exit);

  &.active {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transition: opacity var(--ot-dur-slow) var(--ot-ease-out);

    .confirm-dialog-form {
      transform: none;
      transition: transform var(--ot-dur-slow) var(--ot-ease-out);
    }
  }
}

.confirm-dialog-form {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 100%;
  max-width: 440px;
  padding: 22px 22px 25px;
  background: var(--ot-card);
  color: var(--ot-text);
  border: 1px solid var(--ot-line);
  border-radius: var(--ot-radius);
  box-shadow: var(--ot-shadow);
  overflow: hidden;
  text-align: center;
  transform: translateY(calc(12px * var(--ot-motion))) scale(calc(1 - 0.02 * var(--ot-motion)));
  transition: transform var(--ot-dur-exit) var(--ot-ease-in);

  &::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    background: var(--ot-accent);
  }
  &.is-danger::after {
    background: var(--ot-danger);
  }

  h2 {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin: 0;
    font-size: 1.125rem;
    font-weight: 800;
    color: var(--ot-heading);
    text-wrap: balance;

    svg {
      width: 20px;
      height: 20px;
      flex-shrink: 0;
      color: var(--ot-accent-text);
    }
  }
  &.is-danger h2 svg {
    color: var(--ot-danger-text);
  }
  p {
    margin: 0;
    color: var(--ot-muted);
    text-wrap: pretty;
  }
}

.confirm-dialog-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
  margin-top: 6px;

  button {
    flex: 1 1 140px;
    margin: 0;
  }
}
// What cannot be undone confirms in the danger colour, on its tint (readable in both schemes)
.is-danger .confirm-dialog-ok {
  background: var(--ot-danger-soft);
  color: var(--ot-danger-text);
  border-color: var(--ot-danger-line);

  &:hover,
  &:active {
    background: var(--ot-danger-line);
    color: var(--ot-danger-text);
  }
}
</style>
