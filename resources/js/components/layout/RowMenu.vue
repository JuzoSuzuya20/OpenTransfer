<script setup>
// The "…" menu of a list row. The panel is drawn over the page, under the button or above it when
// the window ends; arrows move between its items, Escape or leaving it closes it.
import { ref, nextTick, onBeforeUnmount } from 'vue'
import { MoreHorizontal } from 'lucide-vue-next'

defineProps({
  label: { type: String, required: true },
  // [{ label, icon, action, danger, disabled }]
  items: { type: Array, required: true }
})

const open = ref(false)
const trigger = ref(null)
const panel = ref(null)
const panelStyle = ref({})
const above = ref(false)

const place = () => {
  if (!trigger.value) return
  const rect = trigger.value.getBoundingClientRect()
  const height = panel.value?.offsetHeight || 220
  above.value = window.innerHeight - rect.bottom < height + 12 && rect.top > height + 12
  const right = Math.max(8, window.innerWidth - rect.right)
  panelStyle.value = above.value
    ? { right: `${right}px`, bottom: `${window.innerHeight - rect.top + 6}px` }
    : { right: `${right}px`, top: `${rect.bottom + 6}px` }
}

const focusables = () => [...(panel.value?.querySelectorAll('.row-menu-item:not(:disabled)') ?? [])]

const show = async () => {
  open.value = true
  place()
  await nextTick()
  place()
  focusables()[0]?.focus()
  window.addEventListener('resize', place)
  window.addEventListener('scroll', place, true)
  document.addEventListener('pointerdown', onOutside, true)
}
const hide = (giveBackFocus = false) => {
  if (!open.value) return
  open.value = false
  window.removeEventListener('resize', place)
  window.removeEventListener('scroll', place, true)
  document.removeEventListener('pointerdown', onOutside, true)
  if (giveBackFocus) trigger.value?.focus()
}
onBeforeUnmount(() => hide())

const toggle = () => (open.value ? hide() : show())
const run = (item) => {
  hide(true)
  item.action()
}
const onOutside = (event) => {
  if (trigger.value?.contains(event.target) || panel.value?.contains(event.target)) return
  hide()
}
// The focus leaving the menu (Tab) closes it
const onFocusOut = (event) => {
  if (!panel.value?.contains(event.relatedTarget) && event.relatedTarget !== trigger.value) hide()
}
const onKeydown = (event) => {
  const list = focusables()
  const at = list.indexOf(document.activeElement)
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    hide(true)
  } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    const step = event.key === 'ArrowDown' ? 1 : -1
    list[(at + step + list.length) % list.length]?.focus()
  } else if (event.key === 'Home' || event.key === 'End') {
    event.preventDefault()
    list[event.key === 'Home' ? 0 : list.length - 1]?.focus()
  }
}
</script>

<template>
  <div class="row-menu">
    <button
      ref="trigger"
      type="button"
      class="ot-act icon-only row-menu-trigger"
      :aria-label="label"
      :title="label"
      aria-haspopup="menu"
      :aria-expanded="open"
      @click="toggle"
    >
      <MoreHorizontal aria-hidden="true" />
    </button>
    <Teleport to="body">
      <Transition name="ot-pop">
        <div
          v-if="open"
          ref="panel"
          class="row-menu-panel"
          :class="{ 'is-above': above }"
          role="menu"
          :aria-label="label"
          :style="panelStyle"
          @keydown="onKeydown"
          @focusout="onFocusOut"
          @mousedown.prevent
        >
          <button
            v-for="item in items"
            :key="item.label"
            type="button"
            role="menuitem"
            class="row-menu-item"
            :class="{ 'is-danger': item.danger }"
            :disabled="item.disabled"
            @click="run(item)"
          >
            <component :is="item.icon" v-if="item.icon" aria-hidden="true" />
            {{ item.label }}
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped lang="scss">
.row-menu {
  position: relative;
}
// Drawn over the page (teleported), so a card that clips its content cannot cut it
.row-menu-panel {
  position: fixed;
  z-index: 900;
  min-width: 230px;
  padding: 6px;
  background: var(--ot-card);
  border: 1px solid var(--ot-line);
  border-radius: var(--ot-radius-lg);
  box-shadow: var(--ot-shadow);
  transform-origin: top right;

  &.is-above {
    transform-origin: bottom right;
  }
}
.row-menu-item {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 10px;
  width: 100%;
  height: 38px;
  margin: 0;
  padding: 0 10px;
  border: 0;
  border-radius: var(--ot-radius);
  background: none;
  color: var(--ot-text);
  font-size: 0.875rem;
  font-weight: 600;

  &:hover {
    background: var(--ot-raised);
    color: var(--ot-heading);
  }
  &:disabled {
    background: none;
    color: var(--ot-faint);
  }
  &.is-danger:hover {
    color: var(--ot-danger);
  }
  svg {
    width: 16px;
    height: 16px;
    margin: 0;
    color: var(--ot-muted);
  }
}
</style>
