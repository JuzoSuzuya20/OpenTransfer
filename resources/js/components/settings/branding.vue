<script setup>
import { emitter } from '../../store'
import { ref, onMounted, watch, defineExpose, onBeforeUnmount, computed } from 'vue'
import { RotateCcw } from 'lucide-vue-next'

import {
  getSettingsByGroup,
  saveSettingsById,
  saveLogo,
  resetLogo,
  saveFavicon,
  deleteFavicon,
  resetFavicon,
  getFaviconStatus
} from '../../api'
import FileInput from '../fileInput.vue'
import { useToast } from 'vue-toastification'
import { niceFileName, mapSettings } from '../../utils'
import { useConfirmDialog } from '../../composables/useConfirmDialog'

import { useTranslate } from '@tolgee/vue'

const { t } = useTranslate()
const toast = useToast()
const confirmDialog = useConfirmDialog()

const settings = ref({
  logo: null,
  logo_width: '',
  show_powered_by: true
})

const faviconStatus = ref({
  has_custom_favicon: false,
  filename: null
})
const pendingFavicon = ref(null)  // File waiting to be uploaded on save

const settingsLoaded = ref(false)
const saving = ref(false)
const logoTimestamp = ref(Date.now())
const faviconTimestamp = ref(Date.now())


onMounted(async () => {
  await loadSettings()
})

const loadSettings = async () => {
  try {
    const loadedSettings = mapSettings(await getSettingsByGroup('ui.*'))
    // Use the logo setting value as a string to show in the input
    // This is just for display - when saving, we only save if it's a File object
    settings.value = {
      ...loadedSettings,
      // If logo is a string from DB, keep it for display. If it's null/empty, set to null
      // If user has selected a File object, that takes precedence
      logo: settings.value.logo instanceof File ? settings.value.logo : (loadedSettings.logo || null)
    }

    settingsLoaded.value = true
  } catch (error) {
    toast.error('Failed to load settings')
    console.error(error)
  }

  loadFaviconStatus()
}

const loadFaviconStatus = async () => {
  try {
    faviconStatus.value = await getFaviconStatus()
  } catch (error) {
    console.error('Failed to load favicon status', error)
  }
}

// Track object URLs for local file previews
const logoObjectUrl = ref(null)
const faviconObjectUrl = ref(null)

// Watch for logo file changes to create/revoke object URLs
watch(() => settings.value.logo, (newVal, oldVal) => {
  // Revoke old URL if it exists
  if (logoObjectUrl.value) {
    URL.revokeObjectURL(logoObjectUrl.value)
    logoObjectUrl.value = null
  }
  // Create new URL if new value is a File
  if (newVal instanceof File) {
    logoObjectUrl.value = URL.createObjectURL(newVal)
  }
}, { immediate: true })

// Watch for favicon file changes to create/revoke object URLs
watch(pendingFavicon, (newVal, oldVal) => {
  // Revoke old URL if it exists
  if (faviconObjectUrl.value) {
    URL.revokeObjectURL(faviconObjectUrl.value)
    faviconObjectUrl.value = null
  }
  // Create new URL if new value is a File
  if (newVal instanceof File) {
    faviconObjectUrl.value = URL.createObjectURL(newVal)
  }
}, { immediate: true })

const logoPreview = computed(() => {
  // If user has selected a new file, show local preview
  if (settings.value.logo instanceof File && logoObjectUrl.value) {
    return {
      url: logoObjectUrl.value,
      filename: settings.value.logo.name
    }
  }
  // Otherwise show current server logo
  return {
    url: `/images/logo.png?t=${logoTimestamp.value}`
  }
})

const faviconPreview = computed(() => {
  // If user has selected a new file, show local preview
  if (pendingFavicon.value instanceof File && faviconObjectUrl.value) {
    return {
      url: faviconObjectUrl.value,
      filename: pendingFavicon.value.name
    }
  }
  // Otherwise show current server favicon
  return {
    url: `/api/favicon?t=${faviconTimestamp.value}`,
    filename: faviconStatus.value.has_custom_favicon ? faviconStatus.value.filename : 'icon.svg'
  }
})

const saveSettings = async () => {
  console.log('saving settings')
  saving.value = true
  try {
    // Upload logo if user selected a new file
    if (settings.value.logo instanceof File) {
      await saveLogo(settings.value.logo)
      logoTimestamp.value = Date.now()
      // Update the logo in the browser by forcing a cache refresh
      updateBrowserLogo()
      emitter.emit('logoChanged', false)
      // Clear the File object after successful upload
      settings.value.logo = settings.value.logo.name
    }

    // Upload favicon if user selected a new file
    if (pendingFavicon.value instanceof File) {
      await saveFavicon(pendingFavicon.value)
      faviconTimestamp.value = Date.now()
      // Update the favicon in the browser
      updateBrowserFavicon()
      // Clear pending and reload status
      pendingFavicon.value = null
      loadFaviconStatus()
    }

    // Only the settings this page shows: the colours and backgrounds of the former themes are left as stored
    await saveSettingsById({ logo_width: settings.value.logo_width, show_powered_by: settings.value.show_powered_by })

    saving.value = false
    toast.success(t.value('settings.branding.settings_saved'))
  } catch (error) {
    saving.value = false
    // Use error code for specific translated message, fallback to generic
    const errorKey = error.code 
      ? `settings.branding.errors.${error.code}`
      : 'settings.branding.failed_to_save_settings'
    toast.error(t.value(errorKey, t.value('settings.branding.failed_to_save_settings')))
    console.error(error)
  }
}

