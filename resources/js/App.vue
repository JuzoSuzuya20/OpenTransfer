<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick, watch, computed, provide } from 'vue'
import { TolgeeProvider, useTranslate } from '@tolgee/vue'
import { useToast } from 'vue-toastification'

import AppHeader from './components/layout/AppHeader.vue'
import Uploader from './components/uploader.vue'
import Downloader from './components/downloader.vue'
import Auth from './components/auth.vue'
import Setup from './components/setup.vue'
import ThankGuestForUpload from './components/thankGuestForUpload.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import CommandPalette from './components/CommandPalette.vue'
import MySharesPage from './pages/MySharesPage.vue'
import RequestsPage from './pages/RequestsPage.vue'
import StatsPage from './pages/StatsPage.vue'
import AdminPage from './pages/AdminPage.vue'
import ProfilePage from './pages/ProfilePage.vue'
import AboutPage from './pages/AboutPage.vue'
import NotFoundPage from './pages/NotFoundPage.vue'

import { domData, domError, domSuccess } from './domData'
import { emitter, store } from './store'
import { logout, getMyProfile } from './api'
import { resolveRoute, settingsTabToPath, legacyHashToPath } from './lib/router'

const { t } = useTranslate()
const toast = useToast()

const settings = domData()
const applicationName = settings.application_name
const allowFileRequests = settings.allow_reverse_shares === true
const showPoweredBy = settings.show_powered_by === true
const logoWidth = settings.logo_width
const defaultLogo = ref(settings.default_logo !== false)
const logoTimestamp = ref(Date.now())
const logoUrl = computed(() => `/images/logo.png?t=${logoTimestamp.value}`)

const commandPalette = ref(null)
const userName = ref('')
const userAvatarSeed = ref('')

// --- Addresses -------------------------------------------------------------------------------
const route = ref(resolveRoute(location.pathname))

// Waits for the element of an anchor, which a page may render after its data arrives
const scrollToAnchor = (id, attempts = 40) => {
  const element = id && document.getElementById(id)
  if (element) {
    element.scrollIntoView({ behavior: 'smooth', block: 'start' })
    return
  }
  if (id && attempts > 0) {
    setTimeout(() => scrollToAnchor(id, attempts - 1), 50)
  }
}

const navigate = (target, { replace = false } = {}) => {
  const url = new URL(target, location.origin)
  const changed = url.pathname + url.search + url.hash !== location.pathname + location.search + location.hash
  if (changed) {
    history[replace ? 'replaceState' : 'pushState']({}, '', url.pathname + url.search + url.hash)
  }
  route.value = resolveRoute(url.pathname)
  nextTick(() => {
    if (url.hash) {
      scrollToAnchor(decodeURIComponent(url.hash.slice(1)))
    } else if (changed) {
      window.scrollTo({ top: 0 })
    }
  })
}

const onPopState = () => {
  route.value = resolveRoute(location.pathname)
}

provide('navigate', navigate)
provide('scrollToAnchor', scrollToAnchor)

// --- Who is here --------------------------------------------------------------------------------
const isDownload = computed(() => route.value.name === 'download')
const needsSignIn = computed(
  () =>
    !store.isLoggedIn() &&
    !isDownload.value &&
    store.mode !== 'setup' &&
    store.mode !== 'thank_guest_for_upload'
)
const showAuth = computed(() => needsSignIn.value || (route.value.name === 'reset-password' && !store.isLoggedIn()))

// Guests only upload what they were asked for; the administration belongs to admins
const page = computed(() => {
  if (store.mode === 'setup') return 'setup'
  if (store.mode === 'thank_guest_for_upload') return 'thanks'
  if (isDownload.value) return 'download'
  if (showAuth.value) return 'auth'
  if (store.isGuest()) return 'send'
  const name = route.value.name
  if ((name === 'stats' || name === 'admin') && !store.isAdmin()) return 'not-found'
  if (name === 'requests' && !allowFileRequests) return 'not-found'
  return name === 'reset-password' ? 'send' : name
})

const headerVariant = computed(() => {
  if (page.value === 'setup' || page.value === 'auth') return 'bare'
  if (page.value === 'download' || page.value === 'thanks') return 'simple'
  if (store.isGuest()) return 'guest'
  return 'full'
})

const mainWidth = computed(() => {
  if (page.value === 'auth' || page.value === 'setup') return 'is-narrow'
  if (page.value === 'download' || page.value === 'thanks') return 'is-medium'
  return 'is-wide'
})

