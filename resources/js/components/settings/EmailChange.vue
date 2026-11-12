<script setup>
// The address of the account changes only with the code sent to the new address: until it comes
// back, the account keeps the address it has (see EmailChangeController)
import { ref, nextTick } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { AtSign } from 'lucide-vue-next'
import OtPassword from '../layout/OtPassword.vue'
import { requestEmailChange, confirmEmailChange } from '../../api'

defineProps({ email: { type: String, required: true } })
const emit = defineEmits(['changed'])

const { t } = useTranslate()
const toast = useToast()

const step = ref('idle') // idle, ask, code
const newEmail = ref('')
const currentPassword = ref('')
const code = ref('')
const pendingEmail = ref('')
const error = ref('')
const busy = ref(false)
const newEmailField = ref(null)
const codeField = ref(null)

const firstError = (failure) => {
  const errors = failure?.data?.errors ?? {}
  if (failure?.code === 'sso_required') return t.value('auth.sso_required')
  if (errors.current_password) return t.value('settings.account.email_change.wrong_password')
  if (errors.email) {
    return String(errors.email[0]).includes('taken') ? t.value('settings.account.email_change.taken') : t.value('settings.account.email_change.invalid_email')
  }
  return t.value('settings.account.email_change.failed')
}

const open = async () => {
  step.value = 'ask'
  error.value = ''
  await nextTick()
  newEmailField.value?.focus()
}

const cancel = () => {
  step.value = 'idle'
  newEmail.value = ''
  currentPassword.value = ''
  code.value = ''
  error.value = ''
}

const send = async () => {
  if (!newEmail.value || !currentPassword.value) {
    error.value = t.value('settings.account.email_change.missing')
    return
  }
  busy.value = true
  error.value = ''
  try {
    pendingEmail.value = await requestEmailChange(newEmail.value.trim(), currentPassword.value)
    currentPassword.value = ''
    step.value = 'code'
    await nextTick()
    codeField.value?.focus()
  } catch (failure) {
    error.value = firstError(failure)
  } finally {
    busy.value = false
  }
}

const confirm = async () => {
  busy.value = true
  error.value = ''
  try {
    const user = await confirmEmailChange(code.value.trim())
    toast.success(t.value('settings.account.email_change.done', { email: user.email }))
    emit('changed', user)
    cancel()
  } catch (failure) {
    if (failure?.status === 429) error.value = t.value('settings.account.email_change.too_many')
    else if (failure?.status === 409) error.value = t.value('settings.account.email_change.taken')
    else error.value = t.value('settings.account.email_change.invalid_code')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="email-change">
    <div class="input-container">
      <label for="email">{{ $t('settings.account.email') }}</label>
      <div class="email-change-current">
        <input type="email" id="email" :value="email" readonly />
        <button type="button" class="secondary" v-if="step === 'idle'" @click="open">
          <AtSign aria-hidden="true" />
          {{ $t('settings.account.email_change.change') }}
        </button>
      </div>
    </div>

    <form class="email-change-step" v-if="step === 'ask'" @submit.prevent="send">
      <div class="input-container">
        <label for="new-email">{{ $t('settings.account.email_change.new') }}</label>
        <input ref="newEmailField" type="email" id="new-email" autocomplete="email" v-model="newEmail" />
      </div>
      <div class="input-container">
        <label for="email-change-password">{{ $t('settings.users.current_password') }}</label>
        <OtPassword id="email-change-password" v-model="currentPassword" autocomplete="current-password" />
      </div>
      <p class="email-change-error" v-if="error" role="alert">{{ error }}</p>
      <div class="email-change-actions">
        <button type="submit" :disabled="busy">{{ $t('settings.account.email_change.send') }}</button>
        <button type="button" class="secondary" @click="cancel">{{ $t('dialog.cancel') }}</button>
      </div>
    </form>

    <form class="email-change-step" v-else-if="step === 'code'" @submit.prevent="confirm">
      <p class="help-text">{{ $t('settings.account.email_change.sent', { email: pendingEmail }) }}</p>
      <div class="input-container">
        <label for="email-change-code">{{ $t('settings.account.email_change.code') }}</label>
        <input
          ref="codeField"
          type="text"
          id="email-change-code"
          v-model="code"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          class="email-change-code"
        />
      </div>
      <p class="email-change-error" v-if="error" role="alert">{{ error }}</p>
      <div class="email-change-actions">
        <button type="submit" :disabled="busy || code.trim().length !== 6">{{ $t('settings.account.email_change.confirm') }}</button>
        <button type="button" class="secondary" @click="cancel">{{ $t('dialog.cancel') }}</button>
      </div>
    </form>
  </div>
</template>

<style scoped lang="scss">
.email-change {
  display: flex;
  flex-direction: column;
  gap: 14px;

  .input-container {
    margin: 0;
  }
}

.email-change-current {
  display: flex;
  gap: 8px;

  input {
    flex: 1;
    min-width: 0;
    margin: 0;
    color: var(--ot-muted);
  }

  button {
    flex: 0 0 auto;
    margin: 0;
  }
}

// The steps of the change, set apart from the fields of the profile around them
.email-change-step {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid var(--ot-line);
  border-radius: var(--ot-radius);
  background: var(--ot-field);
  animation: ot-rise-in var(--ot-dur) var(--ot-ease-out) both;

  p {
    margin: 0;
  }
}

.email-change-code {
  max-width: 12ch;
  font-size: 1.25rem;
  font-weight: 800;
  letter-spacing: 0.3em;
}

.email-change-error {
  padding: 10px 14px;
  border: 1px solid var(--ot-danger-line);
  border-radius: var(--ot-radius);
  background: var(--ot-danger-soft);
  color: var(--ot-danger-text);
  font-size: 0.8125rem;
}

.email-change-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;

  button {
    margin: 0;
  }
}
</style>
