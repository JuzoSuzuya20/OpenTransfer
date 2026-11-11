<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { Upload, Copy, X, Loader, Check, Pause, Play, Eye, EyeOff, Link as LinkIcon } from 'lucide-vue-next'
import { niceFileSize, niceFileType, niceFileName, simpleUUID } from '../utils'
import { getHealth, getMyProfile, uploadFilesInChunks, logout, getMyShares, setDownloadLimit } from '../api'
import { basketRows } from '../lib/basket'
import { sendSummary } from '../lib/shares'
import AppLink from './layout/AppLink.vue'
import { uploadController } from '../store'
import { domData } from '../domData'
import { useTranslate } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { store } from '../store'
const { t } = useTranslate()
const toast = useToast()
const fileInput = ref(null)
const sharePanelVisible = ref(false)
const shareUrl = ref('')
const currentlyUploading = ref(false)
const uploadBasket = ref([])
const maxShareSize = ref(0)
const uploadProgress = ref(0)
const uploadedBytes = ref(0)
const totalBytes = ref(0)
const shareName = ref('')
const shareDescription = ref('')
const isPaused = ref(false)
const currentFileName = ref('')
const currentFileIndex = ref(0)
const totalFiles = ref(0)
const currentChunk = ref(0)
const totalChunks = ref(0)
const currentFileProgress = ref(0)
const currentFileUploadedBytes = ref(0)
const currentFileTotalBytes = ref(0)
const currentFilePath = ref('')
const completedFiles = ref([])
const uploadStartTime = ref(null)
const uploadTick = ref(0) // Reactive trigger for time-based computed properties
let uploadTickInterval = null

// Speed calculation baseline (reset on pause/resume to keep speed accurate)
const speedBaselineTime = ref(null)
const speedBaselineBytes = ref(0)
const accumulatedElapsedTime = ref(0) // Track total elapsed time across pause/resume cycles
const lastKnownSpeed = ref(0) // Store speed before pausing

// Upload speed and time tracking
const uploadSpeed = computed(() => {
  // eslint-disable-next-line no-unused-vars
  const _ = uploadTick.value // Force reactivity on tick
  // When paused (no baseline time), return the last known speed
  if (!speedBaselineTime.value) return lastKnownSpeed.value
  const bytesSinceBaseline = uploadedBytes.value - speedBaselineBytes.value
  if (bytesSinceBaseline <= 0) return lastKnownSpeed.value
  const elapsedSeconds = (Date.now() - speedBaselineTime.value) / 1000
  if (elapsedSeconds <= 0) return lastKnownSpeed.value
  return bytesSinceBaseline / elapsedSeconds // bytes per second
})

const timeElapsed = computed(() => {
  // eslint-disable-next-line no-unused-vars
  const _ = uploadTick.value // Force reactivity on tick
  if (!speedBaselineTime.value) return accumulatedElapsedTime.value
  return accumulatedElapsedTime.value + (Date.now() - speedBaselineTime.value) / 1000 // seconds
})

const timeRemaining = computed(() => {
  if (uploadSpeed.value <= 0 || totalBytes.value === 0) return 0
  const bytesRemaining = totalBytes.value - uploadedBytes.value
  return bytesRemaining / uploadSpeed.value // seconds
})
const expiryValue = ref(domData().default_expiry_time)
const expiryUnit = ref('days')
const maxExpiryTime = ref(domData().max_expiry_time)
const errors = ref({
  shareName: null
})

const sharePassword = ref('')
const sharePasswordConfirm = ref('')

const recipients = ref([])

// The password is typed once, in plain sight on demand; the server still receives its confirmation
const sharePasswordInput = ref('')
const showPassword = ref(false)
watch(sharePasswordInput, (value) => {
  sharePassword.value = value
  sharePasswordConfirm.value = value
})

// Empty means no limit
const downloadLimit = ref('')
const downloadLimitOptions = [1, 3, 5, 10, 25, 50]

// Recipients are typed as addresses; the name an e-mail greets them with is the address itself
const recipientInput = ref('')
const recipientError = ref('')
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const addRecipientFromInput = () => {
  const addresses = recipientInput.value.split(/[\s,;]+/).filter(Boolean)
  if (addresses.length === 0) {
    recipientError.value = ''
    return
  }
  const invalid = addresses.find((address) => !EMAIL.test(address))
  if (invalid) {
    recipientError.value = t.value('send.recipient_invalid', { address: invalid })
    return
  }
  for (const address of addresses) {
    if (!recipients.value.some((recipient) => recipient.email.toLowerCase() === address.toLowerCase())) {
      recipients.value.push({ id: simpleUUID(), name: address, email: address })
    }
  }
  recipientInput.value = ''
  recipientError.value = ''
}
const onRecipientKeydown = (event) => {
  if (['Enter', ',', ';'].includes(event.key)) {
    event.preventDefault()
    addRecipientFromInput()
  } else if (event.key === 'Backspace' && recipientInput.value === '' && recipients.value.length > 0) {
    recipients.value.pop()
  }
}

