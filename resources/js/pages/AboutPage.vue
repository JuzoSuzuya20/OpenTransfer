<script setup>
import { useTranslate } from '@tolgee/vue'
import PageHead from '../components/layout/PageHead.vue'
import About from '../components/settings/about.vue'

const { t } = useTranslate()
</script>

<template>
  <div class="page page-about">
    <PageHead :title="t('settings.title.about')" :description="t('settings.about.description')" />
    <About />
  </div>
</template>
