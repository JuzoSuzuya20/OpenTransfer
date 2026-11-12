<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { ColorPicker as cp } from 'vue-color-kit'
import { Palette } from 'lucide-vue-next'
// set it up so we can use v-model
const props = defineProps({
  modelValue: {
    type: String,
    required: true
  }
})

const showPicker = ref(false)

const emit = defineEmits(['update:modelValue'])

const showHidePopup = () => {
  showPicker.value = !showPicker.value
  if (showPicker.value) {
    setTimeout(() => {
      document.addEventListener('click', (e) => {
        if (!e.target.closest('.cp-popup') && !e.target.closest('.cp-popup-trigger')) {
          showPicker.value = false
        }
      })
    }, 100)
  } else {
    setTimeout(() => {
      document.removeEventListener('click', (e) => {
        if (!e.target.closest('.cp-popup')) {
          showPicker.value = false
        }
      })
    }, 100)
  }
}

const handleChangeColor = (color) => {
  let rgbaValues = color.rgba
  let rgbaString = `rgba(${rgbaValues.r}, ${rgbaValues.g}, ${rgbaValues.b}, ${rgbaValues.a})`
  emit('update:modelValue', rgbaString)
}
</script>

<template>
  <div class="colour-picker">
    <div class="colour-picker-button" @click="triggerColourPicker">
      <div class="colour-picker-label">{{ modelValue }}</div>
      <div class="swatch" :class="{ show: showPicker }" @click="showHidePopup">
        <div class="swatch-colour" :style="{ backgroundColor: modelValue }"></div>
      </div>
      <button @click="showHidePopup" class="cp-popup-trigger">
        <Palette />
      </button>
    </div>
    <div class="cp-popup" :class="{ show: showPicker }">
      <cp theme="light" :color="modelValue" :sucker-hide="false" @changeColor="handleChangeColor" />
    </div>
  </div>
</template>

<style scoped>
.colour-picker {
  position: relative;
}

.colour-picker-button {
  width: 100%;
  padding: 10px;
  border-radius: 5px;
  border: none;
  background: var(--input-background-color);
  color: var(--input-text-color);
  margin-bottom: 10px;
  border: 1px solid var(--input-border-color);
  transition: all var(--ot-dur-slow) var(--ot-ease-out);
  outline: 2px solid transparent;
  height: 50px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  .colour-picker-label {
    flex: 1;
    font-style: italic;
    padding-right: 10px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding-left: 45px;
  }
  button {
    height: 40px;
    width: 40px;
    padding: 0;
    svg {
      width: 20px;
      height: 20px;
      margin: 0;
    }
  }
}

.swatch {
  position: absolute;
  left: 0px;
  top: 0;
  width: 50px;
  height: 50px;

  display: flex;
  align-items: center;
  justify-content: center;

  transition: all var(--ot-dur-slow) var(--ot-ease-out);

  border-radius: 5px;

  --swatch-background-color: #dedede;
  --swatch-checker-size: 10px;
  --half-checker-size: calc(var(--swatch-checker-size) / 2);
  background-image: linear-gradient(45deg, var(--swatch-background-color) 25%, transparent 25%),
    linear-gradient(135deg, var(--swatch-background-color) 25%, transparent 25%), linear-gradient(45deg, transparent 75%, var(--swatch-background-color) 75%),
    linear-gradient(135deg, transparent 75%, var(--swatch-background-color) 75%);
  background-size: var(--swatch-checker-size) var(--swatch-checker-size); /* Must be a square */
  background-position: 0 0, var(--half-checker-size) 0, var(--half-checker-size) calc(var(--half-checker-size) * -1), 0px var(--half-checker-size); /* Must be half of one side of the square */
  .swatch-colour {
    width: 35px;
    height: 35px;
    border-radius: 50%;
  }
}

.cp-popup {
  position: absolute;
  top: 0;
  right: 0;
  z-index: 230;
  opacity: 0;
  pointer-events: none;
  transition: all var(--ot-dur-slow) var(--ot-ease-out);
  &.show {
    opacity: 1;
    pointer-events: auto;
  }
}
</style>
