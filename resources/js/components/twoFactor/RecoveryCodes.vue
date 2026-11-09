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
      <button class="secondary" @click="copyCodes"><Copy />{{ $t('settings.two_factor.copy') }}</button>
      <button class="secondary" @click="downloadCodes"><Download />{{ $t('settings.two_factor.download') }}</button>
    </div>
  </div>
</template>

<style scoped>
.recovery-codes ul {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.25rem 1rem;
  padding-left: 0;
  list-style: none;
  font-family: monospace;
}
.actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
</style>
