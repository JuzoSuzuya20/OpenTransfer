<script setup>
import { useTranslate } from '@tolgee/vue'
import PageHead from '../components/layout/PageHead.vue'

const { t } = useTranslate()
</script>

<template>
  <div class="page page-requests">
    <PageHead :title="t('nav.file_requests')" />
  </div>
</template>
