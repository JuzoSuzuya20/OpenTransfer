<script setup>
// Mandatory setup during sign-in (admin policy). The session is only opened
// once a second factor works, and the recovery codes are shown before going on.
import { ref, defineAsyncComponent } from 'vue'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import { Smartphone, Fingerprint, ShieldCheck, ArrowLeft } from 'lucide-vue-next'
import { enrolStartTotp, enrolConfirmTotp, enrolPasskeyOptions, enrolPasskey } from '../api'
import { passkeysSupported, passkeyCancelled, createPasskey } from '../webauthn'
import RecoveryCodes from './twoFactor/RecoveryCodes.vue'

// With its QR code generator, fetched only while an authenticator app is being paired
const TotpSetup = defineAsyncComponent(() => import('./twoFactor/TotpSetup.vue'))

const props = defineProps({ enrolToken: { type: String, required: true } })
const emit = defineEmits(['done', 'cancel'])

const { t } = useTranslate()
const toast = useToast()

const busy = ref(false)
const setup = ref(null)
const result = ref(null)
const canUsePasskeys = passkeysSupported()

const handleError = (error) => {
  if (error.code === 'mfa_enrolment_expired' || error.code === 'totp_setup_expired') {
    toast.error(t.value('auth.mfa.enrol_expired'))
    emit('cancel')
  } else if (passkeyCancelled(error)) {
    toast.error(t.value('auth.mfa.passkey_cancelled'))
  } else if (error.code === 'mfa_invalid_code') {
    toast.error(t.value('auth.mfa.invalid_code'))
  } else if (error.code === 'passkey_registration_failed') {
    toast.error(t.value('settings.two_factor.passkey_failed'))
  } else {
    toast.error(t.value('settings.two_factor.action_failed'))
  }
}

const start = async () => {
  busy.value = true
  try {
    setup.value = await enrolStartTotp(props.enrolToken)
  } catch (error) {
    handleError(error)
  } finally {
    busy.value = false
  }
}

const addPasskey = async () => {
  busy.value = true
  try {
    const options = await enrolPasskeyOptions(props.enrolToken)
    const credential = await createPasskey(options)
    result.value = await enrolPasskey(props.enrolToken, t.value('settings.two_factor.default_passkey_name'), credential)
  } catch (error) {
    handleError(error)
  } finally {
    busy.value = false
  }
}

const confirm = async (code) => {
  busy.value = true
  try {
    result.value = await enrolConfirmTotp(props.enrolToken, code)
  } catch (error) {
    handleError(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="enrolment">
    <h1>{{ $t('auth.mfa.enrol_title') }}</h1>

    <template v-if="result">
      <RecoveryCodes :codes="result.recoveryCodes" />
      <button class="block mt-3" @click="emit('done', result.auth)">
        <ShieldCheck />
        {{ $t('auth.mfa.enrol_continue') }}
      </button>
    </template>

    <TotpSetup v-else-if="setup" :setup="setup" :busy="busy" @confirm="confirm" />

    <template v-else>
      <p>{{ $t('auth.mfa.enrol_description') }}</p>
      <button class="block mt-2" :disabled="busy" @click="start">
        <Smartphone />
        {{ $t('settings.two_factor.set_up_app') }}
      </button>
      <button class="block secondary mt-2" v-if="canUsePasskeys" :disabled="busy" @click="addPasskey">
        <Fingerprint />
        {{ $t('settings.two_factor.add_passkey') }}
      </button>
    </template>

    <a href="" class="mt-3 d-inline-block" v-if="!result" @click.prevent="emit('cancel')">
      <ArrowLeft style="width: 14px; height: 14px; margin-top: -2px" />
      {{ $t('auth.back_to_login') }}
    </a>
  </div>
</template>

<style scoped>
.enrolment {
  width: 100%;
}
</style>
