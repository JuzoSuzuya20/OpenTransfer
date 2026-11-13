<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick, watch, computed, provide, defineAsyncComponent } from 'vue'
import { TolgeeProvider, useTranslate } from '@tolgee/vue'
import { useToast } from 'vue-toastification'

import AppHeader from './components/layout/AppHeader.vue'
import Uploader from './components/uploader.vue'
import { X } from 'lucide-vue-next'
import { handedOverFiles } from './lib/quickSendHandoff'
import Downloader from './components/downloader.vue'
import Auth from './components/auth.vue'
import ConfirmDialog from './components/ConfirmDialog.vue'
import { useConfirmDialog } from './composables/useConfirmDialog'
import CommandPalette from './components/CommandPalette.vue'
import NotFoundPage from './pages/NotFoundPage.vue'

import { domData, domError, domSuccess } from './domData'
import { emitter, store } from './store'
import { logout, getMyProfile, updateMyProfile } from './api'
import { resolveRoute, settingsTabToPath, legacyHashToPath } from './lib/router'
import { whenIdle } from './lib/idle'
import { flashMessageKey } from './lib/flashMessages'

// What opens first stays in the main file (sign-in, sending, a share's page); every other page
// is a file of its own, so that the visitor of a share never downloads the administration
const signedInPages = {
  myShares: () => import('./pages/MySharesPage.vue'),
  requests: () => import('./pages/RequestsPage.vue'),
  stats: () => import('./pages/StatsPage.vue'),
  admin: () => import('./pages/AdminPage.vue'),
  profile: () => import('./pages/ProfilePage.vue'),
  about: () => import('./pages/AboutPage.vue')
}
const MySharesPage = defineAsyncComponent(signedInPages.myShares)
const RequestsPage = defineAsyncComponent(signedInPages.requests)
const StatsPage = defineAsyncComponent(signedInPages.stats)
const AdminPage = defineAsyncComponent(signedInPages.admin)
const ProfilePage = defineAsyncComponent(signedInPages.profile)
const AboutPage = defineAsyncComponent(signedInPages.about)
const Setup = defineAsyncComponent(() => import('./components/setup.vue'))
const ThankGuestForUpload = defineAsyncComponent(() => import('./components/thankGuestForUpload.vue'))

const { t, isLoading: translationsLoading } = useTranslate()
const toast = useToast()

const settings = domData()
const applicationName = settings.application_name
const allowFileRequests = settings.allow_reverse_shares === true
const showPoweredBy = settings.show_powered_by === true
// The image an admin put behind the sign-in screen (Customization): only a name this app wrote
const loginBackground = /^login-[a-z0-9]{16}\.(jpg|png|webp)$/.test(settings.login_background || '')
  ? `/api/backgrounds/${settings.login_background}`
  : null
const logoWidth = settings.logo_width
const defaultLogo = ref(settings.default_logo !== false)
const logoTimestamp = ref(Date.now())
const logoUrl = computed(() => `/images/logo.png?t=${logoTimestamp.value}`)

const commandPalette = ref(null)

// The quick "New share" window (the send component, compact). It does not close while it sends.
const quickSendOpen = ref(false)
const quickUploader = ref(null)
const quickClose = ref(null)
const openQuickSend = async () => {
  quickSendOpen.value = true
  await nextTick()
  quickClose.value?.focus()
}
const closeQuickSend = () => {
  if (quickUploader.value?.isBusy()) return
  quickSendOpen.value = false
}
const quickMoreOptions = (files) => {
  handedOverFiles.value = files
  quickSendOpen.value = false
  navigate('/')
}
const userName = ref('')
const userAvatarSeed = ref('')

// --- Addresses -------------------------------------------------------------------------------
const route = ref(resolveRoute(location.pathname))
const currentHash = ref(location.hash)

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
  currentHash.value = url.hash
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
  currentHash.value = location.hash
}

provide('navigate', navigate)
provide('currentHash', currentHash)
provide('scrollToAnchor', scrollToAnchor)

// --- Who is here --------------------------------------------------------------------------------
const isDownload = computed(() => route.value.name === 'download')
// The first renewal of the session is still under way (store.restoringSession)
const restoring = computed(() => store.restoringSession && !store.isLoggedIn())
const needsSignIn = computed(
  () =>
    !store.isLoggedIn() &&
    !restoring.value &&
    !isDownload.value &&
    store.mode !== 'setup' &&
    store.mode !== 'thank_guest_for_upload'
)
const showAuth = computed(() => needsSignIn.value || (route.value.name === 'reset-password' && !store.isLoggedIn() && !restoring.value))

