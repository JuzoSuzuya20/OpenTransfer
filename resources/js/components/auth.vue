<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { getApiUrl } from '../utils'
import { useToast } from 'vue-toastification'
import { domData } from '../domData'
import { KeyRound, Fingerprint, UserPlus, Mail, ArrowLeft, ShieldCheck, ChevronRight } from 'lucide-vue-next'
import TwoFactorEnrolment from './TwoFactorEnrolment.vue'
import BrandMark from './layout/BrandMark.vue'
import { ssoDomainOf } from '../lib/ssoDomains'
import { passkeysSupported, passkeyCancelled, getPasskeyAssertion } from '../webauthn'
import { store } from '../store'
import {
  login,
  verifyMfaCode,
  getMfaPasskeyOptions,
  verifyMfaPasskey,
  getPasswordlessOptions,
  passwordlessLogin,
  refresh,
  logout,
  forgotPassword,
  resetPassword,
  getAvailableAuthProviders,
  acceptReverseShareInvite,
  acceptReverseShareInviteById,
  getRegistrationSettings,
  registerUser,
  verifyEmail,
  resendVerificationCode
} from '../api'

import { useTranslate } from '@tolgee/vue'

const { t } = useTranslate()

// Why the API refused a login, when it is not simply the wrong password
const LOGIN_REFUSALS = {
  ip_not_allowed: 'auth.ip_not_allowed',
  too_many_attempts: 'auth.too_many_login_attempts',
  email_not_verified: 'auth.email_not_verified',
  account_disabled: 'auth.account_disabled',
  altcha_invalid: 'auth.altcha_failed',
  sso_required: 'auth.sso_required'
}

const apiUrl = getApiUrl()
const toast = useToast()
const email = ref('')
const name = ref('')
const password = ref('')
const password_confirmation = ref('')
const verificationCode = ref('')
const passwordInput = ref(null)
const loginMessage = domData().login_message
const applicationName = domData().application_name
// The accounts of these domains sign in through the identity provider: the screen says so as they type
const ssoDomain = computed(() => ssoDomainOf(email.value, domData().sso_required_domains))
const defaultLogo = domData().default_logo !== false
const logoWidth = domData().logo_width
const logoUrl = `/images/logo.png?t=${Date.now()}`
const forgotPasswordMode = ref(false)
const haveResetToken = ref(false)
const resetToken = ref('')
const waitingForRedirect = ref(false)
const authProviders = ref([])
const reverseShareToken = ref('')

// Reverse share invite by ID (for existing users)
const pendingInviteId = ref(null)
const hasInviteIdPending = ref(false)

// Registration state
const registrationMode = ref(false)
const verificationMode = ref(false)
const selfRegistrationEnabled = ref(false)
const registrationEmail = ref('')
const isRegistering = ref(false)
const isVerifying = ref(false)
const isResending = ref(false)

// Second factor, after a correct password
const mfaToken = ref('')
const mfaMethods = ref([])
const mfaCode = ref('')
const mfaMethod = ref('totp')
const mfaBusy = ref(false)
const mfaInput = ref(null)
const enrolToken = ref('')

// The first screen of signing in: the brand is its heading, every other step has its own
const isSignInStep = computed(
  () => !enrolToken.value && !mfaToken.value && !verificationMode.value && !haveResetToken.value && !waitingForRedirect.value && !forgotPasswordMode.value && !registrationMode.value
)
const canUsePasskey = computed(() => mfaMethods.value.includes('passkey') && passkeysSupported())

// Signing in with a passkey alone, when an administrator allows it
const passkeyLoginEnabled = domData().passkey_login_enabled === true && passkeysSupported()
const passkeyLoginBusy = ref(false)

