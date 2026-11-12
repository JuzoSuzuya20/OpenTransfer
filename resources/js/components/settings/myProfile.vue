<script setup>
import { ref, onMounted, defineExpose, nextTick } from 'vue'
import { getMyProfile, updateMyProfile, getAvailableAuthProviders, unlinkProvider } from '../../api'
import { CircleX, UserRoundCheck, UserRoundPen, Fingerprint, Link, Unlink, Save } from 'lucide-vue-next'
import UserAvatar from '../layout/UserAvatar.vue'
import { useToast } from 'vue-toastification'
import { store } from '../../store'
import TwoFactorSettings from './TwoFactorSettings.vue'

import { useTranslate } from '@tolgee/vue'
import { useConfirmDialog } from '../../composables/useConfirmDialog'

const { t } = useTranslate()

const toast = useToast()
const profile = ref(null)
const editUserFormActive = ref(false)
const editUser = ref({})
const errors = ref({})
const availableAuthProviders = ref([])
const onLocalhost = ref(false)

onMounted(async () => {
  await loadEverything()
  editUser.value = {
    ...profile.value,
    password: null,
    password_confirmation: null,
    current_password: null
  }
  if (store.autoShowProfileEdit) {
    editUserFormActive.value = true
    store.autoShowProfileEdit = false
  }
  onLocalhost.value = window.location.hostname === 'localhost'
})

const loadEverything = async () => {
  await loadProfile()
  nextTick(async () => {
    await loadAvailableAuthProviders()
  })
}

const loadProfile = async () => {
  profile.value = await getMyProfile()
}

const loadAvailableAuthProviders = async () => {
  let providers = await getAvailableAuthProviders()
  if (profile.value.linked_accounts.length > 0) {
    availableAuthProviders.value = providers.map((p) => {
      return {
        ...p,
        is_linked: profile.value.linked_accounts.some((ap) => ap.id === p.id)
      }
    })
  } else {
    availableAuthProviders.value = providers
  }
}

const editUserFormClickOutside = (e) => {
  if (!e.target.closest('.user-form')) {
    editUserFormActive.value = false
  }
}

const saveUser = async () => {
  errors.value = {}

  if (Object.keys(errors.value).length > 0) {
    toast.error(t.value('settings.users.profile_errors'))
    return
  }

  try {
    const profileData = {
      name: profile.value.name,
      email: profile.value.email
    }
    const updatedUser = await updateMyProfile(profileData)
    profile.value = updatedUser
    toast.success(t.value('settings.users.profile_updated'))
  } catch (error) {
    toast.error(t.value('settings.users.profile_update_failed'))
    errors.value = error.data.errors
  }
}

const savePassword = async () => {
  errors.value = {}

  if (!editUser.value.password) {
    errors.value.password = t.value('settings.users.password_required')
  }

  if (editUser.value.password.length < 8) {
    errors.value.password = t.value('settings.users.password_min_length', { length: 8 })
  }

  if (editUser.value.password !== editUser.value.password_confirmation) {
    errors.value.password_confirmation = t.value('settings.users.password_confirmation_mismatch')
  }

  if (editUser.value.password && editUser.value.current_password === null) {
    errors.value.current_password = t.value('settings.users.current_password_required')
  }

  if (Object.keys(errors.value).length > 0) {
    toast.error(t.value('settings.users.password_errors'))
    return
  }

  try {
    const passwordData = {
      password: editUser.value.password,
      password_confirmation: editUser.value.password_confirmation,
      current_password: editUser.value.current_password
    }
    const updatedUser = await updateMyProfile(passwordData)
    profile.value = updatedUser
    editUserFormActive.value = false
    toast.success(t.value('settings.users.password_updated'))
  } catch (error) {
    toast.error(t.value('settings.users.password_update_failed'))
    errors.value = error.data.errors
  }
}

//define exposed methods
defineExpose({
  saveUser
})

const emit = defineEmits(['navItemClicked'])
const handleNavItemClicked = (item) => {
  emit('navItemClicked', item)
}

const confirmDialog = useConfirmDialog()
const handleLinkProvider = async (provider) => {
  const confirm = await confirmDialog.show({
    title: t.value('dialog.title'),
    message: t.value('settings.users.link_provider_confirm'),
    okText: t.value('settings.account.create_link'),
    cancelText: t.value('dialog.cancel'),
    tone: 'default'
  })
  if (confirm) {
    try {
      window.location.href = `/auth/provider/${provider.id}/link`
    } catch (error) {
      console.error('Failed to link provider', error)
    }
  }
}

const handleUnlinkProvider = async (provider) => {
  const confirm = await confirmDialog.show({
    title: t.value('dialog.title'),
    message: t.value('settings.users.unlink_provider_confirm'),
    okText: t.value('settings.account.unlink'),
    cancelText: t.value('dialog.cancel'),
    tone: 'danger'
  })
  if (confirm) {
    try {
      await unlinkProvider(provider.id)
      toast.success(t.value('settings.users.provider_unlinked'))
      await loadEverything()
    } catch (error) {
      toast.error(t.value('settings.users.provider_unlink_failed'))
    }
  }
}
</script>

