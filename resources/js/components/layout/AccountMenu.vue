<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { ChevronDown, User, Info, LogOut } from 'lucide-vue-next'
import AppLink from './AppLink.vue'
import LanguageSelector from '../languageSelector.vue'
import initials, { shortName } from '../../lib/initials'

defineProps({
  name: { type: String, default: '' }
})
const emit = defineEmits(['logout'])
const { t } = useTranslate()

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
      <span class="avatar" aria-hidden="true">{{ initials(name) }}</span>
      <span class="account-name">{{ shortName(name) }}</span>
      <ChevronDown class="chevron" aria-hidden="true" />
    </button>

    <div class="account-menu-panel" v-show="open">
      <div class="account-menu-who" v-if="name">{{ name }}</div>
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
      <button type="button" class="account-menu-item is-danger" @click="close(); emit('logout')">
        <LogOut aria-hidden="true" />
        {{ t('auth.logout') }}
      </button>
    </div>
  </div>
</template>
