<script setup>
// The anti-robot check of a form open to anyone, made visible: it starts as the form shows,
// says when it passed, and offers to try again when it could not (no network, a refused
// challenge). The proof itself is solved in the browser (api.js, altchaToken).
import { computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { Check, Loader, ShieldAlert } from 'lucide-vue-next'
import { altchaState, altchaToken } from '../../api'

const { t } = useTranslate()

let mounted = false
const prepare = () => altchaToken.prepare().catch(() => {})

onMounted(() => {
  mounted = true
  prepare()
})
onBeforeUnmount(() => {
  mounted = false
})
// Spent by a sending (a wrong password, say): the next one is ready before it is asked for
watch(altchaState, (state) => {
  if (state === 'idle' && mounted) prepare()
})

const label = computed(() => {
  if (altchaState.value === 'verified') return t.value('auth.altcha.verified')
  if (altchaState.value === 'failed') return t.value('auth.altcha.failed')
  return t.value('auth.altcha.verifying')
})
</script>

<template>
  <div class="ot-altcha" :class="`is-${altchaState}`" role="status" aria-live="polite">
    <span class="ot-altcha-box" aria-hidden="true">
      <Check v-if="altchaState === 'verified'" class="ot-check-pop" />
      <ShieldAlert v-else-if="altchaState === 'failed'" />
      <Loader v-else class="spin" />
    </span>
    <span class="ot-altcha-label">{{ label }}</span>
    <button v-if="altchaState === 'failed'" type="button" class="ot-act" @click="prepare">{{ t('auth.altcha.retry') }}</button>
  </div>
</template>

<style lang="scss">
.ot-altcha {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 44px;
  padding: 8px 12px;
  border: 1px solid var(--ot-line);
  border-radius: var(--ot-radius);
  background: var(--ot-field);
  color: var(--ot-muted);
  font-size: 0.8125rem;

  .ot-altcha-box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 22px;
    height: 22px;
    border: 1px solid var(--ot-line-strong);
    border-radius: 6px;
    background: var(--ot-card);

    svg {
      width: 14px;
      height: 14px;
      margin: 0;
    }
  }
  .ot-altcha-label {
    flex: 1;
    min-width: 0;
  }
  button {
    margin: 0;
  }

  &.is-verified {
    color: var(--ot-text);
    .ot-altcha-box {
      border-color: var(--ot-success-line);
      background: var(--ot-success-soft);
      color: var(--ot-success);
    }
  }
  &.is-failed {
    border-color: var(--ot-danger-line);
    background: var(--ot-danger-soft);
    color: var(--ot-danger-text);
    .ot-altcha-box {
      border-color: var(--ot-danger-line);
    }
  }
}
</style>
