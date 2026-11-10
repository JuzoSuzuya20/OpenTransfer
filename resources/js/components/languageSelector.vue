<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useTolgee } from '@tolgee/vue'
import { Languages as LanguageIcon } from 'lucide-vue-next'
import { domData } from '../domData'

import 'flag-icons/css/flag-icons.min.css'

const tolgee = useTolgee(['language'])

const dropdownVisible = ref(false)
const showLanguageSelector = computed(() => {
  return domData().show_language_selector === true
})

// Define available languages as a data structure
const languages = [
  { code: 'en', name: 'English', flag: 'gb' },
  { code: 'de', name: 'Deutsch', flag: 'de' },
  { code: 'fr', name: 'Français', flag: 'fr' },
  { code: 'it', name: 'Italiano', flag: 'it' },
  { code: 'nl', name: 'Nederlands', flag: 'nl' },
  { code: 'pt', name: 'Português', flag: 'pt' }
]

const handleClickOutside = (event) => {
  if (!event.target.closest('.language-selector')) {
    dropdownVisible.value = false
  }
}

const setLanguage = (language) => {
  tolgee.value.changeLanguage(language)
  localStorage.setItem('language', language)
}

const toggleDropdown = () => {
  dropdownVisible.value = !dropdownVisible.value
}

const currentLanguage = computed(() => {
  return tolgee.value.getLanguage()
})

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>
<template>
  <div
    class="language-selector"
    v-if="showLanguageSelector"
    @click="toggleDropdown"
    aria-haspopup="menu"
    :aria-expanded="dropdownVisible"
    aria-label="Select language"
  >
    <LanguageIcon />
    <div class="language-selector-dropdown" :class="{ visible: dropdownVisible }" role="menu">
      <template v-for="language in languages" :key="language.code">
        <div
          class="language-selector-dropdown-item"
          @click="setLanguage(language.code)"
          :class="{ active: currentLanguage === language.code }"
        >
          <span :class="'fi fi-' + language.flag"></span>
          <span>{{ language.name }}</span>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped lang="scss">
.language-selector {
  position: relative;
  z-index: 200;
  width: 40px;
  height: 40px;
  border: 1px solid var(--border-color);
  border-radius: var(--button-border-radius);
  background: var(--secondary-button-background-color);
  color: var(--secondary-button-text-color);
  cursor: pointer;

  display: flex;
  align-items: center;
  justify-content: center;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;

  &:hover {
    background: var(--secondary-button-background-color-hover);
    color: var(--secondary-button-text-color-hover);
  }

  svg {
    width: 20px;
    height: 20px;
  }
  .language-selector-dropdown {
    position: absolute;
    top: calc(100% + 5px);
    right: 0;
    background: var(--panel-item-background-color);
    border: 1px solid var(--border-color);
    padding: 5px;
    display: flex;
    flex-direction: column;
    gap: 5px;
    border-radius: var(--button-border-radius);
    z-index: 100;
    opacity: 0;
    transition: opacity 0.15s ease;
    pointer-events: none;

    &.visible {
      opacity: 1;
      pointer-events: auto;
    }

    .language-selector-dropdown-item {
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: flex-start;
      gap: 5px;
      cursor: pointer;
      font-size: 14px;
      padding: 5px 5px;
      border-radius: 5px;
      margin: 0;
      color: var(--panel-text-color);
      &.active {
        background: var(--primary-button-background-color);
        color: var(--primary-button-text-color);
      }

      &:hover {
        background: var(--primary-button-background-color-hover);
        color: var(--primary-button-text-color-hover);
      }

      span.fi {
        border-radius: 5px;
        width: 15px;
        margin-top: -1px;
      }
    }
  }
}
</style>