// The figures above the form: what the person has online right now
const myShares = ref([])
const loadMyShares = async () => {
  if (store.isGuest()) return
  try {
    myShares.value = await getMyShares()
  } catch (error) {
    console.error('Failed to load the shares', error)
  }
}
const summary = computed(() => sendSummary(myShares.value))

// One row per top-level file and per folder, with the progress of its upload
const rows = computed(() =>
  basketRows(uploadBasket.value, {
    completed: completedFiles.value,
    currentPath: currentFilePath.value,
    currentProgress: currentFileProgress.value
  })
)
const removeRow = (row) => {
  const removed = new Set(row.files)
  uploadBasket.value = uploadBasket.value.filter((file) => !removed.has(file))
}

// Interrupted upload detection
const UPLOAD_STATE_KEY = 'erugo_upload_in_progress'
const showInterruptedUploadPanel = ref(false)
const interruptedUploadInfo = ref(null)

// Save upload state to localStorage when upload starts
const saveUploadState = () => {
  const state = {
    timestamp: Date.now(),
    fileCount: uploadBasket.value.length,
    totalSize: totalSize.value,
    files: uploadBasket.value.map(f => ({
      name: f.name,
      size: f.size,
      path: f.path || '',
      fullPath: f.fullPath || f.name
    })),
    shareName: shareName.value,
    shareDescription: shareDescription.value
  }
  try {
    localStorage.setItem(UPLOAD_STATE_KEY, JSON.stringify(state))
  } catch (e) {
    console.warn('Failed to save upload state to localStorage:', e)
  }
}

// Clear upload state from localStorage
const clearUploadState = () => {
  try {
    localStorage.removeItem(UPLOAD_STATE_KEY)
  } catch (e) {
    console.warn('Failed to clear upload state from localStorage:', e)
  }
}

// Check for interrupted upload on mount
const checkForInterruptedUpload = () => {
  try {
    const savedState = localStorage.getItem(UPLOAD_STATE_KEY)
    if (savedState) {
      const state = JSON.parse(savedState)
      // Only show if the saved state is less than 7 days old
      const maxAge = 7 * 24 * 60 * 60 * 1000 // 7 days in milliseconds
      if (Date.now() - state.timestamp < maxAge) {
        interruptedUploadInfo.value = state
        showInterruptedUploadPanel.value = true
      } else {
        // Clear old state
        clearUploadState()
      }
    }
  } catch (e) {
    console.warn('Failed to check for interrupted upload:', e)
    clearUploadState()
  }
}

const dismissInterruptedUploadPanel = () => {
  showInterruptedUploadPanel.value = false
}

const dismissAndClearInterruptedUpload = () => {
  showInterruptedUploadPanel.value = false
  clearUploadState()
}

onMounted(async () => {
  //grab the max share size from the health check
  const health = await getHealth()
  maxShareSize.value = health.max_share_size

  // Check for interrupted upload
  checkForInterruptedUpload()
})

onMounted(loadMyShares)

const showFilePicker = () => {
  fileInput.value.webkitdirectory = false
  fileInput.value.click()
}

const showFolderPicker = () => {
  fileInput.value.webkitdirectory = true
  fileInput.value.click()
}

const resetFileInput = () => {
  fileInput.value.value = null
}

const dropzone = ref(null)

const handleDrop = (e) => {
  e.preventDefault()
  e.stopPropagation()
  dropBoxRemoveActiveClass()

  const items = e.dataTransfer.items || []

  for (const item of items) {
    if (item.kind === 'file') {
      // For Chromium-based browsers
      const entry = item.webkitGetAsEntry?.()
      if (entry?.isDirectory) {
        console.log(`Got directory: ${entry.name}`)
        recurseDirectory(entry, entry.name)
        continue
      }

      const file = item.getAsFile()
      if (file) {
        pushFile(file, '')
      }
    }
  }
}

const handleFileSelect = (event) => {
  const files = event.target.files

  if (files.length > 0) {
    for (let i = 0; i < files.length; i++) {
      const file = files[i]

      // Check if it's a directory (webkitdirectory attribute adds a webkitRelativePath property)
      if (file.webkitRelativePath) {
        // Extract directory path from the webkitRelativePath
        const pathParts = file.webkitRelativePath.split('/')
        const directoryPath = pathParts.slice(0, -1).join('/')

        // Create file with path information
        const fileWithPath = new File([file], file.name, {
          type: file.type,
          lastModified: file.lastModified
        })

        fileWithPath.path = directoryPath
        fileWithPath.fullPath = file.webkitRelativePath
        pushFile(fileWithPath)
      } else {
        // Regular file
        pushFile(file)
      }
    }
  }

  resetFileInput()
}

