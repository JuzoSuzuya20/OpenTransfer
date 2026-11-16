<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { ChevronDown, User, Info, LogOut, Monitor, Sun, Moon } from 'lucide-vue-next'
import AppLink from './AppLink.vue'
import LanguageSelector from '../languageSelector.vue'
import { shortName } from '../../lib/initials'
import UserAvatar from './UserAvatar.vue'
import { THEMES, storedTheme, applyTheme } from '../../lib/theme'

defineProps({
  name: { type: String, default: '' },
  avatarSeed: { type: String, default: '' }
})
const emit = defineEmits(['logout'])
const { t } = useTranslate()

// Light, dark, or the system (by default): kept by this browser (lib/theme)
const theme = ref(storedTheme())
const THEME_ICONS = { system: Monitor, light: Sun, dark: Moon }
const chooseTheme = (choice) => {
  theme.value = applyTheme(choice)
}

const open = ref(false)
const root = ref(null)
const trigger = ref(null)

const close = (returnFocus = false) => {
  open.value = false
  if (returnFocus) {
    trigger.value?.focus()
  }
}

const toggle = async () => {
  open.value = !open.value
  if (open.value) {
    await nextTick()
    root.value?.querySelector('.account-menu-panel a, .account-menu-panel button')?.focus()
  }
}

const onDocumentClick = (event) => {
  if (open.value && !root.value?.contains(event.target)) {
    close()
  }
}
const onKeydown = (event) => {
  if (open.value && event.key === 'Escape') {
    close(true)
  }
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div class="account-menu" ref="root">
    <button
      ref="trigger"
      class="account-button secondary"
      type="button"
      aria-haspopup="true"
      :aria-expanded="open"
      :aria-label="t('nav.account')"
      @click="toggle"
    >
      <UserAvatar :seed="avatarSeed" :name="name" :size="24" />
      <span class="account-name">{{ shortName(name) }}</span>
      <ChevronDown class="chevron" aria-hidden="true" />
    </button>

    <Transition name="ot-pop">
    <div class="account-menu-panel" v-show="open">
      <div class="account-menu-who" v-if="name">
        <UserAvatar :seed="avatarSeed" :name="name" :size="36" />
        <span>{{ name }}</span>
      </div>
      <AppLink to="/profile" class="account-menu-item" @click="close()">
        <User aria-hidden="true" />
        {{ t('nav.profile') }}
      </AppLink>
      <AppLink to="/about" class="account-menu-item" @click="close()">
        <Info aria-hidden="true" />
        {{ t('settings.title.about') }}
      </AppLink>
      <div class="account-menu-language">
        <LanguageSelector />
      </div>
      <div class="account-menu-theme">
        <span class="account-menu-theme-label" id="account-theme-label">{{ t('theme.label') }}</span>
        <div class="ot-segmented" role="radiogroup" aria-labelledby="account-theme-label">
          <button
            v-for="choice in THEMES"
            :key="choice"
            type="button"
            role="radio"
            :aria-checked="theme === choice"
            :aria-selected="theme === choice"
            @click="chooseTheme(choice)"
          >
            <component :is="THEME_ICONS[choice]" aria-hidden="true" />
            {{ t(`theme.${choice}`) }}
          </button>
        </div>
      </div>
      <button type="button" class="account-menu-item is-danger" @click="close(); emit('logout')">
        <LogOut aria-hidden="true" />
        {{ t('auth.logout') }}
      </button>
    </div>
    </Transition>
  </div>
</template>
