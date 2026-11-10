<script setup>
import { ref, onMounted, nextTick, watch, computed } from 'vue'


//components
import LanguageSelector from './components/languageSelector.vue'
import Uploader from './components/uploader.vue'
import Downloader from './components/downloader.vue'
import Auth from './components/auth.vue'
import Settings from './components/settings.vue'
import Setup from './components/setup.vue'
import ThankGuestForUpload from './components/thankGuestForUpload.vue'
import ReverseInvite from './components/reverseInvite.vue'
import Background from './components/layout/background.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import CommandPalette from './components/CommandPalette.vue'

//3rd party
import { LogOut, Settings as SettingsIcon, Search } from 'lucide-vue-next'
import { TolgeeProvider } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'

//1st party
import { domData, domError, domSuccess } from './domData'
import { emitter, store } from './store'
import { logout } from './api'
import { checkUrlHash, clearUrlHash } from './composables/useSettingsNavigation'


//use
const { t } = useTranslate()

//static data
const logoTimestamp = ref(Date.now())
const logoUrl = computed(() => `/images/logo.png?t=${logoTimestamp.value}`)
const allowReverseShares = ref(false)
const logoWidth = ref(0)
const showPoweredBy = ref(false)
const setupNeeded = ref(false)
const defaultLogo = ref(true)
const applicationName = ref('')

//reactive data
const auth = ref(null)
const downloadShareCode = ref('')
const settingsPanel = ref(null)
const toast = useToast()
const reverseInvite = ref(null)
const commandPalette = ref(null)

// The navigation belongs to people with an account: guests only upload what they were asked for
const showNavigation = computed(() => store.isLoggedIn() && !store.isGuest())

onMounted(() => {

  allowReverseShares.value = domData().allow_reverse_shares
  logoWidth.value = domData().logo_width
  showPoweredBy.value = domData().show_powered_by
  setupNeeded.value = domData().setup_needed
  defaultLogo.value = domData().default_logo !== false
  applicationName.value = domData().application_name

  // The branding settings uploaded a logo, or went back to the default one
  emitter.on('logoChanged', (isDefault) => {
    defaultLogo.value = isDefault
    logoTimestamp.value = Date.now()
  })

  if (domError().length > 0) {
    console.log('error', domError())
    nextTick(() => {
      toast.error(domError())
    })
  }

  if (domSuccess().length > 0) {
    nextTick(() => {
      console.log('domSuccess', domSuccess())
      toast.success(domSuccess())
      if (domSuccess() == 'Account linked successfully') {
        store.setSettingsOpen(true)
        settingsPanel.value.setActiveTab('myProfile')
        setTimeout(() => {
          settingsPanel.value.handleNavItemClicked('linked_accounts')
        }, 500)
      }
    })
  }

  if (setupNeeded.value) {
    store.setMode('setup')
    return
  }

  //figure out which mode the application is in
  setMode()

  //register events
  emitter.on('showPasswordResetForm', () => {
    settingsPanel.value.setActiveTab('myProfile')
    nextTick(() => {
      store.setSettingsOpen(true)
      nextTick(() => {
        emitter.emit('profileEditActive')
      })
    })
  })

  // Register settings navigation event listener
  emitter.on('settingsNavigate', handleSettingsNavigate)

  // Check for settings deep-link in URL hash
  // Delay slightly to ensure user is logged in and settings panel is available
  setTimeout(() => {
    checkSettingsDeepLink()
  }, 100)
})

const setMode = () => {
  if (window.location.pathname.includes('shares')) {
    store.setMode('download')
    downloadShareCode.value = window.location.pathname.split('/').pop()
    setPageTitle('Download Share')
  } else {
    store.setMode('upload')
    setPageTitle('Create Share')
  }
}

const setPageTitle = (title) => {
  let currentTitle = document.title
  document.title = `${currentTitle} - ${title}`
}

const handleLogoutClick = () => {
  if (store.isGuest()) {
    const confirm = window.confirm(t.value('auth.confirm_end_guest_session'))
    if (!confirm) {
      return
    }
  }

  logout()
}

const openSettings = () => {
  store.setSettingsOpen(true)
}

const openSettingsTab = (tab) => {
  handleSettingsNavigate({ tab, section: null, subSection: null })
}

const openCommandPalette = () => {
  commandPalette.value?.open()
}

const openReverseShareInvite = () => {
  reverseInvite.value.showReverseInviteForm()
}

/**
 * Handle settings navigation from the composable
 * @param {{ tab: string, section: string|null, subSection: string|null, skipUrlUpdate?: boolean }} navigation
 */
const handleSettingsNavigate = (navigation) => {
  const { tab, section, subSection, skipUrlUpdate = false } = navigation

  // Open settings panel
  store.setSettingsOpen(true)

  // Wait for settings panel to be ready, then navigate
  nextTick(() => {
    if (!settingsPanel.value) {
      console.warn('[App] Settings panel not available')
      return
    }

    // Set the active tab (skip URL update if requested)
    settingsPanel.value.setActiveTab(tab, { updateUrl: !skipUrlUpdate })

    // If there's a section or sub-section to scroll to, do it after a short delay
    // to allow the tab content to render
    if (section || subSection) {
      setTimeout(() => {
        // Prefer sub-section if available, otherwise use section
        const scrollTarget = subSection || section
        settingsPanel.value.handleNavItemClicked(scrollTarget, { skipUrlUpdate })
      }, 300)
    }
  })
}

