<script setup>
import { ref, inject } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { RefreshCw } from 'lucide-vue-next'
import PageHead from '../components/layout/PageHead.vue'
import SystemStats from '../components/settings/systemStats.vue'

const { t } = useTranslate()
const scrollToAnchor = inject('scrollToAnchor')
const systemStats = ref(null)
</script>

<template>
  <div class="page page-stats">
    <PageHead :title="t('nav.statistics')" :description="t('settings.description.stats')">
      <template #actions>
        <button class="secondary" @click="systemStats.refreshStats()">
          <RefreshCw aria-hidden="true" />
          {{ t('settings.stats.refresh') }}
        </button>
      </template>
    </PageHead>
    <SystemStats ref="systemStats" @navItemClicked="scrollToAnchor" />
  </div>
</template>
