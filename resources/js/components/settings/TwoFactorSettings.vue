<script setup>
// The second factor of the account: authenticator app, passkeys and recovery codes.
import { ref, computed, onMounted, nextTick } from 'vue'
import OtPassword from '../layout/OtPassword.vue'
import { pinLeaving } from '../../lib/motion'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import { ShieldCheck, Smartphone, Fingerprint, KeyRound, Trash, Plus, RefreshCw } from 'lucide-vue-next'
import {
  getTwoFactorStatus,
  startTotpSetup,
  confirmTotpSetup,
  disableTotp,
  regenerateRecoveryCodes,
  getPasskeyRegistrationOptions,
  registerPasskey,
  deletePasskey
} from '../../api'
import { passkeysSupported, passkeyCancelled, createPasskey } from '../../webauthn'
import TotpSetup from '../twoFactor/TotpSetup.vue'
import RecoveryCodes from '../twoFactor/RecoveryCodes.vue'

const { t } = useTranslate()
const toast = useToast()

const status = ref(null)
const busy = ref(false)
const setup = ref(null)
const recoveryCodes = ref(null)
const passkeyName = ref('')
const passkeyToRemove = ref(null)
const canUsePasskeys = passkeysSupported()

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
    passkeyCancelled(error)
      ? 'auth.mfa.passkey_cancelled'
      : error?.name === 'InvalidStateError'
        ? 'settings.two_factor.passkey_exists'
        : {
            invalid_password: 'settings.two_factor.wrong_password',
            mfa_too_many_attempts: 'settings.two_factor.too_many_attempts',
            mfa_required_by_policy: 'settings.two_factor.required_by_policy',
            mfa_invalid_code: 'auth.mfa.invalid_code',
            totp_setup_expired: 'settings.two_factor.setup_expired',
            passkey_registration_failed: 'settings.two_factor.passkey_failed'
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
  },
  addPasskey: async (currentPassword) => {
    const options = await getPasskeyRegistrationOptions(currentPassword)
    const credential = await createPasskey(options)
    const name = passkeyName.value || t.value('settings.two_factor.default_passkey_name')
    const result = await registerPasskey(name, credential)
    passkeyName.value = ''
    recoveryCodes.value = result.recovery_codes
    toast.success(t.value('settings.two_factor.passkey_added'))
    await load()
  },
  removePasskey: async (currentPassword) => {
    status.value = await deletePasskey(passkeyToRemove.value.id, currentPassword)
    passkeyToRemove.value = null
    toast.success(t.value('settings.two_factor.passkey_removed'))
  }
}

const askToRemovePasskey = (passkey) => {
  passkeyToRemove.value = passkey
  askPassword('removePasskey')
}

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : t.value('settings.two_factor.never'))

