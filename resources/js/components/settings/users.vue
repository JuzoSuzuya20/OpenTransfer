<script setup>
import { ref, onMounted, defineEmits } from 'vue'
import { getUsers, createUser, deleteUser, updateUser, forceResetPassword, resetUserTwoFactor } from '../../api'
import { UserPen, Trash, UserPlus, CircleX, UserRoundCheck, FolderOpen, KeyRound, ShieldOff } from 'lucide-vue-next'
import RowMenu from '../layout/RowMenu.vue'
import AppLink from '../layout/AppLink.vue'
import { store } from '../../store'
import { useToast } from 'vue-toastification'
import { niceDate } from '../../utils'
import UserAvatar from '../layout/UserAvatar.vue'
import { useConfirmDialog } from '../../composables/useConfirmDialog'
import { useSetting } from '../../composables/useSetting'

import { useTranslate } from '@tolgee/vue'

const { t } = useTranslate()

const emit = defineEmits(['viewUserShares'])

const toast = useToast()
const confirmDialog = useConfirmDialog()
const users = ref([])
const errors = ref({})

// Subscribe to self_registration_enabled setting - auto-updates when settings change
const { value: selfRegistrationEnabled } = useSetting('self_registration_enabled', 'system.auth', false)

const newUser = ref({})
const editUser = ref({})

onMounted(async () => {
  loadUsers()
  newUser.value = getEmptyUser()
})

const loadUsers = () => {
  getUsers().then((data) => {
    users.value = data.users
  })
}

const handleDeleteUserClick = async (id) => {
  if (id === store.userId) {
    toast.error(t.value('settings.users.cannot_delete_yourself'))
    return
  }

  const confirmed = await confirmDialog.show({
    title: t.value('settings.users.delete'),
    message: t.value('confirm_delete_user'),
    okText: t.value('settings.users.delete'),
    cancelText: t.value('settings.close')
  })

  if (confirmed) {
    deleteUser(id).then(
      (data) => {
        loadUsers()
        toast.success(t.value('settings.users.user_deleted_successfully'))
      },
      (error) => {
        toast.error(t.value('settings.users.failed_to_delete_user'))
      }
    )
  }
}

const handleEditUserClick = (user) => {
  editUser.value = user
  editUser.value.admin = user.admin == 1
  editUser.value.must_change_password = user.must_change_password == 1
  editUserFormActive.value = true
}

const addUser = () => {
  newUserFormActive.value = true
}

//expose addUser to parent
defineExpose({
  addUser
})

const newUserFormActive = ref(false)

const newUserFormClickOutside = (event) => {
  if (!event.target.closest('.user-form')) {
    newUserFormActive.value = false
  }
}

const editUserFormActive = ref(false)

const editUserFormClickOutside = (event) => {
  if (!event.target.closest('.user-form')) {
    editUserFormActive.value = false
  }
}

const resetPasswordFormActive = ref(false)
const resetPasswordUser = ref(null)

const resetPasswordFormClickOutside = (event) => {
  if (!event.target.closest('.user-form')) {
    resetPasswordFormActive.value = false
    resetPasswordUser.value = null
  }
}

const saveUser = () => {
  errors.value = {}

  if (newUserFormActive.value) {
    createUser(newUser.value).then(
      (data) => {
        loadUsers()
        newUserFormActive.value = false
        newUser.value = getEmptyUser()
        toast.success(t.value('settings.users.user_created_successfully'))
      },
      (error) => {
        errors.value = error.data.errors
        toast.error(t.value('settings.users.failed_to_create_user'))
      }
    )
  } else if (editUserFormActive.value) {
    updateUser(editUser.value).then(
      (data) => {
        loadUsers()
        editUserFormActive.value = false
        editUser.value = getEmptyUser()
        toast.success(t.value('settings.users.user_updated_successfully'))
      },
      (error) => {
        errors.value = error.data.errors
        toast.error(t.value('settings.users.failed_to_update_user'))
      }
    )
  }
}

const getEmptyUser = () => {
  return {
    email: '',
    password: '',
    password_confirmation: '',
    name: '',
    admin: false,
    must_change_password: false
  }
}

const handleViewUserShares = (user) => {
  emit('viewUserShares', user)
}