const recurseDirectory = (entry, path) => {
  if (!entry || typeof entry.createReader !== 'function') {
    console.error('Invalid directory entry:', entry)
    return
  }

  console.log(`Recursing directory: ${path}`)
  const reader = entry.createReader()

  // Read entries in batches (the API might not return all entries at once)
  const readAllEntries = () => {
    reader.readEntries(
      (entries) => {
        if (entries.length === 0) {
          // No more entries, we're done with this directory
          return
        }

        for (const childEntry of entries) {
          if (childEntry.isDirectory) {
            recurseDirectory(childEntry, `${path}/${childEntry.name}`)
          } else if (childEntry.isFile) {
            childEntry.file(
              (file) => {
                // Create file with path information
                const fileWithPath = new File([file], file.name, {
                  type: file.type,
                  lastModified: file.lastModified
                })
                // Add path information
                fileWithPath.path = path
                fileWithPath.fullPath = `${path}/${file.name}`
                pushFile(fileWithPath)
              },
              (error) => {
                console.error('Error getting file:', error)
              }
            )
          }
        }

        // Continue reading more entries (the API might return entries in batches)
        readAllEntries()
      },
      (error) => {
        console.error('Error reading directory entries:', error)
      }
    )
  }

  readAllEntries()
}

const pushFile = (file) => {
  const ignore = ['.DS_Store', 'Thumbs.db', 'desktop.ini']

  if (ignore.includes(file.name)) {
    return
  }

  // Check if the file has path information
  const fileKey = file.fullPath || file.name

  // Check if the file is already in the upload basket using the full path if available
  if (!uploadBasket.value.some((item) => (item.fullPath || item.name) === fileKey)) {
    uploadBasket.value.push(file)

    // If the share name is empty, set it to the top-level directory name or file name
    if (shareName.value === '') {
      if (file.path) {
        // Use the top directory name
        const topDir = file.path.split('/')[0]
        shareName.value = topDir
      } else {
        shareName.value = file.name
      }
    }
  }
}

const removeFile = (file) => {
  uploadBasket.value = uploadBasket.value.filter((item) => item.fullPath !== file.fullPath)
}

const totalSize = computed(() => {
  return uploadBasket.value.reduce((acc, file) => acc + file.size, 0)
})

const uploadFiles = async () => {
  const uploadId = simpleUUID()
  currentlyUploading.value = true
  isPaused.value = false
  uploadStartTime.value = Date.now()
  // Initialize speed baseline for accurate speed calculation
  speedBaselineTime.value = Date.now()
  speedBaselineBytes.value = 0
  accumulatedElapsedTime.value = 0
  // Start tick interval for updating time-based computed properties
  if (uploadTickInterval) clearInterval(uploadTickInterval)
  uploadTickInterval = setInterval(() => {
    uploadTick.value++
  }, 1000)
  uploadController.resumeUpload()

  if (totalSize.value > maxShareSize.value) {
    alert(`Total size of files is greater than the max share size of ${niceFileSize(maxShareSize.value)}`)
    currentlyUploading.value = false
    return
  }

  // Save upload state to localStorage so we can detect interrupted uploads
  saveUploadState()

  // Dismiss the interrupted upload panel if it's showing (user is resuming)
  showInterruptedUploadPanel.value = false

  //before we try uploading lets just check we're logged in still
  await getMyProfile()

  await doTusUpload(uploadId)
}

const calculateExpiryDate = () => {
  const now = new Date()
  const expiryDate = new Date(now)
  const currentTimestamp = now.getTime()
  const multiplier = multiplierFromUnit(expiryUnit.value)
  const expiryTimestamp = currentTimestamp + expiryValue.value * multiplier
  expiryDate.setTime(expiryTimestamp)
  return expiryDate
}

const multiplierFromUnit = (unit) => {
  const baseMultiplier = 1000 * 60 * 60 * 24 //1 day in milliseconds
  switch (unit) {
    case 'days':
      return 1 * baseMultiplier
    case 'weeks':
      return 7 * baseMultiplier
    case 'months':
      return 30 * baseMultiplier
    case 'years':
      return 365 * baseMultiplier
    default:
      throw new Error('Invalid expiry unit')
  }
}

