<script setup>
// The second factor of the account: authenticator app and recovery codes.
import { ref, onMounted, nextTick } from 'vue'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import { ShieldCheck, Smartphone, KeyRound, Trash, Plus, RefreshCw } from 'lucide-vue-next'
import {
  getTwoFactorStatus,
  startTotpSetup,
  confirmTotpSetup,
  disableTotp,
  regenerateRecoveryCodes
} from '../../api'
import TotpSetup from '../twoFactor/TotpSetup.vue'
import RecoveryCodes from '../twoFactor/RecoveryCodes.vue'

const { t } = useTranslate()
const toast = useToast()

const status = ref(null)
const busy = ref(false)
const setup = ref(null)
const recoveryCodes = ref(null)

// Every change needs the current password: the action waits here for it
const pendingAction = ref(null)
const password = ref('')
const passwordInput = ref(null)

const load = async () => {
  try {
    status.value = await getTwoFactorStatus()
  } catch (error) {
    toast.error(t.value('settings.two_factor.load_failed'))
  }
}

onMounted(load)

const errorMessage = (error) =>
  t.value(
    {
      invalid_password: 'settings.two_factor.wrong_password',
      mfa_too_many_attempts: 'settings.two_factor.too_many_attempts',
      mfa_required_by_policy: 'settings.two_factor.required_by_policy',
      mfa_invalid_code: 'auth.mfa.invalid_code',
      totp_setup_expired: 'settings.two_factor.setup_expired'
    }[error.code] ?? 'settings.two_factor.action_failed'
  )

const askPassword = async (action) => {
  pendingAction.value = action
  password.value = ''
  await nextTick()
  passwordInput.value?.focus()
}

const cancelPassword = () => {
  pendingAction.value = null
  password.value = ''
}

const actions = {
  setup: async (currentPassword) => {
    setup.value = await startTotpSetup(currentPassword)
    recoveryCodes.value = null
  },
  disable: async (currentPassword) => {
    status.value = await disableTotp(currentPassword)
    recoveryCodes.value = null
    toast.success(t.value('settings.two_factor.totp_disabled'))
  },
  regenerate: async (currentPassword) => {
    recoveryCodes.value = (await regenerateRecoveryCodes(currentPassword)).recovery_codes
    await load()
  }
}

const submitPassword = async () => {
  if (!password.value) return
  busy.value = true
  try {
    await actions[pendingAction.value](password.value)
    cancelPassword()
  } catch (error) {
    password.value = ''
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

const confirmSetup = async (code) => {
  busy.value = true
  try {
    const result = await confirmTotpSetup(code)
    setup.value = null
    recoveryCodes.value = result.recovery_codes
    toast.success(t.value('settings.two_factor.totp_enabled'))
    await load()
  } catch (error) {
    if (error.code === 'totp_setup_expired') {
      setup.value = null
    }
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="setting-group" id="two_factor">
    <div class="setting-group-header">
      <h3>
        <ShieldCheck />
        {{ $t('settings.two_factor.title') }}
      </h3>
    </div>

    <div class="setting-group-body" v-if="status">
      <p>{{ status.enabled ? $t('settings.two_factor.status_on') : $t('settings.two_factor.status_off') }}</p>
      <p v-if="status.required">{{ $t('settings.two_factor.required') }}</p>

      <div class="panel" v-if="recoveryCodes">
        <h5>{{ $t('settings.two_factor.recovery_codes') }}</h5>
        <RecoveryCodes :codes="recoveryCodes" />
        <button class="mt-2" @click="recoveryCodes = null">{{ $t('settings.two_factor.codes_saved') }}</button>
      </div>

      <div class="panel" v-if="pendingAction">
        <div class="input-container">
          <label for="two_factor_password">{{ $t('settings.two_factor.confirm_password') }}</label>
          <input
            id="two_factor_password"
            ref="passwordInput"
            type="password"
            autocomplete="current-password"
            v-model="password"
            @keyup.enter="submitPassword"
          />
        </div>
        <div class="actions">
          <button :disabled="busy || !password" @click="submitPassword">{{ $t('settings.two_factor.continue') }}</button>
          <button class="secondary" @click="cancelPassword">{{ $t('settings.two_factor.cancel') }}</button>
        </div>
      </div>

      <h5 class="mt-3"><Smartphone class="inline-icon" /> {{ $t('settings.two_factor.authenticator_app') }}</h5>

      <div v-if="setup">
        <TotpSetup :setup="setup" :busy="busy" @confirm="confirmSetup" />
        <button class="secondary mt-2" @click="setup = null">{{ $t('settings.two_factor.cancel') }}</button>
      </div>

      <template v-else-if="status.totp_enabled">
        <p>{{ $t('settings.two_factor.totp_active') }}</p>
        <div class="actions">
          <button class="secondary" :disabled="busy" @click="askPassword('setup')">
            <RefreshCw />
            {{ $t('settings.two_factor.replace_app') }}
          </button>
          <button class="secondary" v-if="!status.required" :disabled="busy" @click="askPassword('disable')">
            <Trash />
            {{ $t('settings.two_factor.disable') }}
          </button>
        </div>
      </template>

      <template v-else>
        <p>{{ $t('settings.two_factor.totp_description') }}</p>
        <button :disabled="busy" @click="askPassword('setup')">
          <Plus />
          {{ $t('settings.two_factor.set_up_app') }}
        </button>
      </template>

      <template v-if="status.enabled">
        <h5 class="mt-4"><KeyRound class="inline-icon" /> {{ $t('settings.two_factor.recovery_codes') }}</h5>
        <p>{{ $t('settings.two_factor.recovery_codes_remaining', { count: status.recovery_codes_remaining }) }}</p>
        <button class="secondary" :disabled="busy" @click="askPassword('regenerate')">
          <KeyRound />
          {{ $t('settings.two_factor.regenerate_codes') }}
        </button>
      </template>
    </div>
  </div>
</template>

<style scoped>
.inline-icon {
  width: 18px;
  height: 18px;
  margin-top: -3px;
}
.panel {
  border: 1px solid var(--panel-border-color, rgba(0, 0, 0, 0.15));
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1rem;
}
.actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
</style>
