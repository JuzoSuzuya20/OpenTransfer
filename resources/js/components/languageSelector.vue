<script setup>
import { computed } from 'vue'
import { useTolgee, useTranslate } from '@tolgee/vue'
import { Languages as LanguageIcon } from 'lucide-vue-next'
import { domData } from '../domData'
import { store } from '../store'
import { updateMyProfile } from '../api'

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

const setLanguage = (event) => {
  tolgee.value.changeLanguage(event.target.value)
  localStorage.setItem('language', event.target.value)
  // An account gets its emails in its language: the server keeps it
  if (store.isLoggedIn() && !store.isGuest()) {
    updateMyProfile({ language: event.target.value }).catch((error) => console.error('Failed to save the language', error))
  }
}
</script>

<template>
  <label class="language-selector" v-if="showLanguageSelector">
    <LanguageIcon aria-hidden="true" />
    <span class="visually-hidden-label">{{ t('nav.language') }}</span>
    <select :value="currentLanguage" @change="setLanguage">
      <option v-for="language in languages" :key="language.code" :value="language.code" :lang="language.code">
        {{ language.name }}
      </option>
    </select>
  </label>
</template>

<style scoped lang="scss">
.language-selector {
  position: relative;
  display: inline-flex;
  align-items: center;
  margin: 0;

  svg {
    position: absolute;
    left: 12px;
    width: 16px;
    height: 16px;
    color: var(--ot-muted);
    pointer-events: none;
  }

  select {
    width: auto;
    height: 32px;
    margin: 0;
    padding: 0 32px 0 34px;
    border-radius: var(--ot-btn-radius);
    background-color: var(--ot-raised);
    border-color: var(--ot-line-field);
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
  }
}
</style>