const handleDeleteFavicon = async () => {
  const reallyDelete = confirm(t.value('settings.branding.confirm_delete_favicon'))
  if (!reallyDelete) {
    return
  }
  deleteFavicon()
    .then((data) => {
      faviconTimestamp.value = Date.now()
      loadFaviconStatus()
      toast.success(t.value('settings.branding.favicon_deleted'))
      // Update the favicon in the browser
      updateBrowserFavicon()
    })
    .catch((error) => {
      toast.error(t.value('settings.branding.favicon_delete_failed'))
    })
}

const handleResetLogo = async () => {
  const reallyReset = await confirmDialog.show({
    title: t.value('settings.branding.confirm'),
    message: t.value('settings.branding.confirm_reset_logo'),
    okText: t.value('settings.branding.reset'),
    cancelText: t.value('settings.close')
  })
  if (!reallyReset) {
    return
  }
  resetLogo()
    .then((data) => {
      logoTimestamp.value = Date.now()
      settings.value.logo = 'erugo-logo.png' // Show default logo filename in input
      toast.success(t.value('settings.branding.logo_reset_success'))
      // Update the logo in the browser by forcing a refresh
      updateBrowserLogo()
      emitter.emit('logoChanged', true)
    })
    .catch((error) => {
      toast.error(t.value('settings.branding.logo_reset_failed'))
    })
}

const handleResetFavicon = async () => {
  const reallyReset = await confirmDialog.show({
    title: t.value('settings.branding.confirm'),
    message: t.value('settings.branding.confirm_reset_favicon'),
    okText: t.value('settings.branding.reset'),
    cancelText: t.value('settings.close')
  })
  if (!reallyReset) {
    return
  }
  resetFavicon()
    .then((data) => {
      faviconTimestamp.value = Date.now()
      loadFaviconStatus()
      toast.success(t.value('settings.branding.favicon_reset_success'))
      // Update the favicon in the browser
      updateBrowserFavicon()
    })
    .catch((error) => {
      toast.error(t.value('settings.branding.favicon_reset_failed'))
    })
}

const updateBrowserLogo = () => {
  const logo = document.getElementById('logo')
  if (logo) {
    logo.src = `/images/logo.png?t=${Date.now()}`
  }
}

const updateBrowserFavicon = () => {
  // Find existing favicon link or create one
  let link = document.querySelector("link[rel~='icon']")
  if (!link) {
    link = document.createElement('link')
    link.rel = 'icon'
    document.head.appendChild(link)
  }
  // Add timestamp to bust cache
  link.href = `/api/favicon?t=${Date.now()}`
}

onBeforeUnmount(() => {
  // Clean up object URLs to prevent memory leaks
  if (logoObjectUrl.value) {
    URL.revokeObjectURL(logoObjectUrl.value)
  }
  if (faviconObjectUrl.value) {
    URL.revokeObjectURL(faviconObjectUrl.value)
  }
})

defineExpose({
  saveSettings
})
</script>

<template>
  <div class="admin-sections branding-settings">
    <section class="ot-card setting-group" id="logo-settings" aria-labelledby="h-logo">
      <h2 class="ot-card-title" id="h-logo">{{ $t('settings.branding.logo_and_favicon') }}</h2>

      <div class="branding-fields">
        <div class="input-container">
          <label for="logoFile">{{ $t('settings.branding.logo_image') }}</label>
          <FileInput id="logoFile" v-model="settings.logo" accept="image/png, image/svg+xml" :preview="logoPreview">
            <template #actions>
              <button
                type="button"
                class="ot-act icon-only"
                @click="handleResetLogo"
                :title="$t('settings.branding.reset_logo_to_default')"
                :aria-label="$t('settings.branding.reset_logo_to_default')"
              >
                <RotateCcw aria-hidden="true" />
              </button>
            </template>
          </FileInput>
          <p class="help-text">{{ $t('settings.branding.logo_description') }}</p>
        </div>

        <div class="input-container">
          <label for="faviconFile">{{ $t('settings.branding.favicon') }}</label>
          <FileInput
            id="faviconFile"
            v-model="pendingFavicon"
            accept="image/png, image/svg+xml"
            :label="faviconPreview.filename || $t('settings.branding.upload_favicon')"
            :preview="faviconPreview"
          >
            <template #actions>
              <button
                type="button"
                class="ot-act icon-only"
                @click="handleResetFavicon"
                :title="$t('settings.branding.reset_favicon_to_default')"
                :aria-label="$t('settings.branding.reset_favicon_to_default')"
              >
                <RotateCcw aria-hidden="true" />
              </button>
            </template>
          </FileInput>
          <p class="help-text">{{ $t('settings.branding.favicon_description') }}</p>
        </div>

        <div class="input-container logo-width">
          <label for="logoWidth">
            {{ $t('settings.branding.logo_width') }}
            <small>({{ $t('settings.branding.in_pixels') }})</small>
          </label>
          <input type="number" id="logoWidth" min="1" v-model="settings.logo_width" />
        </div>
      </div>
    </section>

    <section class="ot-card setting-group" id="other-ui-settings" aria-labelledby="h-other-ui">
      <h2 class="ot-card-title" id="h-other-ui">{{ $t('settings.branding.other_ui_settings') }}</h2>
      <div class="checkbox-container">
        <input type="checkbox" v-model="settings.show_powered_by" id="showPoweredBy" />
        <label for="showPoweredBy">{{ $t('settings.branding.show_powered_by') }}</label>
      </div>
      <p class="help-text">{{ $t('settings.branding.show_powered_by_description') }}</p>
    </section>
  </div>
</template>

<style scoped lang="scss">
// Each choice with its explanation right under it, two columns on a wide screen
.branding-fields {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 420px), 1fr));
  gap: 22px 40px;
  align-items: start;
}
.logo-width input {
  max-width: 160px;
}
.help-text {
  margin: 6px 0 0;
  max-width: 65ch;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: var(--ot-muted);
}
</style>
