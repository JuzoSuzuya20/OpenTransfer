<script setup>
// A block that appears or goes while the page is open: its height follows instead of the page
// jumping (lib/expand). Its wrapper holds no padding, so that the height runs down to nothing.
import { expandOpen, expandClose } from '../../lib/expand'

// Shown while truthy (a flag, a value typed in a field)
defineProps({ show: { default: false } })

const onEnter = (el, done) => expandOpen(el).then(done)
const onLeave = (el, done) => expandClose(el).then(done)
</script>

<template>
  <Transition :css="false" @enter="onEnter" @leave="onLeave">
    <div v-if="show" class="ot-expand"><slot /></div>
  </Transition>
</template>

<style lang="scss">
// Its children's margins stay inside it: clipped while it opens, they would jump at the end
.ot-expand {
  display: flow-root;
}
</style>
