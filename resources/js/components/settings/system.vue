<script setup>
import { ref, onMounted, defineExpose, inject, computed, nextTick, watch } from 'vue'
import OtPassword from '../layout/OtPassword.vue'
import {
  Settings,
  Tag,
  Share2,
  Send,
  AtSign,
  Fingerprint,
  MessageCircleQuestion,
  ShieldCheck,
  ShieldBan,
  Eye,
  EyeOff,
  Plus,
  Trash,
  ExternalLink,
  Database,
  Download,
  Loader2,
  MailCheck,
  RefreshCw
} from 'lucide-vue-next'
import {
  getSettingsByGroup,
  saveSettingsById,
  getBackups,
  createBackup,
  downloadBackup,
  deleteBackup,
  getClientIp,
  getStorageEncryptionStatus,
  testSmtpSettings
} from '../../api'
import HelpTip from '../helpTip.vue'
import AppLink from '../layout/AppLink.vue'
import OtSelect from '../layout/OtSelect.vue'
import OtNumber from '../layout/OtNumber.vue'
import Expand from '../layout/Expand.vue'
import OtSkeleton from '../layout/OtSkeleton.vue'
import { atLeast } from '../../lib/atLeast'
import { SHIPPED_LOGIN_MESSAGE } from '../../lib/loginMessage'

import { useToast } from '../../toasts'
import { mapSettings, niceFileSize } from '../../utils'
import { patternBits, MIN_PATTERN_BITS } from '../../lib/patternBits'
import { notifySettingsChanged } from '../../composables/useSetting'
import { useConfirmDialog } from '../../composables/useConfirmDialog'

import { useTranslate, useTolgee } from '@tolgee/vue'

// The section the administration's menu opened
defineProps({
  part: { type: String, default: 'general' }
})

const { t } = useTranslate()
const useBackupLanguage = () => {
  const tolgee = useTolgee(['language'])
  return { language: computed(() => tolgee.value.getLanguage() || 'en') }
}

// Each language under its name in the admin's language, in the order of the other lists
const defaultLanguageOptions = computed(() =>
  [
    ['en', 'english'],
    ['de', 'german'],
    ['es', 'spanish'],
    ['fr', 'french'],
    ['it', 'italian'],
    ['nl', 'dutch'],
    ['pt', 'portuguese']
  ].map(([value, name]) => ({ value, label: t.value(`settings.system.languages.${name}`) }))
)

const showHelpTip = inject('showHelpTip')
const toast = useToast()
const confirmDialog = useConfirmDialog()

const clientIp = ref(null)
const storageEncryption = ref({ key_configured: false, misconfigured: false })

const settings = ref({
  login_ip_allowlist_enabled: false,
  login_ip_allowlist: '',
  mfa_required_for: 'none',
  passkey_login_enabled: false,
  storage_encryption_enabled: false,
  altcha_enabled: false,
  altcha_max_number: 100000,
  application_name: '',
  application_url: '',
  login_message: '',
  default_expiry_time: '',
  max_expiry_time: '',
  max_share_size: '',
  max_share_size_unit: '',
  clean_files_after_days: '',
  share_url_mode: 'haiku',
  share_url_pattern: '********',
  emails_share_downloaded_enabled: '',
  smtp_host: '',
  smtp_port: '',
  smtp_encryption: 'tls',
  smtp_username: '',
  smtp_password: '',
  smtp_sender_name: '',
  smtp_sender_address: '',
  self_registration_enabled: false,
  self_registration_allow_any_domain: true,
  self_registration_allowed_domains: ''
})

