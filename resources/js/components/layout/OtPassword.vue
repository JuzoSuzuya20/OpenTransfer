<script setup>
// A password field with an eye to show what was typed. The attributes (autocomplete, events) go to
// the input; focus() focuses it, for the forms that move the focus to the password.
import { ref } from 'vue'
import { Eye, EyeOff } from 'lucide-vue-next'
import { useTranslate } from '@tolgee/vue'

defineOptions({ inheritAttrs: false })

defineProps({
  modelValue: { type: String, default: '' },
  id: { type: String, required: true }
})
const emit = defineEmits(['update:modelValue'])
const { t } = useTranslate()

const input = ref(null)
const shown = ref(false)

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <div class="field-with-action ot-password">
    <input
      ref="input"
      :id="id"
      :type="shown ? 'text' : 'password'"
      :value="modelValue"
      v-bind="$attrs"
      @input="emit('update:modelValue', $event.target.value)"
    />
    <button
      type="button"
      class="field-action"
      :aria-label="shown ? t('send.hide_password') : t('send.show_password')"
      :aria-pressed="shown"
      :aria-controls="id"
      @click="shown = !shown"
    >
      <EyeOff v-if="shown" class="ot-check-pop" aria-hidden="true" />
      <Eye v-else class="ot-check-pop" aria-hidden="true" />
    </button>
  </div>
</template>
