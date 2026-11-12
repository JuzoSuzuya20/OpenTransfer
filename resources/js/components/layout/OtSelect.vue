<script setup>
// A select drawn in the app's own style: a button that shows the chosen option and opens a list.
// It behaves as a native select does (the ARIA "select-only combobox"): arrows, Home/End, pages,
// typing letters, Enter or Space to choose, Escape to close without changing anything. The list
// is drawn over the page, so a card or a menu that clips its content does not cut it.
import { ref, computed, watch, nextTick, onBeforeUnmount, useId } from 'vue'
import { ChevronDown, Check } from 'lucide-vue-next'
import { moveIndex, typeaheadIndex } from '../../lib/listbox'

const props = defineProps({
  modelValue: { type: [String, Number, Boolean, null], default: null },
  // [{ value, label, lang?, disabled? }]
  options: { type: Array, required: true },
  // The id a <label for> points at
  id: { type: String, default: undefined },
  ariaLabel: { type: String, default: undefined },
  ariaDescribedby: { type: String, default: undefined },
  placeholder: { type: String, default: '' },
  // An icon before the value (the language selector)
  icon: { type: [Object, Function], default: null },
  size: { type: String, default: 'md' },
  disabled: { type: Boolean, default: false }
})
const emit = defineEmits(['update:modelValue', 'change'])

const uid = useId()
const listId = `${uid}-list`
const optionId = (index) => `${uid}-option-${index}`

const trigger = ref(null)
const panel = ref(null)
const open = ref(false)
const activeIndex = ref(-1)
const panelStyle = ref({})
const placement = ref('below')
const listLabel = ref('')

const same = (a, b) => a === b || String(a ?? '') === String(b ?? '')
const selectedIndex = computed(() => props.options.findIndex((option) => same(option.value, props.modelValue)))
const selected = computed(() => props.options[selectedIndex.value] ?? null)
const labels = computed(() => props.options.map((option) => option.label))

// --- Opening, placing, closing

const MAX_HEIGHT = 288
const place = () => {
  if (!trigger.value) return
  const rect = trigger.value.getBoundingClientRect()
  const wanted = Math.min(MAX_HEIGHT, props.options.length * 36 + 12)
  const below = window.innerHeight - rect.bottom - 8
  const above = rect.top - 8
  placement.value = below >= wanted || below >= above ? 'below' : 'above'
  const room = Math.max(120, placement.value === 'below' ? below : above)
  const width = Math.max(rect.width, 160)
  const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))
  panelStyle.value = {
    left: `${left}px`,
    minWidth: `${rect.width}px`,
    maxWidth: `${Math.min(420, window.innerWidth - 16)}px`,
    maxHeight: `${Math.min(MAX_HEIGHT, room)}px`,
    ...(placement.value === 'below'
      ? { top: `${rect.bottom + 4}px` }
      : { bottom: `${window.innerHeight - rect.top + 4}px` })
  }
}

const openList = (start) => {
  if (props.disabled || open.value) return
  listLabel.value =
    props.ariaLabel || (props.id && document.querySelector(`label[for="${props.id}"]`)?.textContent.trim()) || ''
  place()
  open.value = true
  activeIndex.value = start ?? (selectedIndex.value >= 0 ? selectedIndex.value : 0)
  follow()
  window.addEventListener('resize', place)
  window.addEventListener('scroll', place, true)
  document.addEventListener('pointerdown', onOutside, true)
}
// The button may still be moving (a menu that has just opened around it): the list follows it
// for the first frames
let following = 0
const follow = () => {
  cancelAnimationFrame(following)
  const until = performance.now() + 400
  const step = (now) => {
    if (!open.value) return
    place()
    if (now < until) following = requestAnimationFrame(step)
  }
  following = requestAnimationFrame(step)
}

const close = () => {
  if (!open.value) return
  open.value = false
  cancelAnimationFrame(following)
  window.removeEventListener('resize', place)
  window.removeEventListener('scroll', place, true)
  document.removeEventListener('pointerdown', onOutside, true)
}
const onOutside = (event) => {
  if (trigger.value?.contains(event.target) || panel.value?.contains(event.target)) return
  close()
}
onBeforeUnmount(close)