// Pattern presets for share URL generation: each one strong enough (45 bits, PatternGenerator)
const PATTERN_PRESETS = [
  { id: 'short', pattern: '********' },
  { id: 'random16', pattern: '****************' },
  { id: 'uuid', pattern: 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX' },
  { id: 'hex12', pattern: 'XXXXXXXXXXXX' },
  { id: 'custom', pattern: '' }
]
const patternPresets = computed(() => PATTERN_PRESETS.map((preset) => ({ ...preset, name: t.value(`settings.system.pattern_preset.${preset.id}`) })))

// How much chance the links of the pattern carry: the server refuses one under the minimum
const patternStrength = computed(() => {
  const bits = Math.round(patternBits(settings.value.share_url_pattern || ''))
  return { bits, strong: bits >= MIN_PATTERN_BITS }
})

const selectedPreset = ref('short')
const patternPreview = ref('')

// Generate a preview of the pattern
const generatePatternPreview = () => {
  const pattern = settings.value.share_url_pattern
  if (!pattern) {
    patternPreview.value = ''
    return
  }
  
  // Client-side pattern preview generation
  let result = ''
  const length = pattern.length
  let i = 0
  
  const DIGITS = '0123456789'
  const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
  const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz'
  const HEX = '0123456789ABCDEF'
  const ALPHANUMERIC = DIGITS + UPPERCASE + LOWERCASE
  
  const randomChar = (charset) => charset[Math.floor(Math.random() * charset.length)]
  
  const expandRange = (start, end) => {
    const chars = []
    const startCode = start.charCodeAt(0)
    const endCode = end.charCodeAt(0)
    const [from, to] = startCode <= endCode ? [startCode, endCode] : [endCode, startCode]
    for (let c = from; c <= to; c++) {
      chars.push(String.fromCharCode(c))
    }
    return chars
  }
  
  const expandCharClass = (content) => {
    const chars = []
    let j = 0
    while (j < content.length) {
      const ch = content[j]
      if (ch === '\\' && j + 1 < content.length) {
        chars.push(content[j + 1])
        j += 2
        continue
      }
      if (j + 2 < content.length && content[j + 1] === '-') {
        const rangeChars = expandRange(ch, content[j + 2])
        chars.push(...rangeChars)
        j += 3
        continue
      }
      chars.push(ch)
      j++
    }
    return [...new Set(chars)]
  }
  
  while (i < length) {
    const char = pattern[i]
    
    // Handle escape sequences
    if (char === '\\' && i + 1 < length) {
      const nextChar = pattern[i + 1]
      if (['#', 'A', 'a', '*', 'X', '\\'].includes(nextChar)) {
        result += nextChar
        i += 2
        continue
      }
      result += char
      i++
      continue
    }
    
    // Handle character classes [...]
    if (char === '[') {
      const closePos = pattern.indexOf(']', i + 1)
      if (closePos !== -1) {
        const classContent = pattern.substring(i + 1, closePos)
        const chars = expandCharClass(classContent)
        if (chars.length > 0) {
          result += randomChar(chars.join(''))
        }
        i = closePos + 1
        continue
      }
      result += char
      i++
      continue
    }
    
    // Handle special tokens
    if (char === '#') {
      result += randomChar(DIGITS)
      i++
      continue
    }
    if (char === 'A') {
      result += randomChar(UPPERCASE)
      i++
      continue
    }
    if (char === 'a') {
      result += randomChar(LOWERCASE)
      i++
      continue
    }
    if (char === '*') {
      result += randomChar(ALPHANUMERIC)
      i++
      continue
    }
    if (char === 'X') {
      result += randomChar(HEX)
      i++
      continue
    }
    
    // Literal character
    result += char
    i++
  }
  
  patternPreview.value = result
}

// Watch for pattern changes to update preview
watch(() => settings.value.share_url_pattern, () => {
  generatePatternPreview()
  // Update selected preset to 'custom' if pattern doesn't match any preset
  const matchingPreset = PATTERN_PRESETS.find(p => p.pattern === settings.value.share_url_pattern)
  if (matchingPreset) {
    selectedPreset.value = matchingPreset.id
  } else {
    selectedPreset.value = 'custom'
  }
})

// Handle preset selection
const handlePresetChange = () => {
  const preset = PATTERN_PRESETS.find(p => p.id === selectedPreset.value)
  if (preset && preset.pattern) {
    settings.value.share_url_pattern = preset.pattern
  }
  generatePatternPreview()
}

const settingsLoaded = ref(false)
const saving = ref(false)

// Backup state
const backups = ref([])
const backupsLoading = ref(false)
const backupCreating = ref(false)
const backupDownloading = ref(null)
const backupDeleting = ref(null)

const emit = defineEmits(['navItemClicked'])

onMounted(async () => {
  await loadSettings()
  await loadBackups()
  try {
    clientIp.value = await getClientIp()
    storageEncryption.value = await getStorageEncryptionStatus()
  } catch (error) {
    console.error(error)
  }
})

const addClientIpToAllowlist = () => {
  const list = (settings.value.login_ip_allowlist || '').trimEnd()
  settings.value.login_ip_allowlist = list ? `${list}\n${clientIp.value}` : clientIp.value
}

const saveFailureMessage = (error) => {
  if (error.code === 'backup_schedule_invalid') {
    return t.value('settings.system.backups.schedule_invalid')
  }
  if (error.code === 'ip_allowlist_lockout') {
    return t.value('settings.system.security.lockout_error', { ip: error.data?.ip })
  }
  if (error.code === 'altcha_difficulty_invalid') {
    return t.value('settings.system.security.altcha_difficulty_help')
  }
  if (error.code === 'storage_key_missing') {
    return t.value('settings.system.security.encryption_key_absent')
  }
  if (error.code === 'share_url_pattern_weak') {
    return t.value('settings.system.share_url_pattern_weak', { bits: Math.round(error.data?.bits ?? 0), min: error.data?.minimum_bits ?? MIN_PATTERN_BITS })
  }
  if (error.code === 'ip_allowlist_invalid') {
    return t.value('settings.system.security.invalid_entries', { entries: error.data?.invalid?.join(', ') })
  }
  return t.value('settings.failedToSaveSettings')
}

const smtpPasswordSet = ref(false)
// A test e-mail to the admin, with the values of the form, saved or not
const smtpTesting = ref(false)
const smtpTestResult = ref(null)
const runSmtpTest = async () => {
  smtpTesting.value = true
  smtpTestResult.value = null
  try {
    const data = await testSmtpSettings({
      smtp_host: settings.value.smtp_host,
      smtp_port: settings.value.smtp_port,
      smtp_username: settings.value.smtp_username,
      smtp_password: settings.value.smtp_password,
      smtp_encryption: settings.value.smtp_encryption,
      smtp_sender_address: settings.value.smtp_sender_address,
      smtp_sender_name: settings.value.smtp_sender_name
    })
    smtpTestResult.value = { ok: true, text: t.value('settings.system.smtp_test.sent', { email: data.sent_to }) }
  } catch (error) {
    let text = t.value('settings.system.smtp_test.failed', { reason: error.data?.reason || error.message })
    if (error.code === 'smtp_not_configured') text = t.value('settings.system.smtp_test.not_configured')
    if (error.status === 429) text = t.value('settings.system.smtp_test.too_many')
    smtpTestResult.value = { ok: false, text }
  } finally {
    smtpTesting.value = false
  }
}
const loadSettings = async () => {
  try {
    const system = await getSettingsByGroup('system.*')
    settings.value = {
      ...mapSettings(system),
      ...mapSettings(await getSettingsByGroup('ui.*'))
    }
    // Never sent back: the field says whether one is saved, and left empty keeps it
    smtpPasswordSet.value = Boolean(system.find((setting) => setting.key === 'smtp_password')?.is_set)
    // The message installed with the app shows as empty: the field's placeholder is its translation
    if (settings.value.login_message === SHIPPED_LOGIN_MESSAGE) settings.value.login_message = ''

    settingsLoaded.value = true
  } catch (error) {
    toast.error(t.value('settings.failedToLoadSettings'))
    console.error(error)
  }
}

// Backup methods
const backupsLoaded = ref(false)
// The automatic backups: when the next one runs (from the settings saved), in the zone of the server
const nextBackupAt = ref(null)
const backupTimezone = ref('')
const { language } = useBackupLanguage()
const backupFrequencyOptions = computed(() =>
  ['off', 'daily', 'weekly', 'monthly'].map((value) => ({ value, label: t.value(`settings.system.backups.frequency_${value}`) }))
)
// 1 Monday … 7 Sunday, named in the language of the screen (2024-01-01 was a Monday)
const backupWeekdayOptions = computed(() => {
  const name = new Intl.DateTimeFormat(language.value, { weekday: 'long', timeZone: 'UTC' })
  return [1, 2, 3, 4, 5, 6, 7].map((day) => ({ value: day, label: name.format(new Date(Date.UTC(2024, 0, day))) }))
})
const backupMonthdayOptions = Array.from({ length: 28 }, (_, i) => ({ value: i + 1, label: String(i + 1) }))
const nextBackupText = computed(() => {
  if (!nextBackupAt.value) return ''
  const date = new Intl.DateTimeFormat(language.value, { dateStyle: 'full', timeStyle: 'short', timeZone: backupTimezone.value || undefined })
  return t.value('settings.system.backups.next', { date: date.format(new Date(nextBackupAt.value)), zone: backupTimezone.value })
})
const loadBackups = async () => {
  if (backupsLoading.value) return
  backupsLoading.value = true
  try {
    // One turn of the icon at least: a quick answer would stop it half a turn in
    const data = await atLeast(700, getBackups())
    backups.value = data.backups || []
    nextBackupAt.value = data.next_backup_at || null
    backupTimezone.value = data.timezone || ''
  } catch (error) {
    console.error('Failed to load backups:', error)
    toast.error(t.value('settings.system.backups.load_failed'))
  } finally {
    backupsLoading.value = false
    backupsLoaded.value = true
  }
}

const handleCreateBackup = async () => {
  backupCreating.value = true
  try {
    const data = await createBackup()
    toast.success(t.value('settings.system.backups.create_success'))
    await loadBackups()
  } catch (error) {
    console.error('Failed to create backup:', error)
    toast.error(t.value('settings.system.backups.create_failed'))
  } finally {
    backupCreating.value = false
  }
}

const handleDownloadBackup = async (filename) => {
  backupDownloading.value = filename
  try {
    await downloadBackup(filename)
    toast.success(t.value('settings.system.backups.download_success'))
  } catch (error) {
    console.error('Failed to download backup:', error)
    toast.error(t.value('settings.system.backups.download_failed'))
  } finally {
    backupDownloading.value = null
  }
}

const handleDeleteBackup = async (filename) => {
  const confirmed = await confirmDialog.show({
    title: t.value('settings.system.backups.delete'),
    message: t.value('settings.system.backups.delete_confirmation'),
    okText: t.value('settings.system.backups.delete'),
    cancelText: t.value('dialog.cancel'),
    tone: 'danger'
  })

  if (!confirmed) {
    return
  }
  
  backupDeleting.value = filename
  try {
    await deleteBackup(filename)
    toast.success(t.value('settings.system.backups.delete_success'))
    await loadBackups()
  } catch (error) {
    console.error('Failed to delete backup:', error)
    toast.error(t.value('settings.system.backups.delete_failed'))
  } finally {
    backupDeleting.value = null
  }
}

const formatBackupDate = (dateString) => {
  const date = new Date(dateString)
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short'
  }).format(date)
}

