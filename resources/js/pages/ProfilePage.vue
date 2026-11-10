<script setup>
import { ref, inject } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { Save } from 'lucide-vue-next'
import PageHead from '../components/layout/PageHead.vue'
import MyProfile from '../components/settings/myProfile.vue'

const { t } = useTranslate()
const scrollToAnchor = inject('scrollToAnchor')
const myProfilePanel = ref(null)
</script>

<template>
  <div class="page page-profile">
    <PageHead :title="t('settings.title.myProfile')" :description="t('settings.description.myProfile')">
      <template #actions>
        <button @click="myProfilePanel.saveUser()">
          <Save aria-hidden="true" />
          {{ t('admin.save') }}
        </button>
      </template>
    </PageHead>
    <MyProfile ref="myProfilePanel" @navItemClicked="scrollToAnchor" />
  </div>
</template>
