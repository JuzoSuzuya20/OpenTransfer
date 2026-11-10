<script setup>
import { ref, onMounted, nextTick, computed } from 'vue'
import { niceFileSize, timeUntilExpiration, getApiUrl, niceFileType, niceFileName } from '../utils'
import { HeartCrack, TrendingDown, FileX, Lock, Download } from 'lucide-vue-next'
import { getShare } from '../api'
import { domError } from '../domData'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import DirectoryItem from './directory-item.vue'
import initials from '../lib/initials'

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
        toast.error(t.value('share.download.password_required'))
      } else if (errorMessage == 'invalid_password') {
        toast.error(t.value('share.download.invalid_password'))
      } else if (errorMessage == 'too_many_attempts') {
        toast.error(t.value('share.download.too_many_attempts'))
      }
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
      <div class="download-sender" v-if="share.user?.name">
        <span class="avatar" aria-hidden="true">{{ initials(share.user.name) }}</span>
        <span>{{ share.user.name }}</span>
      </div>
      <h1 class="share-name">{{ share.name }}</h1>

      <div class="download-stats">
        <div class="stat-card">
          <span class="stat-label">{{ $t('downloader.items') }}</span>
          <span class="stat-value">{{ share.file_count }}</span>
        </div>
        <div class="stat-card">
          <span class="stat-label">{{ $t('downloader.size') }}</span>
          <span class="stat-value">{{ niceFileSize(share.size) }}</span>
        </div>
        <div class="stat-card stat-expiry">
          <span class="stat-label">{{ $t('downloader.expiry') }}</span>
          <span class="stat-value share-expires">
            {{
              $t('share.expires.in', {
                days: timeUntilExpiration(share.expires_at).days,
                hours: timeUntilExpiration(share.expires_at).hours,
                minutes: timeUntilExpiration(share.expires_at).minutes
              })
            }}
          </span>
        </div>
      </div>

      <div class="download-card share-message" v-if="share.description">
        <h2>{{ $t('message.from', { name: splitFullName(share.user.name) }) }}</h2>
        <div class="message">
          {{ share.description }}
        </div>
      </div>

      <div class="download-card download-button-container" v-if="!share.password_protected">
        <button class="download-button block" @click="downloadFiles">
          <Download />
          {{ $t('download.files', 'Download {value} files', { value: share.file_count }) }}
        </button>
      </div>

      <div class="download-card password-input-container" v-else>
        <h2>
          <Lock />
          {{ $t('downloader.protected_title') }}
        </h2>
        <div class="input-container">
          <label for="share-password">{{ $t('settings.share.password') }}</label>
          <input
            type="password"
            id="share-password"
            v-model="password"
            :class="{ error: error }"
            @keyup.enter="downloadPasswordProtectedFiles"
          />
          <div class="error-message" v-if="error" role="alert">
            {{ error }}
          </div>
        </div>
        <button class="download-button block" @click="downloadPasswordProtectedFiles">
          <Download />
          {{ $t('download.files', 'Download {value} files', { value: share.file_count }) }}
        </button>
      </div>

      <div class="download-card share-files-list">
        <h2>{{ $t('downloader.files_title') }}</h2>
        <directory-item
          :structure="filesByDirectory"
          :is-root="true"
          :read-only="true"
          :share-code="downloadShareCode"
        />
      </div>
    </template>
    <div class="download-card download-state" v-else>
      <template v-if="shareExpired">
        <HeartCrack />
        <h1>{{ $t('share.expired') }}</h1>
        <p>{{ $t('share.expired.message') }}</p>
      </template>
      <template v-else-if="downloadLimitReached">
        <TrendingDown />
        <h1>{{ $t('share.download_limit_reached') }}</h1>
        <p>{{ $t('share.download_limit_reached.message') }}</p>
      </template>
      <template v-else-if="shareNotFound">
        <FileX />
        <h1>{{ $t('share.not_found') }}</h1>
      </template>
      <h1 v-else>{{ $t('share.data_loading') }}</h1>
    </div>
  </div>
</template>
