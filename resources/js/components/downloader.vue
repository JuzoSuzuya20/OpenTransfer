<script setup>
import { ref, onMounted, nextTick, computed } from 'vue'
import { niceFileSize, timeUntilExpiration, getApiUrl, niceFileType, niceFileName } from '../utils'
import { HeartCrack, TrendingDown, FileX, Download } from 'lucide-vue-next'
import { getShare } from '../api'
import { domError } from '../domData'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import DirectoryItem from './directory-item.vue'
import UserAvatar from './layout/UserAvatar.vue'

// How long the share stays online, in its largest unit: 6 d, 4 h, 12 min
const expiresInShort = computed(() => {
  if (!share.value) return ''
  const { days, hours, minutes } = timeUntilExpiration(share.value.expires_at)
  if (days > 0) return t.value('downloader.left_days', { count: days })
  if (hours > 0) return t.value('downloader.left_hours', { count: hours })
  return t.value('downloader.left_minutes', { count: Math.max(minutes, 1) })
})
const expiresInFull = computed(() => {
  if (!share.value) return ''
  const { days, hours, minutes } = timeUntilExpiration(share.value.expires_at)
  return t.value('share.expires.in', { days, hours, minutes })
})

const { t } = useTranslate()

const apiUrl = getApiUrl()
const toast = useToast()
const share = ref(null)
const showFilesCount = ref(5)
const shareExpired = ref(false)
const downloadLimitReached = ref(false)
const shareNotFound = ref(false)

//define props
const props = defineProps({
  downloadShareCode: {
    type: String,
    required: true
  }
})

onMounted(() => {
  fetchShare()
  setTimeout(() => {
    const urlParams = new URLSearchParams(window.location.search)
    const errorMessage = urlParams.get('error')

    if (errorMessage) {
      if (errorMessage == 'password_required') {
        error.value = t.value('share.download.password_required')
      } else if (errorMessage == 'invalid_password') {
        error.value = t.value('share.download.invalid_password')
      } else if (errorMessage == 'too_many_attempts') {
        error.value = t.value('share.download.too_many_attempts')
      }
      history.replaceState(history.state, '', window.location.pathname)
    }
  }, 100)
})

const fetchShare = async () => {
  try {
    share.value = await getShare(props.downloadShareCode)
    document.title = share.value.name
  } catch (error) {
    console.log('error', error)
    if (error.message == 'Download limit reached') {
      downloadLimitReached.value = true
    } else if (error.message == 'Share expired') {
      shareExpired.value = true
    } else if (error.message == 'Share not found') {
      shareNotFound.value = true
    }
  }
}

const downloadFiles = () => {
  const downloadUrl = `${apiUrl}/api/shares/${props.downloadShareCode}/download`
  window.location.href = downloadUrl
}

const splitFullName = (fullName) => {
  if (!fullName) {
    return 'creator'
  }
  const nameParts = fullName.split(' ')
  return nameParts[0]
}

const password = ref('')
const error = ref(null)

const downloadPasswordProtectedFiles = () => {
  //is the password filled in?
  if (!password.value) {
    toast.error(t.value('share.download.password_required'))
    error.value = t.value('share.download.password_required_short')
    return
  }

  //create a form and submit it
  const form = document.createElement('form')
  form.action = `${apiUrl}/api/shares/${props.downloadShareCode}/download`
  form.method = 'POST'

  //add the password input
  const passwordInput = document.createElement('input')
  passwordInput.type = 'password'
  passwordInput.name = 'password'
  passwordInput.value = password.value
  form.appendChild(passwordInput)

  // Add the form to the document body - THIS LINE IS CRUCIAL
  document.body.appendChild(form)

  // Submit the form
  form.submit()
  setTimeout(() => document.body.removeChild(form), 0)
}

