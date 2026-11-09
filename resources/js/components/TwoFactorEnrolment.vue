<script setup>
// Mandatory setup during sign-in (admin policy). The session is only opened
// once a second factor works, and the recovery codes are shown before going on.
import { ref } from 'vue'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import { Smartphone, ShieldCheck, ArrowLeft } from 'lucide-vue-next'
import { enrolStartTotp, enrolConfirmTotp } from '../api'
import TotpSetup from './twoFactor/TotpSetup.vue'
import RecoveryCodes from './twoFactor/RecoveryCodes.vue'

const props = defineProps({ enrolToken: { type: String, required: true } })
const emit = defineEmits(['done', 'cancel'])

const { t } = useTranslate()
const toast = useToast()

const busy = ref(false)
const setup = ref(null)
const result = ref(null)

const handleError = (error) => {
  if (error.code === 'mfa_enrolment_expired' || error.code === 'totp_setup_expired') {
    toast.error(t.value('auth.mfa.enrol_expired'))
    emit('cancel')
  } else if (error.code === 'mfa_invalid_code') {
    toast.error(t.value('auth.mfa.invalid_code'))
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
