<script setup>
// A file chooser that shows what is chosen (or in place) and a Browse button; the page adds its
// own actions (reset to default) beside it
import { ref, computed } from 'vue'
import { FolderOpen } from 'lucide-vue-next'
import { useTranslate } from '@tolgee/vue'

const props = defineProps({
  modelValue: {
    type: [File, null, String],
    required: true
  },
  accept: {
    type: String,
    default: '*'
  },
  label: {
    type: String,
    default: ''
  },
  preview: {
    type: Object,
    default: null,
    validator: (value) => {
      if (!value) return true
      return value.url && typeof value.url === 'string'
    }
  },
  // The id a <label for> points at: the Browse button
  id: {
    type: String,
    default: undefined
  }
})

const { t } = useTranslate()
const fileInput = ref(null)
const emit = defineEmits(['update:modelValue'])

const handleFileChange = (event) => {
  emit('update:modelValue', event.target.files[0])
}
const triggerFileInput = () => {
  fileInput.value.click()
}

const fileName = computed(() => {
  if (props.modelValue) return typeof props.modelValue === 'string' ? props.modelValue : props.modelValue.name
  return props.label
})
</script>

<template>
  <div class="file-input">
    <input type="file" @change="handleFileChange" ref="fileInput" :accept="accept" tabindex="-1" aria-hidden="true" />
    <div class="file-input-box" @click="triggerFileInput">
      <div class="file-input-preview" v-if="preview">
        <img :src="preview.url" alt="" />
      </div>
      <span class="file-label" :title="fileName">{{ fileName }}</span>
      <div class="file-input-actions" @click.stop>
        <button type="button" class="ot-act" :id="id" @click="triggerFileInput">
          <FolderOpen aria-hidden="true" />
          {{ t('send.browse') }}
        </button>
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.file-input {
  width: 100%;
}
input[type='file'] {
  display: none;
}
.file-input-box {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  box-sizing: border-box;
  min-height: 56px;
  padding: 7px 8px 7px 7px;
  background: var(--ot-field);
  border: 1px solid var(--ot-line-field);
  border-radius: var(--ot-radius);
  cursor: pointer;
  transition: border-color 0.12s ease;

  &:hover {
    border-color: var(--ot-line-strong);
  }
}
// The logo or icon on a ground of its own, so a white logo still shows
.file-input-preview {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  min-width: 40px;
  max-width: 96px;
  height: 40px;
  padding: 0 6px;
  box-sizing: border-box;
  background: var(--ot-raised);
  border: 1px solid var(--ot-line-soft);
  border-radius: var(--ot-btn-radius);

  img {
    max-width: 84px;
    max-height: 28px;
    object-fit: contain;
  }
}
.file-label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  font-size: 0.875rem;
  color: var(--ot-text);
  white-space: nowrap;
  text-overflow: ellipsis;
}
.file-input-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  cursor: default;
}
</style>
