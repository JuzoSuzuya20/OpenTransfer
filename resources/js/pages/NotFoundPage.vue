<script setup>
import { useTranslate } from '@tolgee/vue'
import { FileX } from 'lucide-vue-next'
import AppLink from '../components/layout/AppLink.vue'

const { t } = useTranslate()
</script>

<template>
  <div class="page page-not-found">
    <section class="ot-card not-found">
      <FileX aria-hidden="true" />
      <h1 class="ot-page-title">{{ t('not_found.title') }}</h1>
      <p>{{ t('not_found.text') }}</p>
      <AppLink to="/" class="button-link">{{ t('not_found.back') }}</AppLink>
    </section>
  </div>
</template>
