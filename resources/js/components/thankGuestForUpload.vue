<script setup>
import { CircleCheck } from 'lucide-vue-next'
import { useTranslate } from '@tolgee/vue'

const { t } = useTranslate()
</script>

<template>
  <section class="thank-guest-for-upload" aria-labelledby="h-thanks">
    <CircleCheck aria-hidden="true" />
    <h1 id="h-thanks">{{ t('guest.upload_success') }}</h1>
    <p>{{ t('guest.upload_success_message') }}</p>
  </section>
</template>
