<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { pinLeaving } from '../lib/motion'
import { Upload, Copy, X, Loader, Check, Pause, Play, Eye, EyeOff, Link as LinkIcon, Clock, HardDriveDownload, Lock, Mail, ChevronDown, ArrowRight } from 'lucide-vue-next'
import { niceFileSize, niceFileType, niceFileName, simpleUUID } from '../utils'
import { getHealth, getMyProfile, uploadFilesInChunks, logout, getMyShares, setDownloadLimit } from '../api'
import { basketRows } from '../lib/basket'
import { sendSummary } from '../lib/shares'
import AppLink from './layout/AppLink.vue'
import OtSelect from './layout/OtSelect.vue'
import OtNumber from './layout/OtNumber.vue'
import PageHead from './layout/PageHead.vue'
import { handedOverFiles } from '../lib/quickSendHandoff'
import { uploadController } from '../store'
import { domData } from '../domData'
import { useTranslate } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { store } from '../store'
// compact: the quick "New share" window (the files, the default access, one button); the page
// otherwise (piste A: one column, the access options as chips, one open at a time)
const props = defineProps({
  compact: { type: Boolean, default: false }
})
const emit = defineEmits(['more-options', 'close'])

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

  // Check for interrupted upload (the page only: the quick window does not take one over)
  if (!props.compact) checkForInterruptedUpload()
})

onMounted(() => !props.compact && loadMyShares())

// The files chosen in the quick window when the person asked for more options
watch(
  handedOverFiles,
  (files) => {
    if (props.compact || files.length === 0) return
    files.forEach(pushFile)
    handedOverFiles.value = []
  },
  { immediate: true }
)

