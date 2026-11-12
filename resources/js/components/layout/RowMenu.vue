<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { MoreHorizontal } from 'lucide-vue-next'

defineProps({
  label: { type: String, required: true },
  // [{ label, icon, action, danger, disabled }]
  items: { type: Array, required: true }
})

const open = ref(false)
const root = ref(null)

const toggle = async () => {
  open.value = !open.value
  if (open.value) {
    await nextTick()
    root.value?.querySelector('.row-menu-panel button:not(:disabled)')?.focus()
  }
}
const run = (item) => {
  open.value = false
  item.action()
}
const onDocumentClick = (event) => {
  if (open.value && !root.value?.contains(event.target)) open.value = false
}
const onKeydown = (event) => {
  if (open.value && event.key === 'Escape') {
    open.value = false
    root.value?.querySelector('.row-menu-trigger')?.focus()
  }
}
onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div class="row-menu" ref="root">
    <button
      type="button"
      class="ot-act icon-only row-menu-trigger"
      :aria-label="label"
      :title="label"
      aria-haspopup="true"
      :aria-expanded="open"
      @click="toggle"
    >
      <MoreHorizontal aria-hidden="true" />
    </button>
    <Transition name="ot-pop">
    <div class="row-menu-panel" v-show="open">
      <button
        v-for="item in items"
        :key="item.label"
        type="button"
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
  </div>
</template>

<style scoped lang="scss">
.row-menu {
  position: relative;
}
.row-menu-panel {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 50;
  min-width: 230px;
  padding: 6px;
  background: var(--ot-card);
  border: 1px solid var(--ot-line);
  border-radius: var(--ot-radius-lg);
  box-shadow: var(--ot-shadow);
  transform-origin: top right;
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
