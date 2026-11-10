<script setup>
import { inject } from 'vue'

const props = defineProps({
  to: { type: String, required: true }
})
const emit = defineEmits(['click'])
const navigate = inject('navigate')

// A real link: it opens in a new tab on a modified click, and moves within the application otherwise
const onClick = (event) => {
  emit('click', event)
  if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
    return
  }
  event.preventDefault()
  navigate(props.to)
}
</script>

<template>
  <a :href="to" @click="onClick"><slot /></a>
</template>
