<script setup>
// The brand: a square logo, then the name the admin chose ("Drop Me"), as typed. The logo is the
// mark of OpenTransfer while the shipped one is in place, else the image an admin uploaded, held
// in the same square without being stretched.
defineProps({
  size: { type: Number, default: 28 },
  // The name next to the logo; the sign-in screen sets it as its own heading instead
  name: { type: String, default: '' },
  // The logo an admin uploaded; empty for the mark of OpenTransfer
  logoUrl: { type: String, default: '' }
})
</script>

<template>
  <span class="brand-mark-wrap">
    <img v-if="logoUrl" class="brand-logo-img" :src="logoUrl" alt="" :width="size" :height="size" />
    <svg v-else class="brand-mark" :width="size" :height="size" viewBox="0 0 28 28" aria-hidden="true">
      <rect width="28" height="28" rx="7" />
      <path d="M8 15l6-6 6 6M14 9v11" />
    </svg>
    <span class="brand-name" v-if="name">{{ name }}</span>
  </span>
</template>

<style scoped lang="scss">
.brand-mark-wrap {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.brand-mark,
.brand-logo-img {
  flex-shrink: 0;
}
.brand-mark {
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
// Square, whatever the image: it fits inside without being stretched
.brand-logo-img {
  display: block;
  object-fit: contain;
  border-radius: 6px;
}
.brand-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 1.1875rem;
  font-weight: 800;
  line-height: 1.3;
  color: var(--ot-heading);
}
</style>
