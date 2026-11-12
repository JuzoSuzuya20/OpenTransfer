<script setup>
// A number field in the app's style: the browser's spin arrows give way to − and + buttons that
// repeat while held. The field itself still takes the arrows of the keyboard, and the buttons
// stay out of the tab order (they repeat what the arrows do).
import { computed, onBeforeUnmount } from 'vue'
import { Minus, Plus } from 'lucide-vue-next'
import { useTranslate } from '@tolgee/vue'
import { stepValue } from '../../lib/number'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: [Number, String, null], default: null },
  id: { type: String, default: undefined },
  min: { type: [Number, String], default: undefined },
  max: { type: [Number, String], default: undefined },
  // How much a press of − or + changes the value
  increment: { type: Number, default: 1 },
  // A port or a difficulty is typed, not stepped
  steppers: { type: Boolean, default: true }
})
const emit = defineEmits(['update:modelValue'])
const { t } = useTranslate()

const bound = (value) => (value === undefined || value === null || value === '' ? null : Number(value))
const lowest = computed(() => bound(props.min))
const highest = computed(() => bound(props.max))
const current = computed(() => (props.modelValue === '' || props.modelValue === null ? null : Number(props.modelValue)))

const atMin = computed(() => lowest.value !== null && current.value !== null && current.value <= lowest.value)
const atMax = computed(() => highest.value !== null && current.value !== null && current.value >= highest.value)

const onInput = (event) => {
  const value = event.target.value
  emit('update:modelValue', value === '' ? '' : Number(value))
}

const stepBy = (direction) => {
  const next = stepValue(props.modelValue, direction, { min: props.min, max: props.max, increment: props.increment })
  if (next !== current.value) emit('update:modelValue', next)
  // At a bound, holding the button stops repeating
  return (direction < 0 && lowest.value !== null && next <= lowest.value) || (direction > 0 && highest.value !== null && next >= highest.value)
}

// Held down, the button repeats: once at once, then after a pause, faster
let delay = null
let repeat = null
const stop = () => {
  clearTimeout(delay)
  clearInterval(repeat)
  window.removeEventListener('pointerup', stop)
  window.removeEventListener('pointercancel', stop)
}
const hold = (direction) => {
  stop()
  if (stepBy(direction)) return
  window.addEventListener('pointerup', stop)
  window.addEventListener('pointercancel', stop)
  delay = setTimeout(() => {
    repeat = setInterval(() => stepBy(direction) && stop(), 70)
  }, 380)
}
onBeforeUnmount(stop)
</script>

<template>
  <div class="ot-number" :class="{ 'has-steppers': steppers }">
    <input
      type="number"
      :id="id"
      :value="modelValue"
      :min="min"
      :max="max"
      inputmode="numeric"
      v-bind="$attrs"
      @input="onInput"
    />
    <div class="ot-number-steps" v-if="steppers">
      <button
        type="button"
        class="ot-number-step"
        tabindex="-1"
        :aria-label="t('number.decrease')"
        :disabled="atMin"
        @pointerdown.prevent="hold(-1)"
        @pointerleave="stop"
      >
        <Minus aria-hidden="true" />
      </button>
      <button
        type="button"
        class="ot-number-step"
        tabindex="-1"
        :aria-label="t('number.increase')"
        :disabled="atMax"
        @pointerdown.prevent="hold(1)"
        @pointerleave="stop"
      >
        <Plus aria-hidden="true" />
      </button>
    </div>
  </div>
</template>
