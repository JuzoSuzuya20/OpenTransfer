<script setup>
import { computed } from 'vue'
import { avatarDataUri } from '../../lib/avatar'
import initials from '../../lib/initials'

const props = defineProps({
  seed: { type: String, default: '' },
  name: { type: String, default: '' },
  size: { type: Number, default: 28 }
})

// Drawn from the seed of the account; the initials stand in until the seed is known
const src = computed(() => avatarDataUri(props.seed))
</script>

<template>
  <span class="user-avatar" :style="{ width: `${size}px`, height: `${size}px` }" aria-hidden="true">
    <img v-if="src" :src="src" alt="" :width="size" :height="size" />
    <span v-else class="user-avatar-initials">{{ initials(name) }}</span>
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
  }
}
.user-avatar-initials {
  font-size: 0.625rem;
  font-weight: 800;
}
</style>