// The only one left cannot go while the policy requires a second factor
const canRemovePasskey = computed(() => !status.value?.required || status.value.totp_enabled || status.value.passkeys.length > 1)
const canRemoveTotp = computed(() => !status.value?.required || status.value.passkeys.length > 0)

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
  <section class="ot-card two-factor" id="two_factor" aria-labelledby="h-two-factor">
    <div class="card-head">
      <h2 class="ot-card-title" id="h-two-factor">{{ $t('settings.two_factor.title') }}</h2>
      <span v-if="status" class="ot-pill" :class="{ 'is-success': status.enabled }">
        {{ status.enabled ? $t('settings.two_factor.on') : $t('settings.two_factor.off') }}
      </span>
    </div>
    <p class="card-description">{{ $t('settings.two_factor.help') }}</p>

    <template v-if="status">
      <p class="two-factor-required" v-if="status.required">{{ $t('settings.two_factor.required') }}</p>

      <!-- Shown once, right after they are made: only their hashes are kept -->
      <div class="two-factor-panel is-codes" v-if="recoveryCodes">
        <h3>{{ $t('settings.two_factor.recovery_codes') }}</h3>
        <RecoveryCodes :codes="recoveryCodes" />
        <button type="button" @click="recoveryCodes = null">{{ $t('settings.two_factor.codes_saved') }}</button>
      </div>

      <!-- Every change asks for the current password first -->
      <form class="two-factor-panel" v-if="pendingAction" @submit.prevent="submitPassword">
        <div class="input-container">
          <label for="two_factor_password">{{ $t('settings.two_factor.confirm_password') }}</label>
          <OtPassword id="two_factor_password" ref="passwordInput" autocomplete="current-password" v-model="password" />
        </div>
        <div class="factor-actions">
          <button type="submit" :disabled="busy || !password">{{ $t('settings.two_factor.continue') }}</button>
          <button type="button" class="secondary" @click="cancelPassword">{{ $t('settings.two_factor.cancel') }}</button>
        </div>
      </form>

      <div class="factor">
        <div class="factor-head">
          <Smartphone aria-hidden="true" />
          <h3>{{ $t('settings.two_factor.authenticator_app') }}</h3>
          <span class="ot-pill" :class="{ 'is-success': status.totp_enabled }">
            {{ status.totp_enabled ? $t('settings.two_factor.on') : $t('settings.two_factor.off') }}
          </span>
        </div>
        <div v-if="setup" class="factor-body">
          <TotpSetup :setup="setup" :busy="busy" @confirm="confirmSetup" />
          <button type="button" class="ghost" @click="setup = null">{{ $t('settings.two_factor.cancel') }}</button>
        </div>
        <div v-else-if="status.totp_enabled" class="factor-body">
          <p class="factor-text">{{ $t('settings.two_factor.totp_active') }}</p>
          <div class="factor-actions">
            <button type="button" class="ot-act" :disabled="busy" @click="askPassword('setup')">
              <RefreshCw aria-hidden="true" />
              {{ $t('settings.two_factor.replace_app') }}
            </button>
            <button type="button" class="ot-act" v-if="canRemoveTotp" :disabled="busy" @click="askPassword('disable')">
              <Trash aria-hidden="true" />
              {{ $t('settings.two_factor.disable') }}
            </button>
          </div>
        </div>
        <div v-else class="factor-body">
          <p class="factor-text">{{ $t('settings.two_factor.totp_description') }}</p>
          <div class="factor-actions">
            <button type="button" :disabled="busy" @click="askPassword('setup')">
              <Plus aria-hidden="true" />
              {{ $t('settings.two_factor.set_up_app') }}
            </button>
          </div>
        </div>
      </div>

      <div class="factor">
        <div class="factor-head">
          <Fingerprint aria-hidden="true" />
          <h3>{{ $t('settings.two_factor.passkeys') }}</h3>
          <span class="ot-pill" :class="{ 'is-success': status.passkeys.length > 0 }">
            {{ $t('settings.two_factor.passkeys_count', { count: status.passkeys.length }) }}
          </span>
        </div>
        <div class="factor-body">
          <p class="factor-text">{{ $t('settings.two_factor.passkeys_description') }}</p>
          <TransitionGroup name="ot-list" @before-leave="pinLeaving" tag="ul" class="passkeys ot-list-host" v-if="status.passkeys.length">
            <li v-for="passkey in status.passkeys" :key="passkey.id">
              <div class="passkey-main">
                <strong>{{ passkey.name }}</strong>
                <small>
                  {{ $t('settings.two_factor.added_on', { date: formatDate(passkey.created_at) }) }} ·
                  {{ $t('settings.two_factor.last_used', { date: formatDate(passkey.last_used_at) }) }}
                </small>
              </div>
              <button
                type="button"
                class="ot-act icon-only"
                v-if="canRemovePasskey"
                :disabled="busy"
                :aria-label="`${$t('settings.two_factor.disable')} ${passkey.name}`"
                :title="$t('settings.two_factor.disable')"
                @click="askToRemovePasskey(passkey)"
              >
                <Trash aria-hidden="true" />
              </button>
            </li>
          </TransitionGroup>
          <form v-if="canUsePasskeys" class="passkey-add" @submit.prevent="askPassword('addPasskey')">
            <div class="input-container">
              <label for="passkey_name">{{ $t('settings.two_factor.passkey_name') }}</label>
              <input
                id="passkey_name"
                type="text"
                maxlength="100"
                v-model="passkeyName"
                :placeholder="$t('settings.two_factor.passkey_name_placeholder')"
              />
            </div>
            <button type="submit" class="secondary" :disabled="busy">
              <Plus aria-hidden="true" />
              {{ $t('settings.two_factor.add_passkey') }}
            </button>
          </form>
          <p v-else class="factor-text">{{ $t('settings.two_factor.passkeys_unsupported') }}</p>
        </div>
      </div>

      <div class="factor" v-if="status.enabled">
        <div class="factor-head">
          <KeyRound aria-hidden="true" />
          <h3>{{ $t('settings.two_factor.recovery_codes') }}</h3>
          <span class="ot-pill" :class="{ 'is-warning': status.recovery_codes_remaining <= 2 }">
            {{ status.recovery_codes_remaining }}
          </span>
        </div>
        <div class="factor-body">
          <p class="factor-text">{{ $t('settings.two_factor.recovery_codes_remaining', { count: status.recovery_codes_remaining }) }}</p>
          <div class="factor-actions">
            <button type="button" class="ot-act" :disabled="busy" @click="askPassword('regenerate')">
              <KeyRound aria-hidden="true" />
              {{ $t('settings.two_factor.regenerate_codes') }}
            </button>
          </div>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped lang="scss">
.two-factor {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.card-description,
.factor-text {
  margin: 0;
  font-size: 0.8125rem;
  color: var(--ot-muted);
}
.two-factor-required {
  margin: 0;
  padding: 8px 12px;
  border-radius: var(--ot-radius-sm);
  background: var(--ot-accent-soft);
  color: var(--ot-accent-text);
  font-size: 0.8125rem;
  font-weight: 600;
}
.two-factor-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px;
  border: 1px solid var(--ot-line);
  border-radius: var(--ot-radius);
  background: var(--ot-field);

  h3 {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--ot-heading);
  }
  .input-container {
    margin: 0;
  }
}
// One factor: a heading with its state, what it does, then its actions
.factor {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-top: 14px;
  border-top: 1px solid var(--ot-line-soft);
}
.factor-head {
  display: flex;
  align-items: center;
  gap: 8px;

  svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    color: var(--ot-accent-text);
  }
  h3 {
    flex: 1;
    margin: 0;
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--ot-heading);
  }
}
.factor-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
  align-items: flex-start;
}
.factor-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;

  button {
    margin: 0;
  }
}
.passkeys {
  align-self: stretch;
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;

  li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 10px;
    background: var(--ot-field);
    border: 1px solid var(--ot-line-soft);
    border-radius: var(--ot-radius-sm);
  }
  strong {
    color: var(--ot-heading);
  }
  small {
    display: block;
    font-size: 0.75rem;
    color: var(--ot-muted);
  }
}
.passkey-add {
  align-self: stretch;
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 8px;

  .input-container {
    flex: 1 1 200px;
    margin: 0;
  }
  // As tall as the field beside it
  button {
    height: var(--ot-field-height);
    margin: 0;
  }
}
</style>