const doTusUpload = async (uploadId) => {
  let pageTitleAtStart = document.title

  try {
    await uploadFilesInChunks(
      uploadBasket.value,
      uploadId,
      shareName.value,
      shareDescription.value,
      recipients.value,
      calculateExpiryDate(),
      sharePassword.value,
      sharePasswordConfirm.value,
      (progress) => {
        uploadProgress.value = progress.percentage
        uploadedBytes.value = progress.uploadedBytes
        totalBytes.value = progress.totalBytes

        if (progress.currentFileName) {
          currentFileName.value = progress.currentFileName
        }

        if (progress.currentFile && progress.totalFiles) {
          currentFileIndex.value = progress.currentFile
          totalFiles.value = progress.totalFiles
        }

        if (progress.currentChunk && progress.totalChunks) {
          currentChunk.value = progress.currentChunk
          totalChunks.value = progress.totalChunks
        }

        // Per-file progress and tracking
        if (progress.currentFileProgress !== undefined) {
          currentFileProgress.value = progress.currentFileProgress
          currentFileUploadedBytes.value = progress.currentFileUploadedBytes
          currentFileTotalBytes.value = progress.currentFileTotalBytes
        }

        // Track current file path and completed files
        if (progress.currentFilePath) {
          // If the file changed, mark the previous one as completed
          if (currentFilePath.value && currentFilePath.value !== progress.currentFilePath) {
            if (!completedFiles.value.includes(currentFilePath.value)) {
              completedFiles.value.push(currentFilePath.value)
            }
          }
          currentFilePath.value = progress.currentFilePath
        }

        //set the page title to the progress
        document.title = `${Math.round(progress.percentage)}% - ${currentFileName.value}`
      },
      (result) => {
        document.title = pageTitleAtStart
        // Mark the last file as completed
        if (currentFilePath.value && !completedFiles.value.includes(currentFilePath.value)) {
          completedFiles.value.push(currentFilePath.value)
        }
        // Clear the interrupted upload state on successful completion
        clearUploadState()
        if (store.isGuest()) {
          thankGuestForUpload()
        } else {
          showSharePanel(createShareURL(result.data.share.long_id))
          if (downloadLimit.value) {
            setDownloadLimit(result.data.share.id, Number(downloadLimit.value)).catch((error) => {
              console.error('Failed to set the download limit', error)
              toast.error(t.value('send.limit_failed'))
            })
          }
          recipients.value = []
          sharePasswordInput.value = ''
          downloadLimit.value = ''
          setTimeout(loadMyShares, 1500)
        }
        uploadBasket.value = []
        shareName.value = ''
        shareDescription.value = ''
        currentlyUploading.value = false
        resetUploadState()
      },
      (error) => {
        console.error('Upload error:', error)
        alert(`Upload failed: ${error.message}`)
        currentlyUploading.value = false
        resetUploadState()
      }
    )
  } catch (error) {
    console.error('Upload error:', error)
    alert(`Upload failed: ${error.message}`)
    currentlyUploading.value = false
    resetUploadState()
  }
}

const resetUploadState = () => {
  // Stop the tick interval
  if (uploadTickInterval) {
    clearInterval(uploadTickInterval)
    uploadTickInterval = null
  }
  setTimeout(() => {
    uploadProgress.value = 0
    uploadedBytes.value = 0
    totalBytes.value = 0
    currentFileName.value = ''
    currentFileIndex.value = 0
    totalFiles.value = 0
    currentChunk.value = 0
    totalChunks.value = 0
    currentFileProgress.value = 0
    currentFileUploadedBytes.value = 0
    currentFileTotalBytes.value = 0
    currentFilePath.value = ''
    completedFiles.value = []
    uploadStartTime.value = null
    speedBaselineTime.value = null
    speedBaselineBytes.value = 0
    accumulatedElapsedTime.value = 0
    lastKnownSpeed.value = 0
    uploadTick.value = 0
  }, 1000)
}

const createShareURL = (longId) => `${window.location.origin}/shares/${longId}`

const thankGuestForUpload = () => {
  logout()
  store.setMode('thank_guest_for_upload')
}

const shareUrlField = ref(null)
const showSharePanel = (url) => {
  sharePanelVisible.value = true
  shareUrl.value = url
  nextTick(() => shareUrlField.value?.focus())
}

const showCopySuccess = ref(false)

const copyShareUrl = () => {
  navigator.clipboard.writeText(shareUrl.value)
  showCopySuccess.value = true
  setTimeout(() => {
    showCopySuccess.value = false
  }, 1000)
}

const removeRecipient = (recipient) => {
  console.log('remove recipient', recipient)
  recipients.value = recipients.value.filter((item) => item.email !== recipient.email)
}

const togglePause = () => {
  isPaused.value = !isPaused.value
  console.log(isPaused.value ? 'Upload paused' : 'Upload resumed')
  if (isPaused.value) {
    // Pausing: save current speed before stopping
    lastKnownSpeed.value = uploadSpeed.value
    // Accumulate elapsed time so far
    if (speedBaselineTime.value) {
      accumulatedElapsedTime.value += (Date.now() - speedBaselineTime.value) / 1000
    }
    speedBaselineTime.value = null // Stop time tracking while paused
    uploadController.pauseUpload()
  } else {
    // Resuming: reset baseline for fresh speed calculation
    speedBaselineTime.value = Date.now()
    speedBaselineBytes.value = uploadedBytes.value
    uploadController.resumeUpload()
  }
}


