<script setup>
import { ref } from 'vue'
import { useTranslate } from '@tolgee/vue'
import PageHead from '../components/layout/PageHead.vue'
import MyShares from '../components/settings/myShares.vue'

const { t } = useTranslate()
const mySharesPanel = ref(null)
</script>

<template>
  <div class="page page-my-shares">
    <PageHead :title="t('nav.my_shares')" />
    <MyShares ref="mySharesPanel" />
  </div>
</template>
