<script setup>
defineProps({
  size: { type: Number, default: 28 },
  // The name next to the mark; the sign-in screen sets it as its own heading
  withName: { type: Boolean, default: true }
})
</script>

<template>
  <span class="brand-mark-wrap">
    <svg class="brand-mark" :width="size" :height="size" viewBox="0 0 28 28" aria-hidden="true">
      <rect width="28" height="28" rx="7" />
      <path d="M8 15l6-6 6 6M14 9v11" />
    </svg>
    <span class="brand-name" v-if="withName">OpenTransfer</span>
  </span>
</template>

<style scoped lang="scss">
.brand-mark-wrap {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}
.brand-mark {
  flex-shrink: 0;
  rect {
    fill: var(--ot-accent);
  }
  path {
    fill: none;
    stroke: var(--ot-on-accent);
    stroke-width: 2.4;
    stroke-linecap: round;
    stroke-linejoin: round;
  }
}
.brand-name {
  font-size: 1.1875rem;
  font-weight: 800;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--ot-heading);
}
</style>