const pageTitles = computed(() => ({
  send: t.value('nav.send'),
  'my-shares': t.value('nav.my_shares'),
  requests: t.value('nav.file_requests'),
  stats: t.value('nav.statistics'),
  admin: t.value('nav.administration'),
  profile: t.value('settings.title.myProfile'),
  about: t.value('settings.title.about'),
  download: t.value('downloader.files_title'),
  auth: t.value('auth.login')
}))

watch(
  [page, pageTitles],
  () => {
    const title = pageTitles.value[page.value]
    document.title = title ? `${title} · ${applicationName}` : applicationName
  },
  { immediate: true }
)

watch(
  () => store.isLoggedIn() && !store.isGuest(),
  async (signedIn) => {
    if (!signedIn) {
      userName.value = ''
      userAvatarSeed.value = ''
      return
    }
    try {
      const profile = await getMyProfile()
      userName.value = profile.name ?? ''
      userAvatarSeed.value = profile.avatar_seed ?? ''
    } catch (error) {
      console.error('Failed to load the profile', error)
    }
  },
  { immediate: true }
)

const handleLogout = () => {
  if (store.isGuest() && !window.confirm(t.value('auth.confirm_end_guest_session'))) {
    return
  }
  logout()
}

// Former settings links (command palette, deep links of e-mails and bookmarks)
const handleSettingsNavigate = ({ tab, section, subSection }) => {
  const path = settingsTabToPath(tab, section, subSection)
  if (path) {
    navigate(path)
  }
}

onMounted(() => {
  window.addEventListener('popstate', onPopState)

  const legacy = legacyHashToPath(location.hash)
  if (legacy) {
    navigate(legacy, { replace: true })
  } else if (location.hash) {
    scrollToAnchor(decodeURIComponent(location.hash.slice(1)))
  }

  if (domError().length > 0) {
    nextTick(() => toast.error(domError()))
  }
  if (domSuccess().length > 0) {
    nextTick(() => {
      toast.success(domSuccess())
      if (domSuccess() == 'Account linked successfully') {
        navigate('/profile#linked_accounts')
      }
    })
  }

  if (settings.setup_needed) {
    store.setMode('setup')
    return
  }
  store.setMode(isDownload.value ? 'download' : 'upload')

  emitter.on('showPasswordResetForm', () => navigate('/profile'))
  emitter.on('settingsNavigate', handleSettingsNavigate)
  // The branding settings uploaded a logo, or went back to the default one
  emitter.on('logoChanged', (isDefault) => {
    defaultLogo.value = isDefault
    logoTimestamp.value = Date.now()
  })
})

onBeforeUnmount(() => {
  window.removeEventListener('popstate', onPopState)
})
</script>

<template>
  <TolgeeProvider>
    <a class="skip-link" href="#main">{{ t('nav.skip_to_content') }}</a>

    <AppHeader
      :variant="headerVariant"
      :route-name="route.name"
      :user-name="userName"
      :user-avatar-seed="userAvatarSeed"
      :is-admin="store.isAdmin()"
      :allow-file-requests="allowFileRequests"
      :default-logo="defaultLogo"
      :logo-url="logoUrl"
      :logo-width="logoWidth"
      :application-name="applicationName"
      @search="commandPalette?.open()"
      @logout="handleLogout"
    />

    <main class="main" :class="mainWidth" id="main" tabindex="-1">
      <!-- Mounted from the start: it restores a session before anything else shows -->
      <Auth v-show="showAuth" />

      <Setup v-if="page === 'setup'" />
      <ThankGuestForUpload v-else-if="page === 'thanks'" />
      <Downloader v-else-if="page === 'download'" :downloadShareCode="route.params.code" />
      <template v-else-if="page !== 'auth'">
        <Uploader v-if="page === 'send'" />
        <MySharesPage v-else-if="page === 'my-shares'" />
        <RequestsPage v-else-if="page === 'requests'" />
        <StatsPage v-else-if="page === 'stats'" />
        <AdminPage v-else-if="page === 'admin'" :section="route.params.section" />
        <ProfilePage v-else-if="page === 'profile'" />
        <AboutPage v-else-if="page === 'about'" />
        <NotFoundPage v-else />
      </template>
    </main>

    <footer class="app-footer" v-if="showPoweredBy">
      {{ $t('Powered by') }}
      <a href="https://github.com/JuzoSuzuya20/OpenTransfer" rel="noopener">OpenTransfer</a>
    </footer>

    <ConfirmDialog />
    <CommandPalette ref="commandPalette" v-if="store.isLoggedIn() && !store.isGuest()" />
  </TolgeeProvider>
</template>
