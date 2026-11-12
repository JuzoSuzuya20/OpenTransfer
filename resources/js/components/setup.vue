<script setup>
import { ref, computed, onMounted } from 'vue'
import { UserPlus, LogIn, Settings } from 'lucide-vue-next'
import { getApiUrl } from '../utils'
import { createFirstUser, saveFirstRunSettings, login } from '../api'
import { useToast } from 'vue-toastification'
import { store } from '../store'
import FileInput from './fileInput.vue'
import BrandMark from './layout/BrandMark.vue'

import { useTranslate } from '@tolgee/vue'

const apiUrl = getApiUrl()
const toast = useToast()
const emailInput = ref(null)
const { t } = useTranslate()

// Step 1 = account creation, Step 2 = optional settings
const currentStep = ref(1)
const isLoggingIn = ref(false)

const newUser = ref({
  username: '',
  name: '',
  email: '',
  password: '',
  password_confirmation: ''
})

// Get the current URL from the browser as default
const getCurrentUrl = () => {
  const url = new URL(window.location.href)
  // Remove any path, just keep scheme + host + port
  return `${url.protocol}//${url.host}`
}

const optionalSettings = ref({
  application_name: '',
  application_url: getCurrentUrl(),
  logo: null
})

const errors = ref({
  name: '',
  password: '',
  email: '',
  password_confirmation: '',
  application_name: '',
  application_url: '',
  logo: ''
})

const logoPreview = computed(() => {
  if (optionalSettings.value.logo instanceof File) {
    return { url: URL.createObjectURL(optionalSettings.value.logo) }
  }
  return null
})

onMounted(() => {
  emailInput.value.focus()
  //set the title
  document.title = 'OpenTransfer'
})

// Step 1: Create the admin account
const createAccount = async () => {
  errors.value = {}
  if (newUser.value.password !== newUser.value.password_confirmation) {
    errors.value.password_confirmation = 'Password confirmation does not match'
  }

  if (Object.keys(errors.value).length > 0) {
    toast.error('Please fix the errors before saving')
    return
  }

  try {
    await createFirstUser(newUser.value)
    toast.success(t.value('setup.account_created'))
    // Move to step 2
    currentStep.value = 2
  } catch (error) {
    if (error.data && error.data.errors) {
      errors.value = error.data.errors
    }
    toast.error('Failed to create user')
  }
}

// Step 2: Save optional settings and login
const saveSettingsAndLogin = async () => {
  isLoggingIn.value = true
  errors.value = {}
  
  try {
    // First login to get auth token
    const data = await login(newUser.value.email, newUser.value.password)
    store.authSuccess(data)
    
    // Now save the optional settings (authenticated)
    const settingsToSave = {}
    
    if (optionalSettings.value.application_name) {
      settingsToSave.application_name = optionalSettings.value.application_name
    }
    if (optionalSettings.value.application_url) {
      settingsToSave.application_url = optionalSettings.value.application_url
    }
    if (optionalSettings.value.logo instanceof File) {
      settingsToSave.logo = optionalSettings.value.logo
    }
    
    // Only save if there are settings to save
    if (Object.keys(settingsToSave).length > 0) {
      await saveFirstRunSettings(settingsToSave)
    }
    
    toast.success(t.value('setup.settings_saved'))
    window.location.href = '/'
  } catch (error) {
    isLoggingIn.value = false
    if (error.data && error.data.errors) {
      errors.value = error.data.errors
    }
    toast.error('Failed to save settings')
  }
}

// Skip settings and just login
const skipAndLogin = async () => {
  isLoggingIn.value = true
  
  try {
    const data = await login(newUser.value.email, newUser.value.password)
    store.authSuccess(data)
    window.location.href = '/'
  } catch (error) {
    isLoggingIn.value = false
    toast.error('Failed to login')
  }
}
</script>