const expiryValueWatchEnabled = ref(true)
watch(expiryValue, () => {
  if (expiryValueWatchEnabled.value) {
    checkExpiryValue()
  }
})

const timeUnitConversions = {
  days: 1,
  weeks: 7,
  months: 30,
  years: 365
}

// Single watch function with cleaner conversion logic
watch(expiryUnit, (newUnit, oldUnit) => {
  // Skip if units are the same
  if (newUnit === oldUnit) return

  // Get the conversion factor between old and new units
  const oldUnitValue = timeUnitConversions[oldUnit]
  const newUnitValue = timeUnitConversions[newUnit]
  const conversionFactor = oldUnitValue / newUnitValue

  // Apply conversion with a minimum value of 1
  const newValue = Math.round(expiryValue.value * conversionFactor)
  expiryValue.value = Math.max(1, newValue)

  // Check if the new value exceeds maximum allowed time
  checkExpiryValue(false)
})

const checkExpiryValue = (showError = true) => {
  if (maxExpiryTime.value == null) {
    return
  }
  const currentTimestamp = new Date().getTime()
  const selectedUnitMultiplier = multiplierFromUnit(expiryUnit.value)
  const expiryTimestamp = currentTimestamp + expiryValue.value * selectedUnitMultiplier

  const maxExpiryTimeStamp = currentTimestamp + maxExpiryTime.value * 1000 * 60 * 60 * 24 //days in milliseconds (this setting is always in days)
  if (expiryTimestamp > maxExpiryTimeStamp) {
    if (showError) {
      toast.error(t.value('uploader.expiry_too_long', { value: maxExpiryTimeInSelectedUnit.value }))
    }
    console.log('max expiry time in selected unit', maxExpiryTimeInSelectedUnit.value)
    setNewExpiryValueWithoutLoop(maxExpiryTimeInSelectedUnit.value)
  }
}

const maxExpiryTimeInSelectedUnit = computed(() => {
  if (maxExpiryTime.value == null) {
    return null
  }
  const maxExpiryTimeInMilliseconds = maxExpiryTime.value * 1000 * 60 * 60 * 24
  const maxExpiryTimeInSelectedUnit = maxExpiryTimeInMilliseconds / multiplierFromUnit(expiryUnit.value)
  return maxExpiryTimeInSelectedUnit
})

const RoundedMaxExpiryTimeInSelectedUnit = computed(() => {
  return Math.max(1, Math.floor(maxExpiryTimeInSelectedUnit.value))
})

const setNewExpiryValueWithoutLoop = (value) => {
  expiryValueWatchEnabled.value = false
  nextTick(() => {
    expiryValue.value = Math.floor(value)
    expiryValueWatchEnabled.value = true
  })
}

const canExpireInWeeks = computed(() => {
  if (maxExpiryTime.value == null) {
    return true
  }
  return maxExpiryTime.value >= 7
})

const canExpireInMonths = computed(() => {
  if (maxExpiryTime.value == null) {
    return true
  }
  return maxExpiryTime.value >= 30
})

const canExpireInYears = computed(() => {
  if (maxExpiryTime.value == null) {
    return true
  }
  return maxExpiryTime.value >= 365
})

const handleDragOver = (e) => {
  e.preventDefault()
  e.stopPropagation()
  dropBoxAddActiveClass()
}

const handleDragLeave = (e) => {
  e.preventDefault()
  e.stopPropagation()
  dropBoxRemoveActiveClass(e)
}

const dropBoxAddActiveClass = () => {
  dropzone.value.classList.add('active')
}

const dropBoxRemoveActiveClass = (e) => {
  if (e == null) {
    dropzone.value.classList.remove('active')
  } else if (!dropzone.value.contains(e.relatedTarget)) {
    dropzone.value.classList.remove('active')
  }
}

const handleDropzoneClick = (e) => {
  console.log(e)
  if (e.target === dropzone.value) {
    showFilePicker()
  }
}