// --- The access, as chips: one option open at a time
const openOption = ref(null)
const toggleOption = (name) => (openOption.value = openOption.value === name ? null : name)
const expiryLabel = computed(() =>
  t.value(`send.expires_${expiryUnit.value}`, { count: Number(expiryValue.value) || 0 })
)
const downloadsLabel = computed(() =>
  downloadLimit.value ? t.value('send.downloads_count', { count: Number(downloadLimit.value) }) : t.value('send.downloads_unlimited')
)
const passwordLabel = computed(() => (sharePasswordInput.value ? t.value('send.password_set') : t.value('send.password')))
const recipientsLabel = computed(() =>
  recipients.value.length ? t.value('send.recipients_count', { count: recipients.value.length }) : t.value('send.send_by_email')
)
// The quick window says what the link will be without asking
const quickDefaults = computed(() =>
  [expiryLabel.value, downloadsLabel.value, sharePasswordInput.value ? t.value('send.password_set') : t.value('send.no_password')].join(' · ')
)
const ctaLabel = computed(() => {
  if (currentlyUploading.value) return t.value('send.creating', { progress: Math.round(uploadProgress.value) })
  if (uploadBasket.value.length === 0) return t.value('send.add_files_first')
  return t.value('send.create_with', { count: uploadBasket.value.length, size: niceFileSize(totalSize.value) })
})
const startAnother = () => {
  sharePanelVisible.value = false
  shareUrl.value = ''
}
defineExpose({ isBusy: () => currentlyUploading.value })

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
    toast.error(t.value('uploader.too_big', { size: niceFileSize(totalSize.value), max: niceFileSize(maxShareSize.value) }))
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
        toast.error(t.value('uploader.upload_failed', { error: error.message }))
        currentlyUploading.value = false
        resetUploadState()
      }
    )
  } catch (error) {
    console.error('Upload error:', error)
    toast.error(t.value('uploader.upload_failed', { error: error.message }))
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

// The units the maximum expiry leaves room for
const expiryUnitOptions = computed(() =>
  [
    { value: 'days', label: t.value('uploader.expiry_unit.days'), shown: true },
    { value: 'weeks', label: t.value('uploader.expiry_unit.weeks'), shown: canExpireInWeeks.value },
    { value: 'months', label: t.value('uploader.expiry_unit.months'), shown: canExpireInMonths.value },
    { value: 'years', label: t.value('uploader.expiry_unit.years'), shown: canExpireInYears.value }
  ].filter((option) => option.shown)
)
const downloadLimitSelectOptions = computed(() => [
  { value: '', label: t.value('send.unlimited') },
  ...downloadLimitOptions.map((limit) => ({ value: String(limit), label: String(limit) }))
])

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
  <!-- One root: the page can fade in and out as a whole -->
  <div class="send-page">
    <div class="send" :class="{ 'is-compact': compact }">
      <PageHead v-if="!compact" :title="$t('nav.send')" :description="$t('send.lead')" />

      <!-- The quick window, once the link exists -->
      <div class="send-done" v-if="compact && sharePanelVisible" role="status">
        <div class="send-done-head">
          <span class="send-done-icon"><Check class="ot-check-pop" aria-hidden="true" /></span>
          <h2>{{ $t('send.link_ready') }}</h2>
        </div>
        <div class="input-container">
          <label for="quick-share-url">{{ $t('Share URL') }}</label>
          <input id="quick-share-url" type="text" readonly :value="shareUrl" ref="shareUrlField" @focus="$event.target.select()" />
        </div>
        <div class="send-done-actions">
          <button type="button" @click="copyShareUrl">
            <Check v-if="showCopySuccess" class="ot-check-pop" aria-hidden="true" />
            <Copy v-else aria-hidden="true" />
            {{ showCopySuccess ? $t('send.copied') : $t('send.copy_link') }}
          </button>
          <button type="button" class="ghost" @click="startAnother">{{ $t('send.another') }}</button>
        </div>
        <AppLink to="/my-shares" class="send-done-link" @click="emit('close')">{{ $t('send.see_in_my_shares') }}</AppLink>
      </div>

      <template v-else>
        <section class="ot-card send-files" :class="{ 'has-files': rows.length > 0 }" aria-labelledby="h-files">
          <h2 class="ot-card-title" id="h-files">
            <template v-if="rows.length > 0">
              {{ $t('send.total', { count: uploadBasket.length, size: niceFileSize(totalSize), max: niceFileSize(maxShareSize) }) }}
            </template>
            <template v-else>{{ $t('uploader.files_title') }}</template>
          </h2>

          <TransitionGroup name="ot-list" @before-leave="pinLeaving" tag="ul" class="basket ot-list-host" v-if="rows.length > 0" :aria-label="$t('uploader.files_title')">
            <li v-for="(row, index) in rows" :key="row.key" class="basket-row" :class="`is-${row.state}`" :style="{ '--ot-i': index }">
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
                <Check v-if="row.state === 'done'" class="ot-check-pop" :aria-label="$t('send.sent')" />
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
          </TransitionGroup>


          <!-- Big while nothing is chosen, a line under the files afterwards -->
          <div
            class="dropzone"
            :class="{ 'is-compact': rows.length > 0 || compact }"
            @drop="handleDrop"
            @dragenter="handleDragOver"
            @dragover.prevent.stop
            @dragleave="handleDragLeave($event)"
            @click="handleDropzoneClick"
            ref="dropzone"
          >
            <Upload class="dropzone-icon" aria-hidden="true" />
            <div class="dropzone-title">{{ rows.length > 0 ? $t('send.drop_more') : $t('send.drop_title') }}</div>
            <div class="dropzone-sub" v-if="rows.length === 0 && !compact">{{ $t('send.drop_sub', { size: niceFileSize(maxShareSize) }) }}</div>
            <div class="dropzone-actions">
              <button type="button" :class="{ 'ot-act': rows.length > 0 || compact }" @click.stop="showFilePicker" :disabled="currentlyUploading">
                {{ $t('send.browse') }}
              </button>
              <button type="button" :class="rows.length > 0 || compact ? 'ot-act' : 'ghost'" @click.stop="showFolderPicker" :disabled="currentlyUploading">
                {{ $t('send.choose_folder') }}
              </button>
            </div>
          </div>

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

        <section class="ot-card send-access" v-if="!compact" aria-labelledby="h-access">
          <h2 class="ot-card-title" id="h-access">{{ $t('uploader.access_title') }}</h2>
          <div class="send-chips">
            <button type="button" class="send-chip" :class="{ 'is-open': openOption === 'expiry' }" :aria-expanded="openOption === 'expiry'" aria-controls="send-opt-expiry" @click="toggleOption('expiry')">
              <Clock aria-hidden="true" />{{ expiryLabel }}<ChevronDown class="send-chip-chevron" aria-hidden="true" />
            </button>
            <button v-if="!store.isGuest()" type="button" class="send-chip" :class="{ 'is-open': openOption === 'downloads', 'is-set': downloadLimit }" :aria-expanded="openOption === 'downloads'" aria-controls="send-opt-downloads" @click="toggleOption('downloads')">
              <HardDriveDownload aria-hidden="true" />{{ downloadsLabel }}<ChevronDown class="send-chip-chevron" aria-hidden="true" />
            </button>
            <button type="button" class="send-chip" :class="{ 'is-open': openOption === 'password', 'is-set': sharePasswordInput }" :aria-expanded="openOption === 'password'" aria-controls="send-opt-password" @click="toggleOption('password')">
              <Lock aria-hidden="true" />{{ passwordLabel }}<ChevronDown class="send-chip-chevron" aria-hidden="true" />
            </button>
            <button v-if="!store.isGuest()" type="button" class="send-chip" :class="{ 'is-open': openOption === 'recipients', 'is-set': recipients.length }" :aria-expanded="openOption === 'recipients'" aria-controls="send-opt-recipients" @click="toggleOption('recipients')">
              <Mail aria-hidden="true" />{{ recipientsLabel }}<ChevronDown class="send-chip-chevron" aria-hidden="true" />
            </button>
          </div>

          <div class="send-option" id="send-opt-expiry" v-if="openOption === 'expiry'">
            <div class="input-container">
              <label for="share-expiry">{{ $t('send.expires_in') }}</label>
              <div class="field-group">
                <OtNumber
                  v-model="expiryValue"
                  id="share-expiry"
                  :min="1"
                  :max="maxExpiryTimeInSelectedUnit != null ? Math.max(1, Math.floor(maxExpiryTimeInSelectedUnit)) : undefined"
                  :aria-describedby="maxExpiryTimeInSelectedUnit != null ? 'share-expiry-max' : undefined"
                />
                <OtSelect v-model="expiryUnit" :options="expiryUnitOptions" :aria-label="$t('send.expiry_unit')" />
              </div>
              <p class="help-text" id="share-expiry-max" v-if="maxExpiryTimeInSelectedUnit != null">
                {{ t('uploader.expiry_max_value', { value: RoundedMaxExpiryTimeInSelectedUnit }) }}
              </p>
            </div>
          </div>
          <div class="send-option" id="send-opt-downloads" v-if="openOption === 'downloads'">
            <div class="input-container">
              <label for="share-limit">{{ $t('send.downloads') }}</label>
              <OtSelect id="share-limit" v-model="downloadLimit" :options="downloadLimitSelectOptions" />
            </div>
          </div>
          <div class="send-option" id="send-opt-password" v-if="openOption === 'password'">
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
                <EyeOff v-if="showPassword" class="ot-check-pop" aria-hidden="true" />
                <Eye v-else class="ot-check-pop" aria-hidden="true" />
              </button>
            </div>
            <p class="help-text" id="share-password-help">{{ $t('send.password_help') }}</p>
          </div>

          </div>
          <div class="send-option" id="send-opt-recipients" v-if="openOption === 'recipients'">
            <div class="input-container">
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

          </div>

          <details class="send-more" v-if="!store.isGuest()">
            <summary>
              {{ $t('send.name_and_message') }}
              <span class="send-more-name" v-if="shareName">· {{ shareName }}</span>
            </summary>
            <div class="send-more-fields">
              <div class="input-container">
                <label for="share-name">{{ $t('Share Name') }}</label>
                <input type="text" v-model="shareName" id="share-name" name="share-name" autocomplete="off" />
              </div>
              <div class="input-container">
                <label for="share-description">{{ $t('send.message') }}</label>
                <textarea v-model="shareDescription" rows="3" id="share-description" />
              </div>
            </div>
          </details>
        </section>

        <p class="send-defaults" v-if="compact && rows.length > 0">{{ quickDefaults }}</p>

        <button
          type="button"
          class="upload-button block cta"
          :disabled="uploadBasket.length === 0 || currentlyUploading"
          @click="uploadFiles"
        >
          <Loader class="spin" v-if="currentlyUploading" aria-hidden="true" />
          {{ ctaLabel }}
        </button>

        <a v-if="compact" href="/" class="send-more-options" @click.prevent="emit('more-options', [...uploadBasket])">
          {{ $t('send.more_options') }}
          <ArrowRight aria-hidden="true" />
        </a>

        <p class="send-summary" v-if="!compact && !store.isGuest() && summary.active > 0">
          {{ $t('send.summary_line', { active: summary.active, downloads: summary.downloads }) }} ·
          <AppLink to="/my-shares">{{ $t('nav.my_shares') }}</AppLink>
        </p>
      </template>
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
    <Transition name="ot-stamp">
    <div class="share-created" v-if="sharePanelVisible && !compact" @click.self="sharePanelVisible = false" @keydown.esc="sharePanelVisible = false">
      <section class="ot-card share-created-card" role="dialog" aria-modal="true" aria-labelledby="h-share-created">
        <h2 class="ot-card-title" id="h-share-created">{{ $t('send.created_title') }}</h2>
        <p>{{ $t('send.created_text') }}</p>
        <div class="field-with-action">
          <input type="text" readonly :value="shareUrl" :aria-label="$t('Share URL')" @focus="$event.target.select()" ref="shareUrlField" />
        </div>
        <div class="share-created-actions">
          <button type="button" @click="copyShareUrl">
            <Check v-if="showCopySuccess" class="ot-check-pop" aria-hidden="true" />
            <Copy v-else aria-hidden="true" />
            {{ showCopySuccess ? $t('send.copied') : $t('send.copy_link') }}
          </button>
          <AppLink to="/my-shares" class="button-secondary" @click="sharePanelVisible = false">{{ $t('nav.my_shares') }}</AppLink>
          <button type="button" class="ghost" @click="sharePanelVisible = false">{{ $t('send.close') }}</button>
        </div>
      </section>
    </div>
    </Transition>

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
      background: var(--ot-field);
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 16px;

      .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 4px 0;

        strong {
          color: var(--ot-text);
          opacity: 0.8;
        }

        span {
          color: var(--ot-text);
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
