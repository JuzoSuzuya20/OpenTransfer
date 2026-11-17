<script setup>
// The brand: a square logo, then the name the admin chose ("Drop Me"), as typed. The logo is the
// one of OpenTransfer in the main colour while the shipped one is in place (--ot-logo: it follows
// a colour being tried in Appearance), else the image an admin uploaded, held in the same square
// without being stretched.
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
    <span v-else class="brand-mark" :style="{ width: `${size}px`, height: `${size}px` }" aria-hidden="true"></span>
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
  display: block;
  background: var(--ot-logo) center / contain no-repeat;
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
