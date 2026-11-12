<script setup>
import { computed } from 'vue'
import { useTolgee, useTranslate } from '@tolgee/vue'
import { Languages as LanguageIcon } from 'lucide-vue-next'
import { domData } from '../domData'
import { store } from '../store'
import { updateMyProfile } from '../api'
import OtSelect from './layout/OtSelect.vue'

const tolgee = useTolgee(['language'])
const { t } = useTranslate()

const showLanguageSelector = computed(() => domData().show_language_selector === true)

// Each language is named in itself: the person who needs to switch may not read the current one
const languages = [
  { code: 'en', name: 'English' },
  { code: 'de', name: 'Deutsch' },
  { code: 'es', name: 'Español' },
  { code: 'fr', name: 'Français' },
  { code: 'it', name: 'Italiano' },
  { code: 'nl', name: 'Nederlands' },
  { code: 'pt', name: 'Português' }
]

const currentLanguage = computed(() => tolgee.value.getLanguage())
const options = languages.map((language) => ({ value: language.code, label: language.name, lang: language.code }))

const setLanguage = (code) => {
  tolgee.value.changeLanguage(code)
  localStorage.setItem('language', code)
  // An account gets its emails in its language: the server keeps it
  if (store.isLoggedIn() && !store.isGuest()) {
    updateMyProfile({ language: code }).catch((error) => console.error('Failed to save the language', error))
  }
}
</script>

<template>
  <div class="language-selector" v-if="showLanguageSelector">
    <OtSelect
      :model-value="currentLanguage"
      :options="options"
      :icon="LanguageIcon"
      :aria-label="t('nav.language')"
      size="sm"
      @change="setLanguage"
    />
  </div>
</template>

<style scoped lang="scss">
.language-selector {
  display: inline-flex;

  .ot-select {
    width: auto;
    margin: 0;
  }
}
</style>