const handleResetTwoFactor = async (user) => {
  const confirmed = await confirmDialog.show({
    title: t.value('settings.users.reset_two_factor'),
    message: t.value('settings.users.confirm_reset_two_factor', { name: user.name }),
    okText: t.value('settings.users.reset_two_factor'),
    cancelText: t.value('settings.close')
  })
  if (!confirmed) return

  try {
    await resetUserTwoFactor(user.id)
    toast.success(t.value('settings.users.reset_two_factor_success'))
    loadUsers()
  } catch (error) {
    toast.error(t.value('settings.users.reset_two_factor_failed'))
  }
}

const isSelf = (user) => user.id === store.userId
const hasSecondFactor = (user) => Boolean(user.totp_enabled_at || user.passkeys_exists)

// Everything but editing goes in the menu of the row: one visible action per account
const menuItems = (user) => [
  { label: t.value('settings.users.view_shares'), icon: FolderOpen, action: () => handleViewUserShares(user) },
  { label: t.value('settings.users.force_reset_password'), icon: KeyRound, disabled: isSelf(user), action: () => handleForceResetPassword(user) },
  ...(hasSecondFactor(user)
    ? [{ label: t.value('settings.users.reset_two_factor'), icon: ShieldOff, disabled: isSelf(user), action: () => handleResetTwoFactor(user) }]
    : []),
  { label: t.value('settings.users.delete'), icon: Trash, danger: true, disabled: isSelf(user), action: () => handleDeleteUserClick(user.id) }
]

const handleForceResetPassword = (user) => {
  if (user.id === store.userId) {
    toast.error(t.value('settings.users.cannot_force_reset_own_password'))
    return
  }

  resetPasswordUser.value = user
  resetPasswordFormActive.value = true
}

const confirmForceResetPassword = () => {
  if (!resetPasswordUser.value) return

  forceResetPassword(resetPasswordUser.value.id).then(
    (data) => {
      loadUsers()
      toast.success(t.value('settings.users.force_reset_password_success'))
      resetPasswordFormActive.value = false
      resetPasswordUser.value = null
    },
    (error) => {
      toast.error(t.value('settings.users.force_reset_password_failed'))
    }
  )
}
</script>