const choose = (index) => {
  const option = props.options[index]
  if (!option || option.disabled) return
  close()
  trigger.value?.focus()
  if (!same(option.value, props.modelValue)) {
    emit('update:modelValue', option.value)
    emit('change', option.value)
  }
}

const toggle = () => (open.value ? close() : openList())

// The active option stays in sight as the arrows move it
watch(activeIndex, async (index) => {
  if (!open.value || index < 0) return
  await nextTick()
  document.getElementById(optionId(index))?.scrollIntoView({ block: 'nearest' })
})

// --- Keyboard

let typed = ''
let typedTimer = null
const typeahead = (char) => {
  typed += char
  clearTimeout(typedTimer)
  typedTimer = setTimeout(() => (typed = ''), 500)
  const index = typeaheadIndex(labels.value, activeIndex.value, typed)
  if (index >= 0) activeIndex.value = index
}

const NAVIGATION = ['ArrowDown', 'ArrowUp', 'Home', 'End', 'PageDown', 'PageUp']
const onKeydown = (event) => {
  const { key } = event
  const printable = key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey

  if (!open.value) {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(key)) {
      event.preventDefault()
      openList()
    } else if (key === 'Home' || key === 'End') {
      event.preventDefault()
      openList(key === 'Home' ? 0 : props.options.length - 1)
    } else if (printable) {
      openList()
      typeahead(key)
    }
    return
  }

  if (key === 'Escape') {
    // Closes the list only, not the menu or dialog around it
    event.preventDefault()
    event.stopPropagation()
    close()
  } else if (key === 'ArrowUp' && event.altKey) {
    event.preventDefault()
    choose(activeIndex.value)
  } else if (NAVIGATION.includes(key)) {
    event.preventDefault()
    activeIndex.value = moveIndex(activeIndex.value, key, props.options.length)
  } else if (key === 'Enter' || (key === ' ' && !typed)) {
    event.preventDefault()
    choose(activeIndex.value)
  } else if (key === 'Tab') {
    choose(activeIndex.value)
  } else if (printable) {
    event.preventDefault()
    typeahead(key)
  }
}
</script>

<template>
  <div class="ot-select" :class="[`is-${size}`, { 'is-open': open, 'has-icon': icon }]">
    <button
      ref="trigger"
      type="button"
      class="ot-select-trigger"
      :id="id"
      role="combobox"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-controls="listId"
      :aria-activedescendant="open && activeIndex >= 0 ? optionId(activeIndex) : undefined"
      :aria-label="ariaLabel"
      :aria-describedby="ariaDescribedby"
      :disabled="disabled"
      @click="toggle"
      @keydown="onKeydown"
      @blur="close"
    >
      <component :is="icon" v-if="icon" class="ot-select-icon" aria-hidden="true" />
      <span class="ot-select-value" :class="{ 'is-placeholder': !selected }" :lang="selected?.lang">
        {{ selected ? selected.label : placeholder }}
      </span>
      <ChevronDown class="ot-select-chevron" aria-hidden="true" />
    </button>

    <Teleport to="body">
      <Transition name="ot-pop">
        <ul
          v-if="open"
          ref="panel"
          :id="listId"
          class="ot-select-panel"
          :class="`is-${placement}`"
          role="listbox"
          :aria-label="listLabel || undefined"
          tabindex="-1"
          :style="panelStyle"
          @mousedown.prevent
          @click.stop
        >
          <li
            v-for="(option, index) in options"
            :key="String(option.value)"
            :id="optionId(index)"
            role="option"
            class="ot-select-option"
            :class="{ 'is-active': index === activeIndex, 'is-selected': same(option.value, modelValue) }"
            :aria-selected="same(option.value, modelValue)"
            :aria-disabled="option.disabled || undefined"
            :lang="option.lang"
            @mousemove="activeIndex = index"
            @click="choose(index)"
          >
            <span class="ot-select-option-label">{{ option.label }}</span>
            <Check v-if="same(option.value, modelValue)" class="ot-select-check" aria-hidden="true" />
          </li>
        </ul>
      </Transition>
    </Teleport>
  </div>
</template>