<template>
  <div class="setup-container">
    <div class="setup-inner">
      <div class="setup-logo-container">
        <BrandMark :size="48" :with-name="false" />
        <h1 class="setup-title">OpenTransfer</h1>
      </div>

      <!-- Step indicator -->
      <div class="step-indicator">
        <div class="step" :class="{ active: currentStep === 1, completed: currentStep > 1 }">1</div>
        <div class="step-line" :class="{ completed: currentStep > 1 }"></div>
        <div class="step" :class="{ active: currentStep === 2 }">2</div>
      </div>

      <!-- Step 1: Create Admin Account -->
      <div v-if="currentStep === 1">
        <p class="setup-intro">{{ t('setup.intro') }}</p>

        <div class="seperator"></div>

        <div class="setup-form">
          <!-- email -->
          <div class="input-container mt-2">
            <label for="email">{{ t('setup.first_user.email') }}</label>
            <input
              type="email"
              autocomplete="email"
              v-model="newUser.email"
              required
              id="email"
              :class="{ error: errors.email }"
              @keyup.enter="createAccount"
              ref="emailInput"
            />
            <div class="error-message" v-if="errors.email">
              {{ errors.email[0] }}
            </div>
          </div>

          <!-- full name -->
          <div class="input-container mt-2">
            <label for="name">{{ t('setup.first_user.name') }}</label>
            <input
              type="text"
              autocomplete="name"
              v-model="newUser.name"
              required
              id="name"
              :class="{ error: errors.name }"
              @keyup.enter="createAccount"
            />
            <div class="error-message" v-if="errors.name">
              {{ errors.name[0] }}
            </div>
          </div>

          <!-- password -->
          <div class="input-container mt-2">
            <label for="password">{{ t('setup.first_user.password') }}</label>
            <input
              type="password"
              autocomplete="new-password"
              v-model="newUser.password"
              required
              id="password"
              :class="{ error: errors.password }"
              @keyup.enter="createAccount"
            />
            <div class="error-message" v-if="errors.password">
              {{ errors.password[0] }}
            </div>
          </div>

          <!-- confirm password -->
          <div class="input-container mt-2">
            <label for="password_confirmation">{{ t('setup.first_user.password_confirmation') }}</label>
            <input
              type="password"
              autocomplete="new-password"
              v-model="newUser.password_confirmation"
              required
              id="password_confirmation"
              :class="{ error: errors.password_confirmation }"
              @keyup.enter="createAccount"
            />
            <div class="error-message" v-if="errors.password_confirmation">
              {{ errors.password_confirmation[0] }}
            </div>
          </div>

          <div class="button-bar mt-3">
            <button class="cta" @click="createAccount">
              <UserPlus aria-hidden="true" />
              {{ t('setup.create_admin_account') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Step 2: Optional Settings -->
      <div v-if="currentStep === 2">
        <p class="setup-intro">{{ t('setup.step2_intro') }}</p>

        <div class="seperator"></div>

        <div class="setup-form">
          <div class="input-container mt-2">
            <label for="application_name">{{ t('setup.application_name') }}</label>
            <input
              type="text"
              v-model="optionalSettings.application_name"
              :placeholder="t('setup.application_name_placeholder')"
              id="application_name"
              :class="{ error: errors.application_name }"
            />
            <div class="error-message" v-if="errors.application_name">
              {{ errors.application_name[0] }}
            </div>
          </div>

          <div class="input-container mt-2">
            <label for="application_url">{{ t('setup.application_url') }}</label>
            <input
              type="url"
              v-model="optionalSettings.application_url"
              :placeholder="t('setup.application_url_placeholder')"
              id="application_url"
              :class="{ error: errors.application_url }"
            />
            <div class="error-message" v-if="errors.application_url">
              {{ errors.application_url[0] }}
            </div>
          </div>

          <div class="input-container mt-2">
            <label for="logo">{{ t('setup.logo') }}</label>
            <FileInput
              id="logo"
              v-model="optionalSettings.logo"
              accept="image/png, image/svg+xml"
              :preview="logoPreview"
              :label="t('setup.logo_placeholder')"
            />
            <div class="error-message" v-if="errors.logo">
              {{ errors.logo[0] }}
            </div>
          </div>

          <div class="button-bar mt-3">
            <button class="cta" @click="saveSettingsAndLogin" :disabled="isLoggingIn">
              <Settings aria-hidden="true" />
              {{ t('setup.save_and_login') }}
            </button>
          </div>

          <div class="button-bar mt-2">
            <button class="ghost" @click="skipAndLogin" :disabled="isLoggingIn">
              <LogIn aria-hidden="true" />
              {{ t('setup.skip_and_login') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
// The first run: one card in the column of the sign-in screen
.setup-container {
  width: 100%;

  .setup-inner {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 24px 24px 27px;
    background: var(--ot-card);
    color: var(--ot-text);
    border: 1px solid var(--ot-line);
    border-radius: var(--ot-radius);
    overflow: hidden;

    &::after {
      content: "";
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      height: 3px;
      background: var(--ot-accent);
    }

    p {
      margin: 0;
      color: var(--ot-muted);
    }
    .setup-intro {
      margin: 0 auto;
      max-width: 44ch;
      text-align: center;
      text-wrap: pretty;
    }
  }
}

button {
  width: 100%;
  margin: 8px 0 0;
}

.setup-logo-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;

  .setup-title {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: 0.02em;
    text-transform: uppercase;
    color: var(--ot-heading);
  }
}

.step-indicator {
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 6px;

  .step {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    box-sizing: border-box;
    border-radius: 50%;
    background: var(--ot-raised);
    border: 1px solid var(--ot-line-strong);
    color: var(--ot-muted);
    font-size: 0.8125rem;
    font-weight: 700;
    transition:
      background-color var(--ot-dur) var(--ot-ease-out),
      color var(--ot-dur) var(--ot-ease-out),
      transform var(--ot-dur) var(--ot-ease-spring);

    &.active,
    &.completed {
      background: var(--ot-accent-fill);
      border-color: transparent;
      color: var(--ot-on-accent);
    }
    &.active {
      transform: scale(calc(1 + 0.08 * var(--ot-motion)));
    }
  }
  .step-line {
    width: 40px;
    height: 2px;
    background: var(--ot-line-strong);
    transition: background-color var(--ot-dur-slow) var(--ot-ease-out);

    &.completed {
      background: var(--ot-accent);
    }
  }
}
.seperator {
  width: 100%;
  height: 1px;
  margin: 14px 0 16px;
  background: var(--ot-line-soft);
}
</style>