/**
 * Track if we've already handled the initial deep-link
 */
const deepLinkHandled = ref(false)

/**
 * Check for settings deep-link in URL hash on page load
 */
const checkSettingsDeepLink = () => {
  if (deepLinkHandled.value) return
  
  const hashNav = checkUrlHash()
  if (hashNav && store.isLoggedIn()) {
    deepLinkHandled.value = true
    // Skip URL update since we're already at the correct URL
    handleSettingsNavigate({ ...hashNav, skipUrlUpdate: true })
  }
}

// Watch for login state changes to handle deep-links
watch(
  () => store.loggedIn,
  (isLoggedIn) => {
    if (isLoggedIn && !deepLinkHandled.value) {
      // Small delay to ensure settings panel is mounted
      setTimeout(() => {
        checkSettingsDeepLink()
      }, 100)
    }
  }
)
</script>

<template>
  <TolgeeProvider>
    <Background />
    <header class="app-header">
      <div class="app-header-bar">
        <a class="brand" href="/" :aria-label="t('nav.home')">
          <!-- no custom logo: the OpenTransfer mark, drawn in the colours of the theme -->
          <template v-if="defaultLogo">
            <svg class="brand-mark" viewBox="0 0 28 28" aria-hidden="true">
              <rect width="28" height="28" rx="7" />
              <path d="M8 15l6-6 6 6M14 9v11" />
            </svg>
            <span class="brand-name">OpenTransfer</span>
          </template>
          <img v-else :src="logoUrl" :alt="applicationName" id="logo" :style="{ width: `${logoWidth}px` }" />
        </a>

        <button class="header-search secondary" v-if="showNavigation" @click="openCommandPalette">
          <Search />
          <span class="header-search-label">{{ t('nav.search') }}</span>
          <kbd>Ctrl K</kbd>
        </button>

        <div class="app-header-actions">
          <LanguageSelector />
          <button
            class="settings-button secondary icon-only"
            @click="openSettings"
            v-if="showNavigation"
            :title="t('settings.title.manage')"
            :aria-label="t('settings.title.manage')"
          >
            <SettingsIcon />
          </button>
          <button
            class="logout icon-only secondary"
            @click="handleLogoutClick"
            v-if="store.isLoggedIn()"
            :title="store.isGuest() ? t('auth.end_guest_session') : t('auth.logout')"
            :aria-label="store.isGuest() ? t('auth.end_guest_session') : t('auth.logout')"
          >
            <LogOut />
          </button>
        </div>
      </div>

      <!-- every entry leads to a screen that already exists: the settings panel opens on its tab -->
      <nav class="app-nav" v-if="showNavigation" :aria-label="t('nav.main')">
        <a
          class="app-nav-item"
          href="/"
          :class="{ active: store.mode === 'upload' }"
          :aria-current="store.mode === 'upload' ? 'page' : null"
        >
          {{ t('nav.send') }}
        </a>
        <button class="app-nav-item" @click="openSettingsTab('myShares')">{{ t('nav.my_shares') }}</button>
        <button class="app-nav-item" @click="openReverseShareInvite" v-if="allowReverseShares">
          {{ t('nav.file_requests') }}
        </button>
        <button class="app-nav-item" @click="openSettingsTab('stats')" v-if="store.isAdmin()">
          {{ t('nav.statistics') }}
        </button>
        <button class="app-nav-item" @click="openSettingsTab('system')" v-if="store.isAdmin()">
          {{ t('nav.administration') }}
        </button>
        <button class="app-nav-item" @click="openSettingsTab('myProfile')" v-else>
          {{ t('nav.profile') }}
        </button>
      </nav>
    </header>

    <main class="main" :class="{ 'main-wide': store.mode === 'upload' && store.isLoggedIn(), 'main-medium': store.mode === 'download' }">
      <!-- auth: shows if user is not logged in and the mode is upload -->
      <Auth v-show="!store.isLoggedIn() && store.mode === 'upload'" ref="auth" />

      <!-- uploader: shows if user is logged in and mode is upload -->
      <Uploader v-if="store.mode === 'upload' && store.isLoggedIn()" />

      <!-- downloader -->
      <Downloader v-if="store.mode === 'download'" :downloadShareCode="downloadShareCode" />

      <!-- setup wizard: shows if mode is setup -->
      <Setup v-if="store.mode === 'setup'" />

      <!-- thank guest for upload: shows if mode is thank_guest_for_upload -->
      <ThankGuestForUpload v-if="store.mode === 'thank_guest_for_upload'" />
    </main>

    <footer>
      <div class="powered-by" v-if="showPoweredBy">
        {{ $t('Powered by') }}
        <a href="https://github.com/JuzoSuzuya20/OpenTransfer" rel="noopener">OpenTransfer</a>
      </div>
    </footer>

    <!-- settings: load only if user is logged in -->
    <Settings ref="settingsPanel" v-if="store.isLoggedIn()" />

    <!-- reverse invite: load only if reverse shares are allowed and user is logged in and not a guest -->
    <ReverseInvite ref="reverseInvite" v-if="allowReverseShares && !store.isGuest() && store.isLoggedIn()" />

    <!-- confirmation dialog: available globally -->
    <ConfirmDialog />

    <!-- command palette: available when logged in -->
    <CommandPalette ref="commandPalette" v-if="store.isLoggedIn()" />
  </TolgeeProvider>
</template>