<template>
  <section class="ot-card users-card" aria-labelledby="h-users">
    <div class="users-head">
      <h2 class="ot-card-title" id="h-users">{{ $t('settings.title.users') }}</h2>
      <span class="ot-pill">{{ $t('settings.users.count', { count: users.length }) }}</span>
    </div>
    <ul class="user-list">
      <li v-for="user in users" :key="user.id" class="user-row">
        <UserAvatar :seed="user.avatar_seed ?? ''" :name="user.name" :size="32" />
        <div class="user-row-main">
          <div class="user-row-name">
            {{ user.name }}
            <span v-if="isSelf(user)" class="ot-pill is-accent">{{ $t('settings.users.you') }}</span>
          </div>
          <div class="user-row-meta">{{ user.email }} · {{ $t('settings.users.created_on', { date: niceDate(user.created_at) }) }}</div>
        </div>
        <div class="user-row-tags">
          <span v-if="user.admin" class="ot-pill">{{ $t('settings.users.admin') }}</span>
          <span v-if="hasSecondFactor(user)" class="ot-pill is-success" :title="$t('settings.users.two_factor_on')">2FA</span>
          <span v-if="user.must_change_password" class="ot-pill is-warning">{{ $t('settings.users.must_change_password') }}</span>
        </div>
        <div class="user-row-actions">
          <AppLink v-if="isSelf(user)" to="/profile" class="ot-act">
            <UserPen aria-hidden="true" />
            {{ $t('settings.users.edit_my_profile') }}
          </AppLink>
          <button v-else type="button" class="ot-act" @click="handleEditUserClick(user)">
            <UserPen aria-hidden="true" />
            {{ $t('settings.users.edit') }}
          </button>
          <RowMenu :label="$t('shares.more_actions', { name: user.name })" :items="menuItems(user)" />
        </div>
      </li>
    </ul>
  </section>

  <div class="user-form-overlay" :class="{ active: newUserFormActive }" @click="newUserFormClickOutside">
    <div class="user-form">
      <h2>
        <UserPlus />
        {{ $t('settings.users.add') }}
      </h2>
      <p v-html="$t('settings.users.add_user_description')"></p>
      <p v-if="selfRegistrationEnabled">{{ $t('settings.users.add_user_self_registration_tip_on') }}</p>
      <p v-else>{{ $t('settings.users.add_user_self_registration_tip_off') }}</p>
      <div class="input-container">
        <label for="new_user_email">{{ $t('settings.users.email') }}</label>
        <input
          type="email"
          v-model="newUser.email"
          id="new_user_email"
          :placeholder="$t('settings.users.email')"
          required
          :class="{ error: errors.email }"
        />
        <div class="error-message" v-if="errors.email">
          {{ errors.email[0] }}
        </div>
      </div>
      <div class="input-container">
        <label for="new_user_name">{{ $t('settings.users.name') }}</label>
        <input
          type="text"
          v-model="newUser.name"
          id="new_user_name"
          :placeholder="$t('settings.users.name')"
          required
          :class="{ error: errors.name }"
        />
        <div class="error-message" v-if="errors.name">
          {{ errors.name[0] }}
        </div>
      </div>

      <div class="checkbox-container">
        <input type="checkbox" v-model="newUser.admin" id="admin" />
        <label for="admin">{{ $t('settings.users.admin') }}</label>
        <p class="help-text">
          {{ $t('settings.users.admin_help_text') }}
          <br />
          {{ $t('settings.users.admin_help_text_2') }}
        </p>
      </div>

      <div class="button-bar">
        <button @click="saveUser">
          <UserPlus />
          {{ $t('settings.users.add') }}
        </button>
        <button class="secondary close-button" @click="newUserFormActive = false">
          <CircleX />
          {{ $t('settings.close') }}
        </button>
      </div>
    </div>
  </div>

  <div class="user-form-overlay" :class="{ active: editUserFormActive }" @click="editUserFormClickOutside">
    <div class="user-form">
      <h2>
        <UserPlus />
        {{ $t('settings.title.users.edit', { name: editUser.name }) }}
      </h2>
      <div class="input-container">
        <label for="edit_user_email">{{ $t('settings.users.email') }}</label>
        <input
          type="email"
          v-model="editUser.email"
          id="edit_user_email"
          :placeholder="$t('settings.users.email')"
          required
          :class="{ error: errors.email }"
        />
        <div class="error-message" v-if="errors.email">
          {{ errors.email }}
        </div>
      </div>
      <div class="input-container">
        <label for="edit_user_name">{{ $t('settings.users.name') }}</label>
        <input
          type="text"
          v-model="editUser.name"
          id="edit_user_name"
          :placeholder="$t('settings.users.name')"
          required
          :class="{ error: errors.name }"
        />
        <div class="error-message" v-if="errors.name">
          {{ errors.name }}
        </div>
      </div>

      <div class="checkbox-container">
        <input type="checkbox" v-model="editUser.admin" id="edit_user_admin" />
        <label for="edit_user_admin">{{ $t('settings.users.admin') }}</label>
        <p class="help-text">
          {{ $t('settings.users.admin_help_text') }}
          <br />
          {{ $t('settings.users.admin_help_text_2') }}
        </p>
      </div>

      <div class="button-bar">
        <button @click="saveUser">
          <UserRoundCheck />
          {{ $t('settings.users.save_changes') }}
        </button>
        <button class="secondary close-button" @click="editUserFormActive = false">
          <CircleX />
          {{ $t('settings.close') }}
        </button>
      </div>
    </div>
  </div>

  <div class="user-form-overlay" :class="{ active: resetPasswordFormActive }" @click="resetPasswordFormClickOutside">
    <div class="user-form">
      <h2>
        <KeyRound />
        {{ $t('settings.users.force_reset_password') }}
      </h2>
      <p v-if="resetPasswordUser">
        {{ $t('settings.users.confirm_force_reset_password', { name: resetPasswordUser.name }) }}
      </p>
      <div class="button-bar">
        <button @click="confirmForceResetPassword">
          <KeyRound />
          {{ $t('settings.users.force_reset_password') }}
        </button>
        <button class="secondary close-button" @click="resetPasswordFormActive = false; resetPasswordUser = null">
          <CircleX />
          {{ $t('settings.close') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.users-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.users-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.user-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.user-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 14px;
  padding: 10px 12px;
  background: var(--ot-field);
  border: 1px solid var(--ot-line-soft);
  border-radius: var(--ot-radius);
}
.user-row-main {
  flex: 1 1 240px;
  min-width: 0;
}
.user-row-name {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  font-weight: 700;
  color: var(--ot-heading);
}
.user-row-meta {
  font-size: 0.75rem;
  color: var(--ot-muted);
  overflow-wrap: anywhere;
}
.user-row-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.user-row-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
}
</style>