const filesByDirectory = computed(() => {
  const files = share?.value?.files
  const structure = {}

  if (!files) {
    return {}
  }

  files.forEach((file) => {
    const path = file.full_path || ''
    const dirs = path ? path.split('/') : ['']

    // Create nested structure
    let current = structure
    for (const dir of dirs) {
      if (dir) {
        if (!current[dir]) {
          current[dir] = { files: [], directories: {} }
        }
        current = current[dir].directories
      }
    }

    // Add file to its directory
    if (path) {
      const parentDir = dirs.reduce((acc, dir, index) => {
        if (index < dirs.length - 1 && dir) {
          return acc[dir].directories
        }
        return acc
      }, structure)

      const lastDir = dirs[dirs.length - 1]
      if (lastDir) {
        parentDir[lastDir].files.push(file)
      }
    } else {
      // Root files
      if (!structure.files) {
        structure.files = []
      }
      structure.files.push(file)
    }
  })

  return structure
})
</script>

<template>
  <div class="download">
    <template v-if="share">
      <div class="download-head">
        <div class="download-sender" v-if="share.user?.name">
          <UserAvatar :seed="share.user.avatar_seed ?? ''" :name="share.user.name" :size="28" />
          {{ $t('downloader.sent_by', { name: share.user.name }) }}
        </div>
        <h1 class="share-name">{{ share.name }}</h1>
      </div>

      <div class="download-stats">
        <div class="ot-card ot-card--tight stat-card">
          <h2 class="ot-card-title">{{ $t('downloader.items') }}</h2>
          <span class="ot-figure">{{ share.file_count }}</span>
        </div>
        <div class="ot-card ot-card--tight stat-card">
          <h2 class="ot-card-title">{{ $t('downloader.size') }}</h2>
          <span class="ot-figure is-accent">{{ niceFileSize(share.size) }}</span>
        </div>
        <div class="ot-card ot-card--tight stat-card">
          <h2 class="ot-card-title">{{ $t('downloader.expires') }}</h2>
          <span class="ot-figure is-warning" :title="expiresInFull">{{ expiresInShort }}</span>
        </div>
      </div>

      <section class="ot-card download-card share-message" v-if="share.description" aria-labelledby="h-message">
        <h2 class="ot-card-title" id="h-message">{{ $t('message.from', { name: splitFullName(share.user.name) }) }}</h2>
        <div class="message">{{ share.description }}</div>
      </section>

      <section class="ot-card download-card" v-if="share.password_protected" aria-labelledby="h-locked">
        <h2 class="ot-card-title" id="h-locked">{{ $t('downloader.protected_title') }}</h2>
        <p>{{ $t('downloader.protected_text') }}</p>
        <div class="input-container">
          <label for="share-password">{{ $t('send.password') }}</label>
          <input
            type="password"
            id="share-password"
            v-model="password"
            :class="{ error: error }"
            :aria-invalid="error ? 'true' : 'false'"
            :aria-describedby="error ? 'share-password-error' : null"
            autocomplete="off"
            @keyup.enter="downloadPasswordProtectedFiles"
          />
          <div class="download-error" id="share-password-error" v-if="error" role="alert">{{ error }}</div>
        </div>
        <button class="download-button block cta" @click="downloadPasswordProtectedFiles">
          {{ $t('downloader.unlock_and_download') }}
        </button>
      </section>

      <section class="ot-card download-card share-files-list" aria-labelledby="h-files">
        <h2 class="ot-card-title" id="h-files">{{ $t('downloader.files_title') }}</h2>
        <directory-item :structure="filesByDirectory" :is-root="true" :read-only="true" :share-code="downloadShareCode" />
        <button class="download-button block cta" v-if="!share.password_protected" @click="downloadFiles">
          <Download aria-hidden="true" />
          {{ $t('download.files', 'Download {value} files', { value: share.file_count }) }}
        </button>
      </section>
    </template>

    <section class="ot-card download-state" v-else>
      <template v-if="shareExpired">
        <HeartCrack aria-hidden="true" />
        <h1>{{ $t('share.expired') }}</h1>
        <p>{{ $t('share.expired.message') }}</p>
      </template>
      <template v-else-if="downloadLimitReached">
        <TrendingDown aria-hidden="true" />
        <h1>{{ $t('share.download_limit_reached') }}</h1>
        <p>{{ $t('share.download_limit_reached.message') }}</p>
      </template>
      <template v-else-if="shareNotFound">
        <FileX aria-hidden="true" />
        <h1>{{ $t('share.not_found') }}</h1>
      </template>
      <h1 v-else>{{ $t('share.data_loading') }}</h1>
    </section>
  </div>
</template>