const filesByDirectory = computed(() => {
  const structure = {}

  uploadBasket.value.forEach((file) => {
    const path = file.path || ''
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
  <div class="send">
    <div class="send-stats" v-if="!store.isGuest()">
      <div class="ot-card ot-card--tight ot-stat">
        <h2 class="ot-card-title">{{ $t('send.stats.active') }}</h2>
        <div class="ot-stat-value">
          <span class="ot-figure">{{ summary.active }}</span>
          <span class="ot-stat-sub">
            {{
              summary.endingWithinADay > 0
                ? $t('send.stats.ending', { count: summary.endingWithinADay })
                : $t('send.stats.none_ending')
            }}
          </span>
        </div>
      </div>
      <div class="ot-card ot-card--tight ot-stat">
        <h2 class="ot-card-title">{{ $t('send.stats.online') }}</h2>
        <div class="ot-stat-value">
          <span class="ot-figure is-accent">{{ niceFileSize(summary.onlineBytes) }}</span>
          <span class="ot-stat-sub">{{ $t('send.stats.online_sub') }}</span>
        </div>
      </div>
      <div class="ot-card ot-card--tight ot-stat">
        <h2 class="ot-card-title">{{ $t('send.stats.downloads') }}</h2>
        <div class="ot-stat-value">
          <span class="ot-figure is-accent">{{ summary.downloads }}</span>
          <span class="ot-stat-sub">
            {{
              summary.neverOpened > 0
                ? $t('send.stats.never_opened', { count: summary.neverOpened })
                : summary.active > 0
                  ? $t('send.stats.all_opened')
                  : $t('send.stats.no_share')
            }}
          </span>
        </div>
      </div>
    </div>

    <div class="send-columns">
      <section class="ot-card send-files" aria-labelledby="h-files">
        <h2 class="ot-card-title" id="h-files">{{ $t('uploader.files_title') }}</h2>

        <div
          class="dropzone"
          @drop="handleDrop"
          @dragenter="handleDragOver"
          @dragover.prevent.stop
          @dragleave="handleDragLeave($event)"
          @click="handleDropzoneClick"
          ref="dropzone"
        >
          <Upload class="dropzone-icon" aria-hidden="true" />
          <div class="dropzone-title">{{ $t('send.drop_title') }}</div>
          <div class="dropzone-sub">{{ $t('send.drop_sub', { size: niceFileSize(maxShareSize) }) }}</div>
          <div class="dropzone-actions">
            <button type="button" @click.stop="showFilePicker" :disabled="currentlyUploading">
              {{ $t('send.browse') }}
            </button>
            <button type="button" class="ghost" @click.stop="showFolderPicker" :disabled="currentlyUploading">
              {{ $t('send.choose_folder') }}
            </button>
          </div>
        </div>

        <ul class="basket" v-if="rows.length > 0" :aria-label="$t('uploader.files_title')">
          <li v-for="row in rows" :key="row.key" class="basket-row" :class="`is-${row.state}`">
            <div class="basket-row-main">
              <div class="basket-row-name">
                {{ row.kind === 'folder' ? $t('send.folder_row', { name: row.name, count: row.count }) : row.name }}
              </div>
              <div class="basket-row-meta">
                {{ niceFileSize(row.size) }} ·
                {{ row.kind === 'folder' ? $t('send.folder') : niceFileType(row.type) || $t('send.file') }}
              </div>
              <div
                class="basket-progress"
                role="progressbar"
                :aria-label="$t('send.progress_label', { name: row.name })"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="row.progress"
              >
                <div class="basket-progress-fill" :style="{ width: `${row.progress}%` }"></div>
              </div>
            </div>
            <span class="basket-row-status" v-if="currentlyUploading || row.state === 'done'">
              <Check v-if="row.state === 'done'" :aria-label="$t('send.sent')" />
              <template v-else>{{ row.progress }}%</template>
            </span>
            <button
              v-if="!currentlyUploading"
              type="button"
              class="ot-act icon-only"
              :aria-label="$t('send.remove', { name: row.name })"
              :title="$t('send.remove', { name: row.name })"
              @click="removeRow(row)"
            >
              <X aria-hidden="true" />
            </button>
          </li>
        </ul>

        <div class="send-files-foot" v-if="rows.length > 0 || currentlyUploading">
          <span class="send-total">
            {{ $t('send.total', { count: uploadBasket.length, size: niceFileSize(totalSize), max: niceFileSize(maxShareSize) }) }}
          </span>
          <template v-if="currentlyUploading">
            <span class="send-progress-text" aria-live="polite">
              <template v-if="uploadProgress < 100">
                <strong>{{ Math.round(uploadProgress) }}%</strong>
                · {{ niceFileSize(uploadSpeed) }}/s ·
                {{ Math.round(timeRemaining) }}{{ $t('uploader.time_remaining_suffix', 's') }}
                {{ t('uploader.time_remaining_text', 'remaining') }}
              </template>
              <template v-else>{{ $t('Processing uploaded files') }}</template>
            </span>
            <button
              type="button"
              class="ot-act icon-only"
              @click="togglePause"
              :aria-label="isPaused ? $t('send.resume') : $t('send.pause')"
              :title="isPaused ? $t('send.resume') : $t('send.pause')"
            >
              <Play v-if="isPaused" aria-hidden="true" />
              <Pause v-else aria-hidden="true" />
            </button>
          </template>
        </div>
      </section>

      <section class="ot-card send-access" aria-labelledby="h-access">
        <h2 class="ot-card-title" id="h-access">{{ $t('uploader.access_title') }}</h2>

        <div class="input-container" v-if="!store.isGuest()">
          <label for="share-name">{{ $t('Share Name') }}</label>
          <input
            type="text"
            v-model="shareName"
            required
            :class="{ error: errors.shareName }"
            id="share-name"
            name="share-name"
            autocomplete="off"
          />
          <div class="error-message" v-if="errors.shareName">{{ errors.shareName }}</div>
        </div>

        <div class="input-container" v-if="!store.isGuest()">
          <label for="share-description">{{ $t('send.message') }}</label>
          <textarea v-model="shareDescription" rows="3" id="share-description" />
        </div>

        <div class="send-access-pair">
          <div class="input-container">
            <label for="share-expiry">{{ $t('send.expires_in') }}</label>
            <div class="field-group">
              <input
                type="number"
                v-model="expiryValue"
                id="share-expiry"
                min="1"
                inputmode="numeric"
                :aria-describedby="maxExpiryTimeInSelectedUnit != null ? 'share-expiry-max' : undefined"
              />
              <select v-model="expiryUnit" :aria-label="$t('send.expiry_unit')">
                <option value="days">{{ $t('uploader.expiry_unit.days') }}</option>
                <option value="weeks" v-if="canExpireInWeeks">{{ $t('uploader.expiry_unit.weeks') }}</option>
                <option value="months" v-if="canExpireInMonths">{{ $t('uploader.expiry_unit.months') }}</option>
                <option value="years" v-if="canExpireInYears">{{ $t('uploader.expiry_unit.years') }}</option>
              </select>
            </div>
            <!-- The maximum belongs to the expiry: under the downloads it would read as a download limit -->
            <p class="help-text" id="share-expiry-max" v-if="maxExpiryTimeInSelectedUnit != null">
              {{ t('uploader.expiry_max_value', { value: RoundedMaxExpiryTimeInSelectedUnit }) }}
            </p>
          </div>
          <div class="input-container" v-if="!store.isGuest()">
            <label for="share-limit">{{ $t('send.downloads') }}</label>
            <select id="share-limit" v-model="downloadLimit">
              <option value="">{{ $t('send.unlimited') }}</option>
              <option v-for="limit in downloadLimitOptions" :key="limit" :value="String(limit)">{{ limit }}</option>
            </select>
          </div>
        </div>

        <div class="input-container">
          <label for="share-password">{{ $t('send.password') }}</label>
          <div class="field-with-action">
            <input
              :type="showPassword ? 'text' : 'password'"
              id="share-password"
              v-model="sharePasswordInput"
              autocomplete="new-password"
              aria-describedby="share-password-help"
            />
            <button
              type="button"
              class="field-action"
              @click="showPassword = !showPassword"
              :aria-label="showPassword ? $t('send.hide_password') : $t('send.show_password')"
              :aria-pressed="showPassword"
            >
              <EyeOff v-if="showPassword" aria-hidden="true" />
              <Eye v-else aria-hidden="true" />
            </button>
          </div>
          <p class="help-text" id="share-password-help">{{ $t('send.password_help') }}</p>
        </div>

        <div class="input-container" v-if="!store.isGuest()">
          <label for="share-recipient">{{ $t('send.send_to') }}</label>
          <div class="recipients-field" :class="{ error: recipientError }" @click="$refs.recipientField.focus()">
            <span class="recipient-chip" v-for="recipient in recipients" :key="recipient.id">
              {{ recipient.email }}
              <button
                type="button"
                @click.stop="removeRecipient(recipient)"
                :aria-label="$t('send.remove_recipient', { address: recipient.email })"
              >
                <X aria-hidden="true" />
              </button>
            </span>
            <input
              ref="recipientField"
              type="email"
              id="share-recipient"
              v-model="recipientInput"
              @keydown="onRecipientKeydown"
              @blur="addRecipientFromInput"
              autocomplete="email"
              aria-describedby="share-recipient-help"
            />
          </div>
          <p class="help-text is-error" v-if="recipientError" role="alert">{{ recipientError }}</p>
          <p class="help-text" id="share-recipient-help" v-else>
            {{ recipients.length > 0 ? $t('send.send_to_help_some') : $t('send.send_to_help_none') }}
          </p>
        </div>

        <button
          type="button"
          class="upload-button block cta"
          :disabled="uploadBasket.length === 0 || currentlyUploading"
          @click="uploadFiles"
        >
          <Loader class="spin" v-if="currentlyUploading" aria-hidden="true" />
          <template v-if="currentlyUploading">{{ $t('send.creating', { progress: Math.round(uploadProgress) }) }}</template>
          <template v-else-if="uploadBasket.length === 0">{{ $t('send.add_files_first') }}</template>
          <template v-else>{{ $t('send.create') }}</template>
        </button>
      </section>
    </div>
  </div>

  <input
    type="file"
    @change="handleFileSelect"
    style="display: none"
    webkitdirectory
    directory
    ref="fileInput"
    multiple
  />
  <div class="share-created" v-if="sharePanelVisible" @click.self="sharePanelVisible = false" @keydown.esc="sharePanelVisible = false">
    <section class="ot-card share-created-card" role="dialog" aria-modal="true" aria-labelledby="h-share-created">
      <h2 class="ot-card-title" id="h-share-created">{{ $t('send.created_title') }}</h2>
      <p>{{ $t('send.created_text') }}</p>
      <div class="field-with-action">
        <input type="text" readonly :value="shareUrl" :aria-label="$t('Share URL')" @focus="$event.target.select()" ref="shareUrlField" />
      </div>
      <div class="share-created-actions">
        <button type="button" @click="copyShareUrl">
          <Check v-if="showCopySuccess" aria-hidden="true" />
          <Copy v-else aria-hidden="true" />
          {{ showCopySuccess ? $t('send.copied') : $t('send.copy_link') }}
        </button>
        <AppLink to="/my-shares" class="button-secondary" @click="sharePanelVisible = false">{{ $t('nav.my_shares') }}</AppLink>
        <button type="button" class="ghost" @click="sharePanelVisible = false">{{ $t('send.close') }}</button>
      </div>
    </section>
  </div>

  <!-- Interrupted Upload Panel -->
  <div
    class="user-form-overlay interrupted-upload-panel"
    :class="{ active: showInterruptedUploadPanel }"
    @click="(e) => !e.target.closest('.user-form') && dismissInterruptedUploadPanel()"
  >
    <div class="user-form">
      <h2>
        <RotateCcw />
        {{ $t('uploader.interrupted_upload.title', 'Interrupted Upload Detected') }}
      </h2>

      <div class="interrupted-upload-content">
        <p>
          {{ $t('uploader.interrupted_upload.description', 'It looks like you had an upload in progress that was interrupted.') }}
        </p>

        <div class="interrupted-upload-details" v-if="interruptedUploadInfo">
          <div class="detail-row">
            <strong>{{ $t('uploader.interrupted_upload.files', 'Files') }}:</strong>
            <span>{{ interruptedUploadInfo.fileCount }}</span>
          </div>
          <div class="detail-row">
            <strong>{{ $t('uploader.interrupted_upload.total_size', 'Total Size') }}:</strong>
            <span>{{ niceFileSize(interruptedUploadInfo.totalSize) }}</span>
          </div>
          <div class="detail-row" v-if="interruptedUploadInfo.shareName">
            <strong>{{ $t('uploader.interrupted_upload.share_name', 'Share Name') }}:</strong>
            <span>{{ interruptedUploadInfo.shareName }}</span>
          </div>
        </div>

        <div class="interrupted-upload-instructions">
          <p>
            <strong>{{ $t('uploader.interrupted_upload.how_to_resume', 'To resume your upload:') }}</strong>
          </p>
          <ol>
            <li>{{ $t('uploader.interrupted_upload.step1', 'Select the same files you were uploading before') }}</li>
            <li>{{ $t('uploader.interrupted_upload.step2', 'Click Upload - the upload will automatically continue from where it left off') }}</li>
          </ol>
          <p class="note">
            {{ $t('uploader.interrupted_upload.note', 'Note: For resume to work, the files must be identical to the ones you were uploading (same name, size, and content).') }}
          </p>
        </div>
      </div>

      <div class="button-bar">
        <button @click="dismissInterruptedUploadPanel">
          <Check />
          {{ $t('uploader.interrupted_upload.got_it', 'Got it') }}
        </button>
        <button class="secondary" @click="dismissAndClearInterruptedUpload">
          <X />
          {{ $t('uploader.interrupted_upload.start_fresh', 'Start Fresh') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">

@mixin blur-background($blur-amount) {
  backdrop-filter: blur($blur-amount);
}

@mixin flex-row {
  display: flex;
  flex-direction: row;
  align-items: center;
}

@mixin flex-column {
  display: flex;
  flex-direction: column;
  align-items: center;
}



// Interrupted upload panel specific styles
.interrupted-upload-panel {
  .user-form {
    max-width: min(550px, 100vw);
  }

  h2 {
    justify-content: flex-start;
  }

  .interrupted-upload-content {
    width: 100%;
    text-align: left;

    p {
      margin: 0 0 12px 0;
      line-height: 1.5;
    }

    .interrupted-upload-details {
      background: var(--panel-item-background-color);
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 16px;

      .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 4px 0;

        strong {
          color: var(--panel-text-color);
          opacity: 0.8;
        }

        span {
          color: var(--panel-text-color);
        }
      }
    }

    .interrupted-upload-instructions {
      ol {
        margin: 8px 0 12px 0;
        padding-left: 20px;

        li {
          margin: 6px 0;
          line-height: 1.4;
        }
      }

      .note {
        font-size: 0.9em;
        opacity: 0.7;
        font-style: italic;
        margin-bottom: 0;
      }
    }
  }

  .button-bar {
    width: 100%;
    display: flex;
    gap: 10px;
  }
}
</style>
