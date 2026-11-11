<script setup>
// Recovery codes, shown once: only their hashes are stored.
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import { Copy, Download } from 'lucide-vue-next'

const props = defineProps({ codes: { type: Array, required: true } })

const { t } = useTranslate()
const toast = useToast()

const copyCodes = async () => {
  try {
    await navigator.clipboard.writeText(props.codes.join('\n'))
    toast.success(t.value('settings.two_factor.codes_copied'))
  } catch (error) {
    toast.error(t.value('settings.two_factor.action_failed'))
  }
}

const downloadCodes = () => {
  const blob = new Blob([props.codes.join('\n') + '\n'], { type: 'text/plain' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = 'recovery-codes.txt'
  link.click()
  URL.revokeObjectURL(link.href)
}
</script>

<template>
  <div class="recovery-codes">
    <p>{{ $t('settings.two_factor.recovery_codes_save') }}</p>
    <ul>
      <li v-for="code in codes" :key="code"><code>{{ code }}</code></li>
    </ul>
    <div class="actions">
      <button type="button" class="ot-act" @click="copyCodes"><Copy aria-hidden="true" />{{ $t('settings.two_factor.copy') }}</button>
      <button type="button" class="ot-act" @click="downloadCodes"><Download aria-hidden="true" />{{ $t('settings.two_factor.download') }}</button>
    </div>
  </div>
</template>

<style scoped lang="scss">
.recovery-codes {
  display: flex;
  flex-direction: column;
  gap: 10px;

  p {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--ot-text);
  }
}
/* The codes are data to copy: fixed-width figures, two columns, on the ground of a field */
.recovery-codes ul {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px 16px;
  margin: 0;
  padding: 12px 14px;
  list-style: none;
  background: var(--ot-page);
  border: 1px solid var(--ot-line-soft);
  border-radius: var(--ot-radius-sm);
  font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace;
  font-size: 0.875rem;
  color: var(--ot-heading);
}
.actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
</style>