onMounted(async () => {
  const token = domData().token
  if (token) {
    haveResetToken.value = true
    resetToken.value = token
  }

  // Parse URL parameters FIRST (before any async operations)
  // This ensures invite_id is set before attemptRefresh() completes
  const urlParams = new URLSearchParams(window.location.search)
  
  // Check for invite_id (for existing users who need to log in)
  const inviteId = urlParams.get('invite_id')
  if (inviteId) {
    pendingInviteId.value = inviteId
    hasInviteIdPending.value = true
    // Remove the invite_id from the URL but keep it stored
    window.history.replaceState({}, document.title, window.location.pathname)
  }

  // Now try to refresh (if user is already logged in, this will accept the pending invite)
  attemptRefresh()

  getAvailableAuthProviders().then((data) => {
    authProviders.value = data
  })

  // Check if self-registration is enabled
  try {
    const settings = await getRegistrationSettings()
    selfRegistrationEnabled.value = settings.self_registration_enabled
  } catch (error) {
    // Self-registration not available
    selfRegistrationEnabled.value = false
  }

  // Grab reverse share token from url (for guest users)
  reverseShareToken.value = urlParams.get('invite_token')
  if (reverseShareToken.value) {
    try {
      await acceptReverseShareInvite(reverseShareToken.value)
      toast.success(t.value('auth.invite_accepted'))
      //remove the invite token from the url
      window.history.replaceState({}, document.title, window.location.pathname)
      attemptRefresh()
    } catch (error) {
      //remove the invite token from the url
      window.history.replaceState({}, document.title, window.location.pathname)
      toast.error(t.value('auth.failed_to_accept_invite'))
    }
  }
})

const attemptLogin = async () => {
  if (email.value === '' || password.value === '') {
    toast.error(t.value('auth.please_enter_email_and_password'))
    return
  }

  try {
    const data = await login(email.value, password.value)

    if (data.mfaRequired) {
      password.value = ''
      mfaToken.value = data.mfaToken
      mfaMethods.value = data.methods
      if (data.methods.includes('totp')) {
        chooseMfaMethod('totp')
      } else {
        chooseMfaMethod(canUsePasskey.value ? 'passkey' : 'recovery')
      }
      return
    }

    if (data.enrolmentRequired) {
      password.value = ''
      enrolToken.value = data.enrolToken
      return
    }

    await completeSignIn(data)
  } catch (error) {
    toast.error(t.value(LOGIN_REFUSALS[error.code] || 'auth.invalid_email_or_password'))
  }
}

const completeSignIn = async (data) => {
  store.authSuccess(data)
  toast.success(t.value('auth.login_successful'))

  // If there's a pending invite, accept it after successful login
  if (hasInviteIdPending.value && pendingInviteId.value) {
    try {
      await acceptReverseShareInviteById(pendingInviteId.value)
      toast.success(t.value('auth.invite_accepted'))
    } catch (inviteError) {
      toast.error(t.value('auth.failed_to_accept_invite'))
    } finally {
      hasInviteIdPending.value = false
      pendingInviteId.value = null
    }
  }
}

const focusMfaInput = async () => {
  await nextTick()
  mfaInput.value?.focus()
}

const leaveMfa = () => {
  mfaToken.value = ''
  mfaMethods.value = []
  mfaCode.value = ''
  mfaMethod.value = 'totp'
}

const chooseMfaMethod = (method) => {
  mfaMethod.value = method
  mfaCode.value = ''
  focusMfaInput()
}

const handleMfaError = (error) => {
  if (error.code === 'mfa_expired') {
    toast.error(t.value('auth.mfa.expired'))
    leaveMfa()
  } else if (error.code === 'mfa_too_many_attempts') {
    toast.error(t.value('auth.mfa.too_many_attempts'))
  } else if (passkeyCancelled(error)) {
    toast.error(t.value('auth.mfa.passkey_cancelled'))
  } else if (error.code === 'mfa_invalid_passkey' || mfaMethod.value === 'passkey') {
    toast.error(t.value('auth.mfa.passkey_failed'))
  } else {
    toast.error(t.value('auth.mfa.invalid_code'))
  }
}

const attemptMfaCode = async () => {
  if (!mfaCode.value) return

  mfaBusy.value = true
  try {
    const data = await verifyMfaCode(mfaToken.value, mfaMethod.value, mfaCode.value)
    leaveMfa()
    await completeSignIn(data)
  } catch (error) {
    mfaCode.value = ''
    handleMfaError(error)
  } finally {
    mfaBusy.value = false
  }
}

const attemptMfaPasskey = async () => {
  mfaBusy.value = true
  try {
    const options = await getMfaPasskeyOptions(mfaToken.value)
    const credential = await getPasskeyAssertion(options)
    const data = await verifyMfaPasskey(mfaToken.value, credential)
    leaveMfa()
    await completeSignIn(data)
  } catch (error) {
    handleMfaError(error)
  } finally {
    mfaBusy.value = false
  }
}

