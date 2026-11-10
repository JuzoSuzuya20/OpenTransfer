<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' }
})
</script>

<template>
  <header class="page-head">
    <div class="page-head-text">
      <h1 class="ot-page-title">{{ title }}</h1>
      <p class="page-description" v-if="description">{{ description }}</p>
    </div>
    <div class="page-actions" v-if="$slots.actions">
      <slot name="actions" />
    </div>
  </header>
</template>