// Guests only upload what they were asked for; the administration belongs to admins
const page = computed(() => {
  if (store.mode === 'setup') return 'setup'
  if (store.mode === 'thank_guest_for_upload') return 'thanks'
  if (isDownload.value) return 'download'
  if (restoring.value) return 'restoring'
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
    // The other pages arrive while the browser has nothing else to do: opening one later does
    // not wait for the network
    whenIdle(() => Object.values(signedInPages).forEach((load) => load().catch(() => {})))
    try {
      const profile = await getMyProfile()
      userName.value = profile.name ?? ''
      userAvatarSeed.value = profile.avatar_seed ?? ''
      // An account that never chose keeps the language of the screen for its emails
      const screenLanguage = localStorage.getItem('language')
      if (!profile.language && screenLanguage) {
        updateMyProfile({ language: screenLanguage }).catch(() => {})
      }
    } catch (error) {
      console.error('Failed to load the profile', error)
    }
  },
  { immediate: true }
)

const confirmDialog = useConfirmDialog()
const handleLogout = async () => {
  if (
    store.isGuest() &&
    !(await confirmDialog.show({
    title: t.value('dialog.title'),
    message: t.value('auth.confirm_end_guest_session'),
    okText: t.value('auth.end_guest_session'),
    cancelText: t.value('dialog.cancel'),
    tone: 'danger'
  }))
  ) {
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

  // The sentence the server left after a round trip to an identity provider, in the language
  // of the screen when the app knows it
  // (the language arrives in a file of its own: the message waits for it)
  const flash = (message) => (flashMessageKey(message) ? t.value(flashMessageKey(message)) : message)
  const whenTranslated = (show) => {
    if (!translationsLoading.value) return nextTick(show)
    const stop = watch(translationsLoading, (loading) => {
      if (!loading) {
        stop()
        show()
      }
    })
  }
  if (domError().length > 0) {
    whenTranslated(() => toast.error(flash(domError())))
  }
  if (domSuccess().length > 0) {
    whenTranslated(() => {
      toast.success(flash(domSuccess()))
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

    <Transition name="ot-backdrop" appear>
      <div
        v-if="loginBackground && showAuth"
        class="login-backdrop"
        :style="{ backgroundImage: `url('${loginBackground}')` }"
        aria-hidden="true"
      ></div>
    </Transition>

    <AppHeader
      v-if="page !== 'restoring'"
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
      @new-share="openQuickSend"
      @logout="handleLogout"
    />

    <main class="main" :class="mainWidth" id="main" tabindex="-1">
      <!-- Mounted from the start: it restores a session before anything else shows -->
      <Auth v-show="showAuth" />

      <Setup v-if="page === 'setup'" />
      <ThankGuestForUpload v-else-if="page === 'thanks'" />
      <Downloader v-else-if="page === 'download'" :downloadShareCode="route.params.code" />
      <template v-else-if="page !== 'auth' && page !== 'restoring'">
        <!-- From one page to the next: the old one goes at once, the new one settles (styles/_motion.scss) -->
        <Transition name="ot-page" mode="out-in">
          <Uploader v-if="page === 'send'" />
          <MySharesPage v-else-if="page === 'my-shares'" />
          <RequestsPage v-else-if="page === 'requests'" />
          <StatsPage v-else-if="page === 'stats'" />
          <AdminPage v-else-if="page === 'admin'" :section="route.params.section" />
          <ProfilePage v-else-if="page === 'profile'" />
          <AboutPage v-else-if="page === 'about'" />
          <NotFoundPage v-else />
        </Transition>
      </template>
    </main>

    <footer class="app-footer" v-if="showPoweredBy && page !== 'restoring'">
      {{ $t('Powered by') }}
      <a href="https://github.com/JuzoSuzuya20/OpenTransfer" rel="noopener">OpenTransfer</a>
    </footer>

    <ConfirmDialog />
    <Teleport to="body">
      <Transition name="ot-dialog">
        <div v-if="quickSendOpen" class="quick-send-overlay" @click.self="closeQuickSend" @keydown.esc="closeQuickSend">
          <section class="ot-card quick-send" role="dialog" aria-modal="true" aria-labelledby="h-quick-send">
            <header class="quick-send-head">
              <h2 id="h-quick-send">{{ t('nav.new_share') }}</h2>
              <button ref="quickClose" type="button" class="ot-act icon-only" :aria-label="t('send.close')" :title="t('send.close')" @click="closeQuickSend">
                <X aria-hidden="true" />
              </button>
            </header>
            <Uploader ref="quickUploader" compact @more-options="quickMoreOptions" @close="quickSendOpen = false" />
          </section>
        </div>
      </Transition>
    </Teleport>

    <CommandPalette ref="commandPalette" v-if="store.isLoggedIn() && !store.isGuest()" />
  </TolgeeProvider>
</template>