const saveSettings = async () => {

  if (!shareSettingsLookOk()) {
    return
  }

  //check if settings.application_url ends with a / and remove it if it does
  if (settings.value.application_url.endsWith('/')) {
    settings.value.application_url = settings.value.application_url.slice(0, -1)
  }

  saving.value = true
  try {
    await saveSettingsById({
      ...settings.value
    })

    saving.value = false
    toast.success(t.value('settings.settingsSavedSuccessfully'))
    await loadSettings()
    // The next automatic backup follows the schedule just saved
    if (backupsLoaded.value) loadBackups()
    
    // Notify other components that settings have changed
    notifySettingsChanged()
  } catch (error) {
    saving.value = false
    toast.error(saveFailureMessage(error))
    console.error(error)
  }
}

const shareSettingsLookOk = () => {
  return true
}

const handleNavItemClicked = (item) => {
  emit('navItemClicked', item)
}

//define exposed methods
defineExpose({
  saveSettings
})

</script>
<template>
  <div class="system-settings">
    <!-- A long page: its sections, one link each -->
    <!-- One section at a time: the menu of the administration says which (prop part) -->
    <div class="admin-sections" v-if="settingsLoaded">
        <div class="setting-group" id="general" v-show="part === 'general'">
              <div class="setting-group-header">
                <h3>
                  <Settings />
                  {{ $t('settings.system.general') }}
                </h3>
              </div>

              <div class="setting-group-body">
                <div class="setting-group-body-item">
                  <label for="application_name">{{ $t('settings.system.application_name') }}</label>
                  <input type="text" id="application_name" v-model="settings.application_name" />
                  <p class="help-text">{{ $t('settings.system.application_name_description') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <label for="application_url">{{ $t('settings.system.application_url') }}</label>
                  <input type="text" id="application_url" v-model="settings.application_url" />
                  <p class="help-text">{{ $t('settings.system.application_url_description') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <label for="login_message">{{ $t('settings.system.login_message') }}</label>
                  <input
                    type="text"
                    id="login_message"
                    v-model="settings.login_message"
                    :placeholder="$t('auth.login_message_default')"
                  />
                  <p class="help-text">{{ $t('settings.system.login_message_description') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <label for="default_language">{{ $t('settings.system.default_language') }}</label>
                  <OtSelect id="default_language" v-model="settings.default_language" :options="defaultLanguageOptions" />
                  <p class="help-text">{{ $t('settings.system.default_language_description') }}</p>
                </div>

                <div class="setting-group-body-item mt-3">
                  <div class="checkbox-container">
                    <input type="checkbox" id="show_language_selector" v-model="settings.show_language_selector" />
                    <label for="show_language_selector">{{ $t('settings.system.show_language_selector') }}</label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.show_language_selector_description') }}</p>
                </div>
              </div>
            </div>


        <div class="setting-group" id="shares" v-show="part === 'shares'">
              <div class="setting-group-header">
                <h3>
                  <Share2 />
                  {{ $t('settings.system.shares') }}
                </h3>
              </div>

              <div class="setting-group-body">
                <div class="setting-group-body-item">
                  <label for="default_expiry_time">
                    {{ $t('settings.system.default_expiry_time') }}
                    <small>({{ $t('settings.system.days') }})</small>
                  </label>
                  <OtNumber id="default_expiry_time" v-model="settings.default_expiry_time" :min="1" placeholder="7" />
                </div>
                <div class="setting-group-body-item">
                  <label for="max_expiry_time">
                    {{ $t('settings.system.max_expiry_time') }}
                    <small>({{ $t('settings.system.days') }})</small>
                  </label>
                  <OtNumber id="max_expiry_time" v-model="settings.max_expiry_time" :min="1" placeholder="∞" />
                  <p class="help-text">{{ $t('settings.system.max_expiry_time_description') }}</p>
                </div>
                <div class="setting-group-body-item">
                  <label for="max_share_size">{{ $t('settings.system.max_share_size') }}</label>
                  <div class="size-with-unit">
                    <OtNumber id="max_share_size" v-model="settings.max_share_size" :min="1" />
                    <OtSelect
                      id="max_share_size_unit"
                      v-model="settings.max_share_size_unit"
                      :options="[{ value: 'MB', label: 'MB' }, { value: 'GB', label: 'GB' }]"
                      :aria-label="$t('settings.system.max_share_size')"
                    />
                  </div>
                  <p class="help-text">{{ $t('settings.system.max_share_size_description') }}</p>
                </div>
                <div class="setting-group-body-item">
                  <label for="clean_files_after_days">
                    {{ $t('settings.system.clean_files_after') }}
                    <small>({{ $t('settings.system.days') }})</small>
                  </label>
                  <OtNumber id="clean_files_after_days" v-model="settings.clean_files_after_days" :min="1" placeholder="30" />
                  <p class="help-text">{{ $t('settings.system.clean_files_after_description') }}</p>
                </div>
                <h3 id="reverse_shares" class="setting-subtitle">{{ $t('settings.system.reverse_shares') }}</h3>
                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input type="checkbox" id="allow_reverse_shares" v-model="settings.allow_reverse_shares" />
                    <label for="allow_reverse_shares">{{ $t('settings.system.allow_reverse_shares') }}</label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.allow_reverse_shares_description') }}</p>
                </div>

                <h3 id="share_url_generation" class="setting-subtitle">{{ $t('settings.system.share_url_generation') }}</h3>
                <div class="setting-group-body-item">
                  <label for="share_url_mode">{{ $t('settings.system.share_url_mode') }}</label>
                  <OtSelect
                    id="share_url_mode"
                    v-model="settings.share_url_mode"
                    :options="[
                      { value: 'haiku', label: $t('settings.system.share_url_mode_haiku') },
                      { value: 'pattern', label: $t('settings.system.share_url_mode_pattern') }
                    ]"
                  />
                  <p class="help-text">{{ $t('settings.system.share_url_mode_description') }}</p>
                </div>

                <Expand :show="settings.share_url_mode === 'pattern'">
                  <div class="pattern-url-settings">
                    <div class="setting-group-body-item">
                      <label for="share_url_preset">{{ $t('settings.system.share_url_preset') }}</label>
                      <OtSelect
                        id="share_url_preset"
                        v-model="selectedPreset"
                        :options="patternPresets.map((preset) => ({ value: preset.id, label: preset.name }))"
                        @change="handlePresetChange"
                      />
                    </div>
                    <div class="setting-group-body-item">
                      <label for="share_url_pattern">{{ $t('settings.system.share_url_pattern') }}</label>
                      <input
                        type="text"
                        id="share_url_pattern"
                        v-model="settings.share_url_pattern"
                        :placeholder="$t('settings.system.share_url_pattern_placeholder')"
                      />
                      <p class="help-text">{{ $t('settings.system.share_url_pattern_description') }}</p>
                      <p class="help-text pattern-strength" :class="{ 'is-weak': !patternStrength.strong }" aria-live="polite" v-if="settings.share_url_pattern">
                        {{ patternStrength.strong
                          ? $t('settings.system.share_url_pattern_strong', { bits: patternStrength.bits })
                          : $t('settings.system.share_url_pattern_weak', { bits: patternStrength.bits, min: MIN_PATTERN_BITS }) }}
                      </p>
  <Expand :show="settings.share_url_mode === 'pattern'">
    <div>
                    <div class="pattern-syntax-help">
                      <h6>{{ $t('settings.system.share_url_pattern_syntax') }}</h6>
                      <ul class="syntax-list">
                        <li><code>#</code> {{ $t('settings.system.pattern_token_digit') }}</li>
                        <li><code>A</code> {{ $t('settings.system.pattern_token_uppercase') }}</li>
                        <li><code>a</code> {{ $t('settings.system.pattern_token_lowercase') }}</li>
                        <li><code>*</code> {{ $t('settings.system.pattern_token_alphanumeric') }}</li>
                        <li><code>X</code> {{ $t('settings.system.pattern_token_hex') }}</li>
                        <li><code>[A-Z]</code> {{ $t('settings.system.pattern_token_range') }}</li>
                        <li><code>\#</code> {{ $t('settings.system.pattern_token_escape') }}</li>
                      </ul>
                    </div>
                  </div>
  </Expand>
  </div>
                    <Expand :show="settings.share_url_pattern">
                      <div class="setting-group-body-item pattern-preview">
                        <label>{{ $t('settings.system.share_url_pattern_preview') }}</label>
                        <div class="preview-box">
                          <code>{{ patternPreview }}</code>
                          <button type="button" class="refresh-preview" @click="generatePatternPreview">
                            ↻
                          </button>
                        </div>
                      </div>
                    </Expand>
                  </div>
                </Expand>

              </div>
            </div>


        <div class="setting-group" id="emails" v-show="part === 'emails'">
              <div class="setting-group-header">
                <h3>
                  <Send />
                  {{ $t('settings.system.emails') }}
                </h3>
              </div>

              <div class="setting-group-body">
                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input
                      type="checkbox"
                      id="emails_share_downloaded_enabled"
                      v-model="settings.emails_share_downloaded_enabled"
                    />
                    <label for="emails_share_downloaded_enabled">
                      {{ $t('settings.system.enable_share_downloaded_emails') }}
                    </label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.share_downloaded_emails_description') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input
                      type="checkbox"
                      id="emails_share_expiry_warning_enabled"
                      v-model="settings.emails_share_expiry_warning_enabled"
                    />
                    <label for="emails_share_expiry_warning_enabled">
                      {{ $t('settings.system.enable_share_expiry_warning_emails') }}
                    </label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.share_expiry_warning_emails_description') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input
                      type="checkbox"
                      id="emails_share_expired_warning_enabled"
                      v-model="settings.emails_share_expired_warning_enabled"
                    />
                    <label for="emails_share_expired_warning_enabled">
                      {{ $t('settings.system.enable_share_expired_warning_emails') }}
                    </label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.share_expired_warning_emails_description') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input
                      type="checkbox"
                      id="emails_share_deletion_warning_enabled"
                      v-model="settings.emails_share_deletion_warning_enabled"
                    />
                    <label for="emails_share_deletion_warning_enabled">
                      {{ $t('settings.system.enable_share_deletion_warning_emails') }}
                    </label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.share_deletion_warning_emails_description') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input
                      type="checkbox"
                      id="emails_share_deleted_enabled"
                      v-model="settings.emails_share_deleted_enabled"
                    />
                    <label for="emails_share_deleted_enabled">
                      {{ $t('settings.system.enable_share_deleted_emails') }}
                    </label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.share_deleted_emails_description') }}</p>
                </div>
              </div>
            </div>


        <div class="setting-group" id="smtp" v-show="part === 'smtp'">
              <div class="setting-group-header">
                <h3>
                  <AtSign />
                  {{ $t('settings.system.smtp') }}
                </h3>
              </div>

              <div class="setting-group-body">
                <!-- Hidden decoy fields to prevent browser password save prompts -->
                <input type="text" name="prevent_autofill_1" style="display:none" tabindex="-1" autocomplete="off" />
                <input type="password" name="prevent_autofill_2" style="display:none" tabindex="-1" autocomplete="off" />
                
                <div class="setting-group-body-item">
                  <label for="smtp_host">{{ $t('settings.system.smtp_host') }}</label>
                  <input type="text" id="smtp_host" v-model="settings.smtp_host" />
                  <p class="help-text">{{ $t('settings.system.smtp_host_description') }}</p>
                </div>
                <div class="setting-group-body-item">
                  <label for="smtp_port">{{ $t('settings.system.smtp_port') }}</label>
                  <OtNumber id="smtp_port" v-model="settings.smtp_port" :min="1" :max="65535" :steppers="false" />
                  <p class="help-text">{{ $t('settings.system.smtp_port_description') }}</p>
                </div>
                <div class="setting-group-body-item">
                  <label for="smtp_encryption">{{ $t('settings.system.smtp_encryption') }}</label>
                  <OtSelect
                    id="smtp_encryption"
                    v-model="settings.smtp_encryption"
                    :options="[
                      { value: 'tls', label: 'TLS' },
                      { value: 'ssl', label: 'SSL' },
                      { value: '', label: $t('settings.system.smtp_encryption_none') }
                    ]"
                  />
                  <p class="help-text">{{ $t('settings.system.smtp_encryption_description') }}</p>
                </div>
                <div class="setting-group-body-item">
                  <label for="smtp_username">{{ $t('settings.system.smtp_username') }}</label>
                  <input type="text" id="smtp_username" v-model="settings.smtp_username" autocomplete="off" />
                  <p class="help-text">{{ $t('settings.system.smtp_username_description') }}</p>
                </div>
                <div class="setting-group-body-item">
                  <label for="smtp_password">{{ $t('settings.system.smtp_password') }}</label>
                  <OtPassword id="smtp_password" v-model="settings.smtp_password" autocomplete="new-password" :placeholder="smtpPasswordSet ? '••••••••••••' : ''" />
                  <p class="help-text">
                    {{ $t('settings.system.smtp_password_description') }}
                    <template v-if="smtpPasswordSet">{{ $t('common.secret_kept') }}</template>
                  </p>
                </div>
                <div class="setting-group-body-item">
                  <label for="smtp_sender_name">{{ $t('settings.system.smtp_sender_name') }}</label>
                  <input type="text" id="smtp_sender_name" v-model="settings.smtp_sender_name" />
                  <p class="help-text">{{ $t('settings.system.smtp_sender_name_description') }}</p>
                </div>
                <div class="setting-group-body-item">
                  <label for="smtp_sender_address">{{ $t('settings.system.smtp_sender_address') }}</label>
                  <input type="text" id="smtp_sender_address" v-model="settings.smtp_sender_address" />
                  <p class="help-text">{{ $t('settings.system.smtp_sender_address_description') }}</p>
                </div>
                <!-- A test e-mail to the admin, with the values above, saved or not -->
                <div class="setting-group-body-item smtp-test">
                  <button type="button" class="secondary" @click="runSmtpTest" :disabled="smtpTesting" :aria-busy="smtpTesting">
                    <Loader2 v-if="smtpTesting" class="spinner" aria-hidden="true" />
                    <MailCheck v-else aria-hidden="true" />
                    {{ smtpTesting ? $t('settings.system.smtp_test.running') : $t('settings.system.smtp_test.button') }}
                  </button>
                  <p class="help-text">{{ $t('settings.system.smtp_test.help') }}</p>
                  <p v-if="smtpTestResult" class="smtp-test-result" :class="smtpTestResult.ok ? 'is-ok' : 'is-error'" role="status">
                    {{ smtpTestResult.text }}
                  </p>
                </div>
              </div>
            </div>


        <div class="setting-group" id="auth" v-show="part === 'auth'">
              <div class="setting-group-header">
                <h3>
                  <Fingerprint />
                  {{ $t('settings.system.auth') }}
                </h3>
              </div>

              <div class="setting-group-body">
                <!-- Self registration: the target of the link on the users page -->
                <div class="setting-group-body-item" id="self_registration">
                  <div class="checkbox-container">
                    <input
                      type="checkbox"
                      id="self_registration_enabled"
                      v-model="settings.self_registration_enabled"
                    />
                    <label for="self_registration_enabled">
                      {{ $t('settings.system.self_registration_enabled') }}
                    </label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.self_registration_description') }}</p>
                </div>

                <Expand :show="settings.self_registration_enabled">
                  <div class="self-registration-options">
                    <div class="setting-group-body-item">
                      <div class="checkbox-container">
                        <input
                          type="checkbox"
                          id="self_registration_allow_any_domain"
                          v-model="settings.self_registration_allow_any_domain"
                        />
                        <label for="self_registration_allow_any_domain">
                          {{ $t('settings.system.self_registration_allow_any_domain') }}
                        </label>
                      </div>
                    </div>
  
                    <Expand :show="!settings.self_registration_allow_any_domain">
                      <div class="setting-group-body-item">
                        <label for="self_registration_allowed_domains">
                          {{ $t('settings.system.self_registration_allowed_domains') }}
                        </label>
                        <input
                          type="text"
                          id="self_registration_allowed_domains"
                          v-model="settings.self_registration_allowed_domains"
                          :placeholder="$t('settings.system.self_registration_allowed_domains_placeholder')"
                        />
                        <p class="help-text">{{ $t('settings.system.self_registration_allowed_domains_help') }}</p>
                        <p class="help-text">{{ $t('settings.system.self_registration_allowed_domains_description') }}</p>
                      </div>
                    </Expand>
                  </div>
                </Expand>

                <p class="sso-moved">
                  {{ $t('sso.moved') }}
                  <AppLink to="/admin/sso">{{ $t('sso.title') }}</AppLink>
                </p>
              </div>
            </div>


        <div class="setting-group" id="security" v-show="part === 'security'">
              <div class="setting-group-header">
                <h3>
                  <ShieldCheck />
                  {{ $t('settings.system.security.title') }}
                </h3>
              </div>

              <div class="setting-group-body">
                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input
                      type="checkbox"
                      id="login_ip_allowlist_enabled"
                      v-model="settings.login_ip_allowlist_enabled"
                    />
                    <label for="login_ip_allowlist_enabled">
                      {{ $t('settings.system.security.ip_allowlist_enabled') }}
                    </label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.security.ip_allowlist_description') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <label for="login_ip_allowlist">{{ $t('settings.system.security.ip_allowlist_entries') }}</label>
                  <textarea
                    id="login_ip_allowlist"
                    rows="5"
                    spellcheck="false"
                    v-model="settings.login_ip_allowlist"
                  ></textarea>
                  <p class="help-text">{{ $t('settings.system.security.ip_allowlist_entries_help') }}</p>
                  <p class="help-text" v-if="clientIp">
                    {{ $t('settings.system.security.your_ip', { ip: clientIp }) }}
                    <a href="#" @click.prevent="addClientIpToAllowlist">
                      {{ $t('settings.system.security.add_your_ip') }}
                    </a>
                  </p>
                  <p class="help-text"><strong>{{ $t('settings.system.security.proxy_title') }}</strong> {{ $t('settings.system.security.proxy_help') }}</p>
                  <p class="help-text"><strong>{{ $t('settings.system.security.recovery_title') }}</strong> {{ $t('settings.system.security.recovery_help') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <label for="mfa_required_for">{{ $t('settings.system.security.mfa_policy_label') }}</label>
                  <OtSelect
                    id="mfa_required_for"
                    v-model="settings.mfa_required_for"
                    :options="[
                      { value: 'none', label: $t('settings.system.security.mfa_policy_none') },
                      { value: 'admins', label: $t('settings.system.security.mfa_policy_admins') },
                      { value: 'everyone', label: $t('settings.system.security.mfa_policy_everyone') }
                    ]"
                  />
                  <p class="help-text">{{ $t('settings.system.security.mfa_policy_help') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input type="checkbox" id="passkey_login_enabled" v-model="settings.passkey_login_enabled" />
                    <label for="passkey_login_enabled">{{ $t('settings.system.security.passkey_login') }}</label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.security.passkey_login_help') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input
                      type="checkbox"
                      id="storage_encryption_enabled"
                      v-model="settings.storage_encryption_enabled"
                      :disabled="!storageEncryption.key_configured && !settings.storage_encryption_enabled"
                    />
                    <label for="storage_encryption_enabled">{{ $t('settings.system.security.encryption_enabled') }}</label>
                  </div>
                  <p class="help-text" v-if="storageEncryption.misconfigured">
                    <strong>{{ $t('settings.system.security.encryption_misconfigured') }}</strong>
                  </p>
                  <p class="help-text" v-else-if="!storageEncryption.key_configured">
                    {{ $t('settings.system.security.encryption_key_absent') }}
                  </p>
                  <p class="help-text">{{ $t('settings.system.security.encryption_help') }}</p>
                </div>

                <div class="setting-group-body-item">
                  <div class="checkbox-container">
                    <input type="checkbox" id="altcha_enabled" v-model="settings.altcha_enabled" />
                    <label for="altcha_enabled">{{ $t('settings.system.security.altcha_enabled') }}</label>
                  </div>
                  <p class="help-text">{{ $t('settings.system.security.altcha_description') }}</p>
                </div>

                <Expand :show="settings.altcha_enabled">
                  <div class="setting-group-body-item">
                    <label for="altcha_max_number">{{ $t('settings.system.security.altcha_difficulty') }}</label>
                    <OtNumber id="altcha_max_number" :min="1000" :max="10000000" :increment="10000" v-model="settings.altcha_max_number" />
                    <p class="help-text">{{ $t('settings.system.security.altcha_difficulty_help') }}</p>
                  </div>
                </Expand>
              </div>
            </div>


        <div class="setting-group" id="backups" v-show="part === 'backups'">
              <div class="setting-group-header">
                <h3>
                  <Database />
                  {{ $t('settings.system.backups.title') }}
                </h3>
              </div>

              <div class="setting-group-body">
                <p class="card-description">{{ $t('settings.system.backups.description') }}</p>

                <!-- The automatic backups: when, and for how long -->
                <div class="setting-group-body-item">
                  <label for="backup_frequency">{{ $t('settings.system.backups.frequency') }}</label>
                  <OtSelect id="backup_frequency" v-model="settings.backup_frequency" :options="backupFrequencyOptions" />
                  <p class="help-text" v-if="settings.backup_frequency === 'off'">{{ $t('settings.system.backups.off_help') }}</p>
                </div>
                <Expand :show="settings.backup_frequency !== 'off'">
                  <div class="backup-schedule">
                    <div class="setting-group-body-item" v-if="settings.backup_frequency === 'weekly'">
                      <label for="backup_weekday">{{ $t('settings.system.backups.weekday') }}</label>
                      <OtSelect id="backup_weekday" v-model="settings.backup_weekday" :options="backupWeekdayOptions" />
                    </div>
                    <div class="setting-group-body-item" v-if="settings.backup_frequency === 'monthly'">
                      <label for="backup_monthday">{{ $t('settings.system.backups.monthday') }}</label>
                      <OtSelect id="backup_monthday" v-model="settings.backup_monthday" :options="backupMonthdayOptions" />
                    </div>
                    <div class="setting-group-body-item">
                      <label for="backup_time">{{ $t('settings.system.backups.time') }}</label>
                      <input type="time" id="backup_time" v-model="settings.backup_time" step="60" required />
                    </div>
                    <div class="setting-group-body-item">
                      <label for="backup_keep_days">
                        {{ $t('settings.system.backups.keep_days') }}
                        <small>({{ $t('settings.system.days') }})</small>
                      </label>
                      <OtNumber id="backup_keep_days" :min="1" :max="3650" v-model="settings.backup_keep_days" />
                    </div>
                  </div>
                  <p class="help-text">{{ $t('settings.system.backups.keep_help') }}</p>
                  <p class="help-text backup-next" v-if="nextBackupText">{{ nextBackupText }}</p>
                </Expand>

                <p class="card-description">{{ $t('settings.system.backups.auto_backup_description') }}</p>
                <div class="setting-group-body-item">
                  <div class="backup-actions mb-3">
                    <button 
                      @click="handleCreateBackup" 
                      :disabled="backupCreating"
                      class="create-backup-button ot-act"
                    >
                      <Loader2 v-if="backupCreating" class="spinner" />
                      <Plus v-else />
                      {{ $t('settings.system.backups.create_backup') }}
                    </button>
                    <!-- Same size and colour while it loads: only its icon turns -->
                    <button
                      type="button"
                      @click="loadBackups"
                      :aria-busy="backupsLoading"
                      class="refresh-backups-button ot-act"
                    >
                      <RefreshCw class="ot-turn" :class="{ 'is-turning': backupsLoading }" aria-hidden="true" />
                      {{ $t('settings.system.backups.refresh') }}
                    </button>
                  </div>
                </div>

                <div class="setting-group-body-item">
                  <OtSkeleton v-if="!backupsLoaded" variant="rows" :count="3" />
                  
                  <div v-else-if="backups.length === 0" class="no-backups">
                    <p>{{ $t('settings.system.backups.no_backups') }}</p>
                  </div>
                  
                  <div v-else class="backups-list">
                    <table class="backups-table">
                      <thead>
                        <tr>
                          <!-- <th>{{ $t('settings.system.backups.filename') }}</th> -->
                          <th>{{ $t('settings.system.backups.created_at') }}</th>
                          <th>{{ $t('settings.system.backups.kind') }}</th>
                          <th>{{ $t('settings.system.backups.size') }}</th>
                          <th class="backup-actions-cell">{{ $t('settings.system.backups.actions') }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="backup in backups" :key="backup.filename">
                          <!-- <td class="backup-filename">{{ backup.filename }}</td> -->
                          <td>{{ formatBackupDate(backup.created_at) }}</td>
                          <td>
                            <span class="backup-kind" :class="`is-${backup.kind}`">{{ $t(`settings.system.backups.kind_${backup.kind}`) }}</span>
                          </td>
                          <td>{{ niceFileSize(backup.size) }}</td>
                          <td class="backup-actions-cell">
                            <button 
                              @click="handleDownloadBackup(backup.filename)"
                              :disabled="backupDownloading === backup.filename"
                              class="ot-act icon-only"
                              :title="$t('settings.system.backups.download')"
                              :aria-label="$t('settings.system.backups.download')"
                            >
                              <Loader2 v-if="backupDownloading === backup.filename" class="spinner" />
                              <Download v-else />
                            </button>
                            <button 
                              @click="handleDeleteBackup(backup.filename)"
                              :disabled="backupDeleting === backup.filename"
                              class="ot-act icon-only delete-button"
                              :title="$t('settings.system.backups.delete')"
                              :aria-label="$t('settings.system.backups.delete')"
                            >
                              <Loader2 v-if="backupDeleting === backup.filename" class="spinner" />
                              <Trash v-else />
                            </button>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
    </div>
    <!-- The settings on their way: the shape of a section -->
    <div class="admin-sections" v-else>
      <div class="setting-group"><OtSkeleton variant="fields" :count="4" /></div>
    </div>
  </div>
</template>

<style scoped lang="scss">
// The SMTP test: its button, then what came of it
.smtp-test {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;

  .help-text {
    margin: 0;
  }
}
.smtp-test-result {
  margin: 0;
  padding: 8px 12px;
  border-radius: var(--ot-radius-sm);
  font-size: 0.8125rem;
  overflow-wrap: anywhere;

  &.is-ok {
    background: var(--ot-success-soft);
    color: var(--ot-success);
  }
  &.is-error {
    background: var(--ot-danger-soft);
    color: var(--ot-danger-text);
  }
}

// The schedule of the automatic backups: its fields side by side while they fit
.backup-schedule {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0 16px;
}
.backup-next {
  color: var(--ot-text);
}
// Automatic (cleaned after the days chosen) or made by hand (kept)
.backup-kind {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--ot-raised);
  color: var(--ot-muted);
  font-size: 0.75rem;
  font-weight: 600;
  &.is-manual {
    background: var(--ot-accent-soft);
    color: var(--ot-accent-text);
  }
}

// Under the minimum the line says so in the colour of an error, not only in words
.pattern-strength.is-weak {
  color: var(--ot-danger-text);
}

// The size and its unit on one line, the unit as wide as its two letters need
.size-with-unit {
  display: flex;
  align-items: flex-start;
  gap: 8px;

  > :first-child {
    flex: 1;
    min-width: 0;
  }

  > :last-child {
    flex: 0 0 6.5rem;
  }
}

.auth-provider {
  margin-bottom: 8px;
  .icon {
    svg {
      width: 2rem;
      height: 2rem;
      stroke: url(#icon-gradient);
      &.custom {
        fill: url(#icon-gradient);
      }
    }
  }
}

.provider-type {
  background: var(--ot-field);
  height: 80px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  border-radius: var(--ot-radius);

  h6 {
    cursor: pointer;
    margin-top: -4px;
  }

  small {
    font-size: 0.7rem;
    color: var(--ot-muted);
    margin-bottom: 0;
  }

  .checkbox-container {
    margin-top: 25px;
    label {
      margin-bottom: 0;
      font-weight: 400;
    }
  }

  &.open {
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
  }
}

.provider-settings {
  background: var(--ot-field);
  border-radius: var(--ot-radius);
  border-top: 1px solid var(--input-border-color);
  border-top-left-radius: 0;
  border-top-right-radius: 0;
  padding: 1rem;
  padding-top: 0px;
  padding-bottom: 0px;
  margin-bottom: 0px;
  opacity: 0;
  max-height: 0;
  overflow: hidden;
  transition: all var(--ot-dur-slow) var(--ot-ease-out);
  &.open {
    margin-bottom: 10px;
    opacity: 1;
    max-height: 800px;
    padding: 1rem;
  }
}

#gradientDefs {
  opacity: 0;
  position: absolute;
  top: 0;
  left: 0;
  width: 0;
  height: 0;
}

.input-group {
  position: relative;

  button {
    position: absolute;
    right: 5px;
    top: 5px;
    bottom: 0;
    height: 40px;
    width: 40px;
    border-radius: 100% !important;
    svg {
      margin-top: 1px;
    }
  }
}

.new-provider-form {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding-left: 15px;
  padding-right: 15px;
  select {
    opacity: 0;
    transition: all var(--ot-dur-slow) var(--ot-ease-out);
    margin-top: 10px;
    width: calc(100% - 70px);
    pointer-events: none;
  }
  .new-provider-button {
    position: absolute;
    right: 50%;
    top: 50%;
    transform: translateX(50%) translateY(-50%);
    transition: all var(--ot-dur-slow) var(--ot-ease-out);
    filter: grayscale(100%);
    opacity: 0.4;
  }
  &:hover {
    .new-provider-button {
      filter: grayscale(0%);
      opacity: 1;
    }
  }
  &.active {
    select {
      opacity: 1;
      pointer-events: auto;
    }
    .new-provider-button {
      left: unset;
      right: 12px;
      transform: translateX(0) translateY(-50%);
      filter: grayscale(0%);
      opacity: 1;
    }
  }
}

.delete-auth-provider {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-top: 10px;
  font-size: 0.8rem;
  color: var(--ot-text);
  &:hover {
    color: var(--ot-danger-text);
  }
  svg {
    width: 15px;
    height: 15px;
    margin-top: -2px;
  }
}

.provider-info-link {
  display: flex;
  align-items: center;
  gap: 5px;
  svg {
    width: 15px;
    height: 15px;
    margin-top: -2px;
  }
}

.pattern-preview {
  .preview-box {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--ot-field);
    padding: 10px 15px;
    border-radius: var(--ot-radius);
    font-family: monospace;
    
    code {
      flex: 1;
      font-size: 1rem;
      word-break: break-all;
    }
    
    .refresh-preview {
      background: transparent;
      border: none;
      cursor: pointer;
      font-size: 1.2rem;
      padding: 5px;
      border-radius: 50%;
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform var(--ot-dur-slow) var(--ot-ease-spring);

      &:hover {
        transform: rotate(180deg);
      }
    }
  }
}

.pattern-syntax-help {
  margin-top: 1rem;
  
  .syntax-list {
    list-style: none;
    padding: 0;
    margin: 0.5rem 0;
    font-size: 0.85rem;
    
    li {
      padding: 4px 0;
      
      code {
        background: var(--ot-field);
        padding: 2px 6px;
        border-radius: 3px;
        font-family: monospace;
        margin-right: 8px;
      }
    }
  }
}

// Backup styles
.backup-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  
  button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    
    svg {
      width: 16px;
      height: 16px;
    }
  }
}

.backups-loading,
.no-backups {
  padding: 20px;
  text-align: center;
  color: var(--ot-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  
  svg {
    width: 20px;
    height: 20px;
  }
}

.backups-table {
  .backup-actions-cell {
    white-space: nowrap;
    width: 1px;
    text-align: right;

    button {
      margin-right: 6px;

      &:last-child {
        margin-right: 0;
      }
      &.delete-button:hover {
        color: var(--ot-danger-text);
      }
    }
  }
}

.spinner {
  animation: spin 1s var(--ot-ease-loop) infinite;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
</style>
