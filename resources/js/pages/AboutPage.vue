<script setup>
import { inject } from 'vue'
import { useTranslate } from '@tolgee/vue'
import PageHead from '../components/layout/PageHead.vue'
import About from '../components/settings/about.vue'

const { t } = useTranslate()
const scrollToAnchor = inject('scrollToAnchor')
</script>

<template>
  <div class="page page-about">
    <PageHead :title="t('settings.title.about')" />
    <About @navItemClicked="scrollToAnchor" />
  </div>
</template>
