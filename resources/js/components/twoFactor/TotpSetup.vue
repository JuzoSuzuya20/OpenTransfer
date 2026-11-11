<script setup>
// Pairing an authenticator app: QR code or manual key, then a code to prove it works.
import { ref, computed } from 'vue'
import qrcode from 'qrcode-generator'
import { ShieldCheck } from 'lucide-vue-next'

const props = defineProps({
  setup: { type: Object, required: true },
  busy: { type: Boolean, default: false }
})
const emit = defineEmits(['confirm'])

const code = ref('')

// Generated here from the URI: the secret never goes through a third-party service
const qrSvg = computed(() => {
  const qr = qrcode(0, 'M')
  qr.addData(props.setup.otpauth_uri)
  qr.make()
  return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true })
})

const confirm = () => {
  if (code.value) {
    emit('confirm', code.value)
    code.value = ''
  }
}
</script>

<template>
  <div class="totp-setup">
    <p>{{ $t('settings.two_factor.scan_qr') }}</p>
    <div class="qr" v-html="qrSvg"></div>
    <p>
      {{ $t('settings.two_factor.manual_key') }}
      <code class="secret">{{ setup.secret }}</code>
    </p>
    <div class="input-container">
      <label for="totp_confirm_code">{{ $t('settings.two_factor.enter_code') }}</label>
      <input
        id="totp_confirm_code"
        type="text"
        inputmode="numeric"
        autocomplete="one-time-code"
        maxlength="6"
        placeholder="123456"
        v-model="code"
        @keyup.enter="confirm"
      />
    </div>
    <button type="button" :disabled="busy || !code" @click="confirm">
      <ShieldCheck aria-hidden="true" />
      {{ $t('settings.two_factor.activate') }}
    </button>
  </div>
</template>

<style scoped lang="scss">
.totp-setup {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;

  p {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--ot-text);
  }
  .input-container {
    align-self: stretch;
    max-width: 260px;
    margin: 0;
  }
  button {
    margin: 0;
  }
}
/* The code is read by a phone camera: always dark on white, whatever the scheme */
.qr {
  width: 184px;
  max-width: 100%;
  padding: 8px;
  background: #fff;
  border-radius: var(--ot-radius);
}
.qr :deep(svg) {
  display: block;
  width: 100%;
  height: auto;
}
.secret {
  padding: 1px 6px;
  border-radius: 3px;
  background: var(--ot-field);
  color: var(--ot-heading);
  word-break: break-all;
  user-select: all;
}
</style>