const attemptPasskeyLogin = async () => {
  passkeyLoginBusy.value = true
  try {
    const { token, options } = await getPasswordlessOptions()
    const credential = await getPasskeyAssertion(options)
    await completeSignIn(await passwordlessLogin(token, credential))
  } catch (error) {
    if (passkeyCancelled(error)) {
      toast.error(t.value('auth.mfa.passkey_cancelled'))
    } else if (error.code === 'ip_not_allowed') {
      toast.error(t.value('auth.ip_not_allowed'))
    } else {
      toast.error(t.value('auth.passkey_login_failed'))
    }
  } finally {
    passkeyLoginBusy.value = false
  }
}

const finishEnrolment = async (data) => {
  enrolToken.value = ''
  await completeSignIn(data)
}

const attemptRefresh = async () => {
  try {
    const data = await refresh()
    store.authSuccess(data)

    // If there's a pending invite and user is now logged in, accept it
    if (hasInviteIdPending.value && pendingInviteId.value) {
      try {
        await acceptReverseShareInviteById(pendingInviteId.value)
        toast.success(t.value('auth.invite_accepted'))
      } catch (inviteError) {
        toast.error(t.value('auth.failed_to_accept_invite'))
      } finally {
        hasInviteIdPending.value = false
        pendingInviteId.value = null
      }
    }
  } catch (error) {
    //noop
  }
}

const attemptLogout = async () => {
  await logout()
}

const moveToPassword = () => {
  passwordInput.value.focus()
}

const attemptForgotPassword = async () => {
  if (email.value === '') {
    toast.error(t.value('auth.please_enter_email'))
    return
  }
  try {
    await forgotPassword(email.value)
    toast.success(t.value('auth.password_reset_email_sent'))
    forgotPasswordMode.value = false
  } catch (error) {
    toast.error(t.value('auth.failed_to_send_password_reset_email'))
  }
}

const attemptResetPassword = async () => {
  if (password.value === '' || password_confirmation.value === '') {
    toast.error(t.value('auth.please_enter_password_and_confirm_password'))
    return
  }
  if (password.value !== password_confirmation.value) {
    toast.error(t.value('auth.passwords_do_not_match'))
    return
  }
  try {
    await resetPassword(resetToken.value, email.value, password.value, password_confirmation.value)
    toast.success(t.value('auth.password_reset_successfully'))
    haveResetToken.value = false
    waitingForRedirect.value = true
    setTimeout(() => {
      window.location.href = '/'
    }, 3000)
  } catch (error) {
    toast.error(t.value('auth.failed_to_reset_password'))
  }
}

const attemptAuthProviderLogin = (providerId) => {
  const newLocation = `/auth/provider/${providerId}/login`
  window.location.href = newLocation
}

// Registration methods
const attemptRegister = async () => {
  if (name.value === '' || email.value === '' || password.value === '' || password_confirmation.value === '') {
    toast.error(t.value('auth.please_fill_all_fields'))
    return
  }
  if (password.value !== password_confirmation.value) {
    toast.error(t.value('auth.passwords_do_not_match'))
    return
  }

  isRegistering.value = true
  try {
    const result = await registerUser(name.value, email.value, password.value, password_confirmation.value)
    toast.success(t.value('auth.registration_successful'))
    registrationEmail.value = email.value
    registrationMode.value = false
    verificationMode.value = true
    // Clear form
    name.value = ''
    password.value = ''
    password_confirmation.value = ''
  } catch (error) {
    toast.error(error.message || t.value('auth.registration_failed'))
  } finally {
    isRegistering.value = false
  }
}

const attemptVerifyEmail = async () => {
  if (verificationCode.value === '' || verificationCode.value.length !== 6) {
    toast.error(t.value('auth.please_enter_verification_code'))
    return
  }

  isVerifying.value = true
  try {
    await verifyEmail(registrationEmail.value, verificationCode.value)
    toast.success(t.value('auth.email_verified_successfully'))
    verificationMode.value = false
    verificationCode.value = ''
    email.value = registrationEmail.value
    registrationEmail.value = ''
  } catch (error) {
    toast.error(error.message || t.value('auth.verification_failed'))
  } finally {
    isVerifying.value = false
  }
}

