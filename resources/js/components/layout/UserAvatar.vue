<script setup>
import { ref, watch } from 'vue'
import initials from '../../lib/initials'

const props = defineProps({
  seed: { type: String, default: '' },
  name: { type: String, default: '' },
  size: { type: Number, default: 28 }
})

// DiceBear weighs more than the rest of the header together: its own file, fetched once after
// the page shows, never by the visitors of a share
let drawer = null
const loadDrawer = () => (drawer ??= import('../../lib/avatar').then((module) => module.avatarDataUri))

// Drawn from the seed of the account; the initials stand in while no seed is known
const src = ref(null)
watch(
  () => props.seed,
  async (seed) => {
    if (!seed) {
      src.value = null
      return
    }
    const draw = await loadDrawer()
    if (props.seed === seed) src.value = draw(seed)
  },
  { immediate: true }
)
</script>

<template>
  <span class="user-avatar" :style="{ width: `${size}px`, height: `${size}px` }" aria-hidden="true">
    <img v-if="src" :src="src" alt="" :width="size" :height="size" />
    <span v-else-if="!seed" class="user-avatar-initials">{{ initials(name) }}</span>
  </span>
</template>

<style scoped lang="scss">
.user-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: 50%;
  background: var(--ot-active);
  color: var(--ot-heading);

  img {
    display: block;
    width: 100%;
    height: 100%;
    animation: user-avatar-in var(--ot-dur) var(--ot-ease-out);
  }
}
@keyframes user-avatar-in {
  from {
    opacity: 0;
  }
}
.user-avatar-initials {
  font-size: 0.625rem;
  font-weight: 800;
}
</style>
