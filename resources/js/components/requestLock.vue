<script setup>
import { ref, computed } from 'vue'
import { useTranslate } from '@tolgee/vue'
import OtPassword from './layout/OtPassword.vue'
import UserAvatar from './layout/UserAvatar.vue'
import { acceptReverseShareInvite } from '../api'
import { timeUntilExpiration } from '../utils'
import { store } from '../store'

// A file request the member protected with a password: the guest sees who asks and until when,
// and gives the password (passed on apart from the link) before the drop page opens
const { t } = useTranslate()
const password = ref('')
const error = ref('')
const opening = ref(false)

const request = computed(() => store.lockedRequest)

const timeLeft = computed(() => {
  if (!request.value?.expires_at) return ''
  const { days, hours, minutes } = timeUntilExpiration(request.value.expires_at)
  if (days > 0) return t.value('downloader.left_days', { count: days })
  if (hours > 0) return t.value('downloader.left_hours', { count: hours })
  return t.value('downloader.left_minutes', { count: Math.max(minutes, 1) })
})

const REFUSALS = {
  'Invalid password': 'drop.invalid_password',
  'Too many attempts': 'drop.too_many_attempts',
  'Password required': 'drop.password_required'
}

const open = async () => {
  if (opening.value) return
  if (password.value === '') {
    error.value = t.value('drop.password_required')
    return
  }
  opening.value = true
  error.value = ''
  try {
    const session = await acceptReverseShareInvite(request.value.token, password.value)
    window.history.replaceState({}, document.title, window.location.pathname)
    store.lockedRequest = null
    store.authSuccess(session)
  } catch (refusal) {
    error.value = REFUSALS[refusal.message] ? t.value(REFUSALS[refusal.message]) : t.value('auth.failed_to_accept_invite')
    if (!refusal.request) {
      // Expired or used in the meantime: nothing left to open
      window.history.replaceState({}, document.title, window.location.pathname)
    }
  } finally {
    opening.value = false
  }
}
</script>

<template>
  <div class="download">
    <div class="download-head">
      <div class="download-sender" v-if="request?.from">
        <UserAvatar :seed="request.avatar_seed ?? ''" :name="request.from" :size="28" />
        {{ $t('send.request_from', { name: request.from }) }}
      </div>
      <h1 class="share-name">{{ $t('drop.protected_title') }}</h1>
    </div>

    <form class="ot-card download-card download-lock" aria-labelledby="h-request-locked" @submit.prevent="open">
      <h2 class="ot-card-title" id="h-request-locked">{{ $t('send.password') }}</h2>
      <p>{{ $t('drop.protected_text') }}</p>
      <div class="input-container">
        <label class="visually-hidden-label" for="request-password">{{ $t('send.password') }}</label>
        <OtPassword
          id="request-password"
          v-model="password"
          :class="{ error: error }"
          :aria-invalid="error ? 'true' : 'false'"
          :aria-describedby="error ? 'request-password-error' : null"
          autocomplete="off"
          autofocus
        />
        <div class="download-error" id="request-password-error" v-if="error" role="alert">{{ error }}</div>
      </div>
      <button type="submit" class="download-button block cta" :disabled="opening">
        {{ $t('drop.open') }}
      </button>
      <p class="download-lock-expiry" v-if="timeLeft">{{ $t('drop.time_left') }} {{ timeLeft }}</p>
    </form>
  </div>
</template>