const attemptResendCode = async () => {
  isResending.value = true
  try {
    await resendVerificationCode(registrationEmail.value)
    toast.success(t.value('auth.verification_code_resent'))
  } catch (error) {
    toast.error(error.message || t.value('auth.failed_to_resend_code'))
  } finally {
    isResending.value = false
  }
}

const switchToRegistration = () => {
  registrationMode.value = true
  forgotPasswordMode.value = false
}

const switchToLogin = () => {
  registrationMode.value = false
  verificationMode.value = false
  forgotPasswordMode.value = false
}
</script>

<template>
  <div class="auth">
    <div class="auth-brand">
      <BrandMark v-if="defaultLogo" :size="56" :with-name="false" />
      <img v-else :src="logoUrl" :alt="applicationName" class="auth-logo" :style="{ width: `${logoWidth}px` }" />
      <component :is="isSignInStep ? 'h1' : 'p'" class="auth-title">{{ applicationName }}</component>
      <p class="auth-subtitle" v-if="isSignInStep">{{ loginMessage }}</p>
    </div>

  <!-- Mandatory second factor setup -->
  <div class="auth-container" v-if="enrolToken">
    <div class="auth-container-inner">
      <TwoFactorEnrolment :enrol-token="enrolToken" @done="finishEnrolment" @cancel="enrolToken = ''" />
    </div>
  </div>

  <!-- Second factor -->
  <div class="auth-container" v-else-if="mfaToken">
    <div class="auth-container-inner">
      <div class="auth-step-head">
        <h1>{{ $t('auth.mfa.title') }}</h1>
        <p v-if="mfaMethod === 'passkey'">{{ $t('auth.mfa.use_passkey_prompt') }}</p>
        <p v-else>{{ mfaMethod === 'recovery' ? $t('auth.mfa.enter_recovery_code') : $t('auth.mfa.enter_code') }}</p>
      </div>

      <button v-if="mfaMethod === 'passkey'" class="block cta" @click="attemptMfaPasskey" :disabled="mfaBusy">
        <Fingerprint aria-hidden="true" />
        {{ $t('auth.mfa.use_passkey') }}
      </button>

      <template v-else>
        <div class="input-container">
          <label for="mfa_code">{{ mfaMethod === 'recovery' ? $t('settings.two_factor.recovery_codes') : $t('auth.mfa.code') }}</label>
          <input
            id="mfa_code"
            ref="mfaInput"
            type="text"
            autocomplete="one-time-code"
            :inputmode="mfaMethod === 'recovery' ? 'text' : 'numeric'"
            :maxlength="mfaMethod === 'recovery' ? 32 : 6"
            :class="{ 'verification-code-input': mfaMethod !== 'recovery' }"
            v-model="mfaCode"
            @keyup.enter="attemptMfaCode"
          />
        </div>
        <button class="block cta" @click="attemptMfaCode" :disabled="mfaBusy || !mfaCode">
          <ShieldCheck aria-hidden="true" />
          {{ $t('auth.mfa.verify') }}
        </button>
      </template>

      <div class="auth-step-links">
        <a href="" v-if="mfaMethod !== 'passkey' && canUsePasskey" @click.prevent="chooseMfaMethod('passkey')">
          {{ $t('auth.mfa.use_passkey') }}
        </a>
        <a href="" v-if="mfaMethod !== 'totp' && mfaMethods.includes('totp')" @click.prevent="chooseMfaMethod('totp')">
          {{ $t('auth.mfa.use_authenticator') }}
        </a>
        <a href="" v-if="mfaMethod !== 'recovery' && mfaMethods.includes('recovery')" @click.prevent="chooseMfaMethod('recovery')">
          {{ $t('auth.mfa.use_recovery_code') }}
        </a>
        <a href="" class="auth-back" @click.prevent="leaveMfa">
          <ArrowLeft aria-hidden="true" />
          {{ $t('auth.back_to_login') }}
        </a>
      </div>
    </div>
  </div>

  <!-- Verification Code Screen -->
  <div class="auth-container" v-else-if="verificationMode">
    <div class="auth-container-inner">
      <div class="auth-step-head">
        <h1>{{ $t('auth.verify_email') }}</h1>
        <p>{{ $t('auth.verification_code_sent', { email: registrationEmail }) }}</p>
      </div>
      <div class="input-container">
        <label for="verification_code">{{ $t('auth.verification_code') }}</label>
        <input
          id="verification_code"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          v-model="verificationCode"
          @keyup.enter="attemptVerifyEmail"
          maxlength="6"
          class="verification-code-input"
        />
      </div>
      <button class="block cta" @click="attemptVerifyEmail" :disabled="isVerifying">
        <Mail aria-hidden="true" />
        {{ isVerifying ? $t('auth.verifying') : $t('auth.verify_email') }}
      </button>
      <div class="auth-step-links">
        <a href="" @click.prevent="attemptResendCode" :class="{ disabled: isResending }">
          {{ isResending ? $t('auth.sending') : $t('auth.resend_code') }}
        </a>
        <a href="" class="auth-back" @click.prevent="switchToLogin">
          <ArrowLeft aria-hidden="true" />
          {{ $t('auth.back_to_login') }}
        </a>
      </div>
    </div>
  </div>

  <!-- Main Auth Container -->
  <div class="auth-container" v-else-if="!haveResetToken && !waitingForRedirect">
    <div class="auth-container-inner" :class="{ 'is-sign-in': isSignInStep }">
      <!-- Login Mode -->
      <template v-if="!forgotPasswordMode && !registrationMode">
        <p class="auth-notice" v-if="hasInviteIdPending">{{ $t('auth.login_to_accept_invite') }}</p>

        <!-- The accounts of the organisation come first: most people sign in with them -->
        <section class="ot-card auth-card" v-if="authProviders.length > 0 && !waitingForRedirect" aria-labelledby="h-sso">
          <h2 class="ot-card-title" id="h-sso">{{ $t('auth.organisation_account') }}</h2>
          <button
            class="block secondary provider-button"
            v-for="provider in authProviders"
            :key="provider.id"
            @click="attemptAuthProviderLogin(provider.id)"
          >
            <Fingerprint v-if="!provider.icon" aria-hidden="true" />
            <svg v-else v-html="provider.icon" class="custom" aria-hidden="true"></svg>
            <span class="provider-name">{{ provider.name }}</span>
            <ChevronRight class="provider-chevron" aria-hidden="true" />
          </button>
        </section>

        <section class="ot-card auth-card" aria-labelledby="h-local">
          <h2 class="ot-card-title" id="h-local">{{ $t('auth.local_account') }}</h2>
          <div class="input-container">
            <label for="email">{{ $t('auth.email') }}</label>
            <input type="email" id="email" v-model="email" autocomplete="username" @keyup.enter="moveToPassword" aria-describedby="sso-domain-note" />
            <p class="auth-sso-note" id="sso-domain-note" v-if="ssoDomain" role="status">
              {{ $t('auth.sso_domain_note', { domain: ssoDomain }) }}
            </p>
          </div>
          <div class="input-container">
            <label for="password">{{ $t('auth.password') }}</label>
            <input
              type="password"
              id="password"
              v-model="password"
              autocomplete="current-password"
              @keyup.enter="attemptLogin"
              ref="passwordInput"
            />
          </div>
          <button class="block cta" @click="attemptLogin">{{ $t('auth.login') }}</button>
          <button class="block secondary" v-if="passkeyLoginEnabled" :disabled="passkeyLoginBusy" @click="attemptPasskeyLogin">
            <Fingerprint aria-hidden="true" />
            {{ $t('auth.sign_in_with_passkey') }}
          </button>
          <a href="" class="auth-link" @click.prevent="forgotPasswordMode = true">{{ $t('auth.forgot_password_question') }}</a>
        </section>

        <p class="auth-register" v-if="selfRegistrationEnabled">
          {{ $t('auth.no_account') }}
          <a href="" @click.prevent="switchToRegistration">{{ $t('auth.create_account') }}</a>
        </p>
      </template>

      <!-- Forgot Password Mode -->
      <template v-else-if="forgotPasswordMode">
        <div class="auth-step-head">
          <h1>{{ $t('auth.forgot_password') }}</h1>
          <p>{{ $t('auth.please_enter_email_to_reset_password') }}</p>
        </div>
        <div class="input-container">
          <label for="forgot_email">{{ $t('auth.email') }}</label>
          <input id="forgot_email" type="email" autocomplete="email" v-model="email" @keyup.enter="attemptForgotPassword" />
        </div>
        <button class="block cta" @click="attemptForgotPassword">
          <KeyRound aria-hidden="true" />
          {{ $t('auth.request_reset') }}
        </button>
        <div class="auth-step-links">
          <a href="" class="auth-back" @click.prevent="switchToLogin">
            <ArrowLeft aria-hidden="true" />
            {{ $t('auth.back_to_login') }}
          </a>
        </div>
      </template>

      <!-- Registration Mode -->
      <template v-else-if="registrationMode">
        <div class="auth-step-head">
          <h1>{{ $t('auth.create_account') }}</h1>
          <p>{{ $t('auth.create_account_description') }}</p>
        </div>
        <div class="input-container">
          <label for="register_name">{{ $t('auth.name') }}</label>
          <input id="register_name" type="text" autocomplete="name" v-model="name" />
        </div>
        <div class="input-container">
          <label for="register_email">{{ $t('auth.email') }}</label>
          <input id="register_email" type="email" autocomplete="email" v-model="email" />
        </div>
        <div class="input-container">
          <label for="register_password">{{ $t('auth.password') }}</label>
          <input id="register_password" type="password" autocomplete="new-password" v-model="password" />
        </div>
        <div class="input-container">
          <label for="register_password_confirmation">{{ $t('auth.confirm_password') }}</label>
          <input
            id="register_password_confirmation"
            type="password"
            autocomplete="new-password"
            v-model="password_confirmation"
            @keyup.enter="attemptRegister"
          />
        </div>
        <button class="block cta" @click="attemptRegister" :disabled="isRegistering">
          <UserPlus aria-hidden="true" />
          {{ isRegistering ? $t('auth.registering') : $t('auth.register') }}
        </button>
        <div class="auth-step-links">
          <a href="" class="auth-back" @click.prevent="switchToLogin">
            <ArrowLeft aria-hidden="true" />
            {{ $t('auth.back_to_login') }}
          </a>
        </div>
      </template>

    </div>
    <svg id="gradientDefs">
      <linearGradient id="gradient">
        <stop offset="0%" style="stop-color: var(--link-color); stop-opacity: 1" />
        <stop offset="100%" style="stop-color: var(--link-color-hover); stop-opacity: 1" />
      </linearGradient>
    </svg>
  </div>

  <!-- Password Reset Token Screen -->
  <div class="auth-container" v-else-if="!waitingForRedirect">
    <div class="auth-container-inner">
      <div class="auth-step-head">
        <h1>{{ t('auth.forgot_password_create_password') }}</h1>
        <p>{{ t('auth.forgot_password_create_password_description') }}</p>
      </div>
      <div class="input-container">
        <label for="reset_email">{{ t('auth.email') }}</label>
        <input id="reset_email" type="email" autocomplete="username" v-model="email" @keyup.enter="moveToPassword" />
      </div>
      <div class="input-container">
        <label for="reset_password">{{ t('auth.password') }}</label>
        <input
          id="reset_password"
          ref="passwordInput"
          type="password"
          autocomplete="new-password"
          v-model="password"
          @keyup.enter="attemptResetPassword"
        />
      </div>
      <div class="input-container">
        <label for="reset_password_confirmation">{{ t('auth.confirm_password') }}</label>
        <input
          id="reset_password_confirmation"
          type="password"
          autocomplete="new-password"
          v-model="password_confirmation"
          @keyup.enter="attemptResetPassword"
        />
      </div>
      <button class="block cta" @click="attemptResetPassword">
        <KeyRound aria-hidden="true" />
        {{ t('auth.save_new_password') }}
      </button>
    </div>
  </div>

  <!-- Waiting for Redirect Screen -->
  <div class="auth-container" v-else>
    <div class="auth-container-inner">
      <div class="auth-step-head">
        <h1>{{ t('auth.password_set') }}</h1>
        <p>{{ t('auth.password_set_description') }}</p>
      </div>
    </div>
  </div>
  </div>
</template>

<style scoped lang="scss">
.provider-button {
  svg:not(.provider-chevron) {
    stroke: url(#gradient);
    &.custom {
      fill: url(#gradient);
    }
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

.verification-code-input {
  font-size: 1.5rem;
  letter-spacing: 0.5rem;
  text-align: center;
  font-family: monospace;
}

a.disabled {
  opacity: 0.5;
  pointer-events: none;
}
</style>
