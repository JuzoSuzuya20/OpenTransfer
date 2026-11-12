<script setup>
import { computed } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { Search, Plus, LogOut } from 'lucide-vue-next'
import AppLink from './AppLink.vue'
import AccountMenu from './AccountMenu.vue'
import LanguageSelector from '../languageSelector.vue'
import BrandMark from './BrandMark.vue'

const props = defineProps({
  // full: signed-in account; guest: an invited uploader; simple: a recipient; bare: sign-in and setup
  variant: { type: String, required: true },
  routeName: { type: String, default: '' },
  userName: { type: String, default: '' },
  userAvatarSeed: { type: String, default: '' },
  isAdmin: { type: Boolean, default: false },
  allowFileRequests: { type: Boolean, default: false },
  defaultLogo: { type: Boolean, default: true },
  logoUrl: { type: String, default: '' },
  logoWidth: { type: [Number, String], default: 0 },
  applicationName: { type: String, default: '' }
})
const emit = defineEmits(['search', 'logout', 'new-share'])
const { t } = useTranslate()

const navItems = computed(() =>
  [
    { to: '/', route: 'send', label: t.value('nav.send') },
    { to: '/my-shares', route: 'my-shares', label: t.value('nav.my_shares') },
    props.allowFileRequests && { to: '/requests', route: 'requests', label: t.value('nav.file_requests') },
    props.isAdmin && { to: '/stats', route: 'stats', label: t.value('nav.statistics') },
    props.isAdmin && { to: '/admin', route: 'admin', label: t.value('nav.administration') }
  ].filter(Boolean)
)
</script>

<template>
  <header class="app-header" :class="`is-${variant}`">
    <div class="app-header-bar">
      <AppLink v-if="variant !== 'bare'" class="brand" to="/" :aria-label="t('nav.home')">
        <BrandMark v-if="defaultLogo" />
        <img v-else :src="logoUrl" :alt="applicationName" id="logo" :style="{ width: `${logoWidth}px` }" />
      </AppLink>

      <template v-if="variant === 'full'">
        <button type="button" class="header-search" @click="emit('search')">
          <Search aria-hidden="true" />
          <span class="header-search-label">{{ t('nav.search_placeholder') }}</span>
          <kbd>Ctrl+K</kbd>
        </button>
        <!-- A quick share in a window; its "More options" leads to the send page -->
        <button type="button" class="header-new-share" @click="emit('new-share')" :aria-label="t('nav.new_share')">
          <Plus aria-hidden="true" />
          <span>{{ t('nav.new_share') }}</span>
        </button>
        <AccountMenu :name="userName" :avatar-seed="userAvatarSeed" @logout="emit('logout')" />
      </template>

      <div class="app-header-end" v-else>
        <LanguageSelector />
        <button v-if="variant === 'guest'" type="button" class="secondary header-end-session" @click="emit('logout')">
          <LogOut aria-hidden="true" />
          {{ t('auth.end_guest_session') }}
        </button>
      </div>
    </div>

    <nav v-if="variant === 'full'" class="app-nav" :aria-label="t('nav.main')">
      <AppLink
        v-for="item in navItems"
        :key="item.to"
        :to="item.to"
        class="app-nav-item"
        :class="{ active: routeName === item.route }"
        :aria-current="routeName === item.route ? 'page' : null"
      >
        {{ item.label }}
      </AppLink>
    </nav>
  </header>
</template>