<template>
  <div class="profile-layout" v-if="profile">
    <div class="profile-column">
      <section class="ot-card profile-card" id="my_account" aria-labelledby="h-my-account">
        <h2 class="ot-card-title" id="h-my-account">{{ $t('settings.title.myProfile') }}</h2>
        <div class="profile-identity">
          <UserAvatar :seed="profile.avatar_seed ?? ''" :name="profile.name" :size="48" />
          <div>
            <div class="profile-name">{{ profile.name }}</div>
            <div class="profile-email">{{ profile.email }}</div>
          </div>
        </div>
        <div class="input-container">
          <label for="email">{{ $t('settings.account.email') }}</label>
          <input type="email" id="email" autocomplete="email" v-model="profile.email" :class="{ error: errors.email }" />
          <div class="error-message" v-if="errors.email">{{ Array.isArray(errors.email) ? errors.email[0] : errors.email }}</div>
        </div>
        <div class="input-container">
          <label for="name">{{ $t('settings.account.name') }}</label>
          <input type="text" id="name" autocomplete="name" v-model="profile.name" :class="{ error: errors.name }" />
          <div class="error-message" v-if="errors.name">{{ Array.isArray(errors.name) ? errors.name[0] : errors.name }}</div>
        </div>
        <div class="profile-actions">
          <button type="button" @click="saveUser">
            <Save aria-hidden="true" />
            {{ $t('admin.save') }}
          </button>
          <button type="button" class="secondary" @click="editUserFormActive = true">
            <UserRoundPen aria-hidden="true" />
            {{ $t('settings.account.change_password') }}
          </button>
        </div>
      </section>

      <section class="ot-card profile-card" id="linked_accounts" aria-labelledby="h-linked" v-if="availableAuthProviders.length > 0">
        <h2 class="ot-card-title" id="h-linked">{{ $t('settings.title.linked_accounts') }}</h2>
        <p class="card-description">{{ $t('settings.account.linked_accounts_description') }}</p>
        <ul class="providers">
          <li v-for="provider in availableAuthProviders" :key="provider.id" class="provider-row">
            <span class="provider-icon" aria-hidden="true" v-if="provider.icon" v-html="provider.icon"></span>
            <span class="provider-icon" aria-hidden="true" v-else><Fingerprint /></span>
            <div class="provider-main">
              <strong>{{ provider.name }}</strong>
              <span class="ot-pill" :class="{ 'is-success': provider.is_linked }">
                {{ provider.is_linked ? t('settings.users.linked') : t('settings.users.not_linked') }}
              </span>
            </div>
            <button type="button" class="ot-act" v-if="!provider.is_linked" @click="handleLinkProvider(provider)">
              <Link aria-hidden="true" />
              {{ $t('settings.account.create_link') }}
            </button>
            <button type="button" class="ot-act" v-else @click="handleUnlinkProvider(provider)">
              <Unlink aria-hidden="true" />
              {{ $t('settings.account.unlink') }}
            </button>
          </li>
        </ul>
      </section>
    </div>

    <div class="profile-column is-wide" v-if="!store.guest">
      <TwoFactorSettings />
    </div>
  </div>

  <div class="user-form-overlay" :class="{ active: editUserFormActive }" @click="editUserFormClickOutside">
    <div class="user-form">
      <h2>
        <UserRoundPen />
        {{ $t('settings.account.change_password') }}
      </h2>

      <div class="input-container">
        <label for="current_password">{{ $t('settings.users.current_password') }}</label>
        <input
          type="password"
          v-model="editUser.current_password"
          :placeholder="$t('settings.users.current_password')"
          required
          :class="{ error: errors.current_password }"
        />
        <div class="error-message" v-if="errors.current_password">
          {{ errors.current_password }}
        </div>
      </div>
      <div class="input-container">
        <label for="password">{{ $t('settings.users.password') }}</label>
        <input
          type="password"
          v-model="editUser.password"
          :placeholder="$t('settings.users.password')"
          required
          :class="{ error: errors.password }"
        />
        <div class="error-message" v-if="errors.password">
          {{ errors.password }}
        </div>
      </div>
      <div class="input-container">
        <label for="password_confirmation">{{ $t('settings.users.password_confirmation') }}</label>
        <input
          type="password"
          v-model="editUser.password_confirmation"
          :placeholder="$t('settings.users.password_confirmation')"
          required
          :class="{ error: errors.password_confirmation }"
        />
        <div class="error-message" v-if="errors.password_confirmation">
          {{ errors.password_confirmation }}
        </div>
      </div>

      <div class="button-bar">
        <button @click="savePassword">
          <UserRoundCheck />
          {{ $t('settings.users.save_changes') }}
        </button>
        <button class="secondary close-button" @click="editUserFormActive = false">
          <CircleX />
          {{ $t('settings.users.close') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
// Two supple columns: the account on one side, its second factor on the other
.profile-layout {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 20px;
}
.profile-column {
  flex: 1 1 360px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;

  &.is-wide {
    flex: 1.3 1 440px;
  }
}
.profile-card {
  display: flex;
  flex-direction: column;
  gap: 14px;

  .input-container {
    margin: 0;
  }
}
.profile-identity {
  display: flex;
  align-items: center;
  gap: 12px;
}
.profile-name {
  font-size: 1.0625rem;
  font-weight: 800;
  color: var(--ot-heading);
}
.profile-email {
  font-size: 0.8125rem;
  color: var(--ot-muted);
  overflow-wrap: anywhere;
}
.profile-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;

  button {
    margin: 0;
  }
}
.card-description {
  margin: 0;
  font-size: 0.8125rem;
  color: var(--ot-muted);
}
.providers {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.provider-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  background: var(--ot-field);
  border: 1px solid var(--ot-line-soft);
  border-radius: var(--ot-radius);
}
.provider-icon {
  display: inline-flex;
  color: var(--ot-accent-text);

  :deep(svg) {
    width: 22px;
    height: 22px;
  }
}
.provider-main {
  flex: 1;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  min-width: 0;

  strong {
    color: var(--ot-heading);
  }
}
</style>
