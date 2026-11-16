<script setup>
// The shape of what is loading, in place of an empty page that fills at once: rows of a list,
// lines of text, cards of figures, fields of a form. Screen readers hear that it loads; the sheen
// that runs across it speeds up and slows down, and stays still for less motion.
import { useTranslate } from '@tolgee/vue'

defineProps({
  variant: { type: String, default: 'rows' }, // rows | lines | cards | fields
  count: { type: Number, default: 3 }
})
const { t } = useTranslate()
</script>

<template>
  <div class="ot-skeleton" :class="`is-${variant}`" role="status" aria-live="polite">
    <span class="visually-hidden-label">{{ t('common.loading') }}</span>
    <template v-if="variant === 'fields'">
      <span v-for="index in count" :key="index" class="ot-skeleton-field" :style="{ '--ot-i': index }" aria-hidden="true">
        <i class="ot-skeleton-bone is-label"></i>
        <i class="ot-skeleton-bone is-input"></i>
      </span>
    </template>
    <template v-else-if="variant === 'rows'">
      <span v-for="index in count" :key="index" class="ot-skeleton-row" :style="{ '--ot-i': index }" aria-hidden="true">
        <i class="ot-skeleton-bone is-title"></i>
        <i class="ot-skeleton-bone is-meta"></i>
      </span>
    </template>
    <i v-else v-for="index in count" :key="index" class="ot-skeleton-bone" :class="variant === 'cards' ? 'is-card' : 'is-line'" :style="{ '--ot-i': index }" aria-hidden="true"></i>
  </div>
</template>
