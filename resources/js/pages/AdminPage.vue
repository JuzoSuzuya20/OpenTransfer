<script setup>
import { ref, computed, watch, inject, onMounted } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { Save, UserPlus, Info, SlidersHorizontal, Share2, Bell, AtSign, Mail, Palette, KeyRound, UserRoundPlus, ShieldCheck, Users as UsersIcon, FolderOpen, Database } from 'lucide-vue-next'
import PageHead from '../components/layout/PageHead.vue'
import AppLink from '../components/layout/AppLink.vue'
import OtSelect from '../components/layout/OtSelect.vue'
import SystemSettings from '../components/settings/system.vue'
import SsoSettings from '../components/settings/SsoSettings.vue'
import BrandingSettings from '../components/settings/branding.vue'
import EmailTemplates from '../components/settings/emailTemplates.vue'
import Users from '../components/settings/users.vue'
import AllShares from '../components/settings/allShares.vue'
import { getUsers } from '../api'
import { useSetting } from '../composables/useSetting'

const props = defineProps({
  section: { type: String, required: true }
})

const { t } = useTranslate()
const navigate = inject('navigate')
const scrollToAnchor = inject('scrollToAnchor')
const currentHash = inject('currentHash')

const systemSettings = ref(null)
const ssoSettings = ref(null)
const brandingSettings = ref(null)
const emailTemplates = ref(null)
const usersPanel = ref(null)
const allSharesPanel = ref(null)

const { value: selfRegistrationEnabled } = useSetting('self_registration_enabled', 'system.auth', false)

// Piste A: a menu in three groups. The system settings show one section at a time (the anchor
// of the address says which); the other entries are pages of their own.
const SYSTEM_PARTS = ['general', 'shares', 'emails', 'smtp', 'auth', 'security', 'backups']
// Older anchors (command palette, e-mails, bookmarks) inside a section
const PART_OF_ANCHOR = { reverse_shares: 'shares', share_url_generation: 'shares', self_registration: 'auth', auth: 'auth' }
const systemPart = computed(() => {
  const anchor = decodeURIComponent((currentHash.value || '').slice(1))
  return SYSTEM_PARTS.includes(anchor) ? anchor : PART_OF_ANCHOR[anchor] ?? 'general'
})

const item = (id, icon, to, part = null) => ({
  id,
  icon,
  to,
  part,
  label: t.value(`admin.nav.${id}`),
  description: t.value(`admin.desc.${id}`)
})
const groups = computed(() => [
  {
    id: 'instance',
    label: t.value('admin.group.instance'),
    items: [
      item('general', SlidersHorizontal, '/admin/system#general', 'general'),
      item('shares', Share2, '/admin/system#shares', 'shares'),
      item('notifications', Bell, '/admin/system#emails', 'emails'),
      item('smtp', AtSign, '/admin/system#smtp', 'smtp'),
      item('templates', Mail, '/admin/email-templates'),
      item('customization', Palette, '/admin/branding')
    ]
  },
  {
    id: 'access',
    label: t.value('admin.group.access'),
    items: [
      item('sso', KeyRound, '/admin/sso'),
      item('signup', UserRoundPlus, '/admin/system#auth', 'auth'),
      item('security', ShieldCheck, '/admin/system#security', 'security'),
      item('users', UsersIcon, '/admin/users')
    ]
  },
  {
    id: 'data',
    label: t.value('admin.group.data'),
    items: [item('all_shares', FolderOpen, '/admin/all-shares'), item('backups', Database, '/admin/system#backups', 'backups')]
  }
])
const isActive = (entry) =>
  entry.part ? props.section === 'system' && systemPart.value === entry.part : entry.to === `/admin/${props.section}`
const current = computed(() => groups.value.flatMap((group) => group.items).find(isActive) ?? groups.value[0].items[0])

// The sections of a settings page link to an anchor of the page
const onSection = (anchor) => {
  scrollToAnchor(anchor)
  history.replaceState(history.state, '', `${location.pathname}#${anchor}`)
}

// All shares: who they belong to, and whether deleted ones show
const users = ref([])
const selectedUserId = ref(new URLSearchParams(location.search).get('user'))
const showDeleted = ref(localStorage.getItem('allSharesShowDeleted') === 'true')

const loadUsers = async () => {
  try {
    users.value = (await getUsers()).users || []
  } catch (error) {
    console.error('Failed to load users', error)
  }
}

const applyAllSharesFilters = () => {
  allSharesPanel.value?.setShowDeletedShares(showDeleted.value)
  allSharesPanel.value?.setUserFilter(selectedUserId.value || null)
}

const userOptions = computed(() => [
  { value: '', label: t.value('settings.allShares.allUsers') },
  ...users.value.map((user) => ({ value: user.id, label: `${user.name} (${user.email})` }))
])
const onUserFilter = (value) => {
  selectedUserId.value = value || null
  const query = selectedUserId.value ? `?user=${selectedUserId.value}` : ''
  history.replaceState(history.state, '', `${location.pathname}${query}`)
  allSharesPanel.value?.setUserFilter(selectedUserId.value)
}

const onShowDeleted = (event) => {
  showDeleted.value = event.target.checked
  localStorage.setItem('allSharesShowDeleted', showDeleted.value)
  allSharesPanel.value?.setShowDeletedShares(showDeleted.value)
}

const viewUserShares = (user) => navigate(`/admin/all-shares?user=${user.id}`)

watch(
  () => props.section,
  (section) => {
    if (section === 'all-shares') {
      selectedUserId.value = new URLSearchParams(location.search).get('user')
      if (users.value.length === 0) {
        loadUsers()
      }
      setTimeout(applyAllSharesFilters, 0)
    }
  },
  { immediate: true }
)

onMounted(() => {
  if (props.section === 'all-shares') {
    setTimeout(applyAllSharesFilters, 0)
  }
})
</script>

<template>
  <div class="page page-admin">
    <div class="admin-layout">
    <nav class="admin-side" :aria-label="t('nav.administration')">
      <div class="admin-side-group" v-for="group in groups" :key="group.id">
        <h2>{{ group.label }}</h2>
        <AppLink
          v-for="entry in group.items"
          :key="entry.id"
          :to="entry.to"
          class="admin-side-item"
          :class="{ active: isActive(entry) }"
          :aria-current="isActive(entry) ? 'page' : null"
        >
          <component :is="entry.icon" aria-hidden="true" />
          {{ entry.label }}
        </AppLink>
      </div>
    </nav>

    <div class="admin-main">
    <PageHead :title="current.label" :description="current.description">
      <template #actions>
        <button v-if="section === 'system' && systemPart !== 'backups'" @click="systemSettings?.saveSettings()">
          <Save aria-hidden="true" />
          {{ t('admin.save') }}
        </button>
        <button v-else-if="section === 'sso'" @click="ssoSettings?.save()">
          <Save aria-hidden="true" />
          {{ t('admin.save') }}
        </button>
        <button v-else-if="section === 'branding'" @click="brandingSettings?.saveSettings()">
          <Save aria-hidden="true" />
          {{ t('admin.save') }}
        </button>
        <button v-else-if="section === 'email-templates'" @click="emailTemplates?.saveEmailTemplates()">
          <Save aria-hidden="true" />
          {{ t('admin.save') }}
        </button>
        <template v-else-if="section === 'users'">
          <AppLink class="admin-tip" to="/admin/system#auth">
            <Info aria-hidden="true" />
            {{ selfRegistrationEnabled ? t('settings.users.self_registration_on') : t('settings.users.self_registration_off') }}
          </AppLink>
          <button @click="usersPanel?.addUser()">
            <UserPlus aria-hidden="true" />
            {{ t('settings.button.users.add') }}
          </button>
        </template>
        <template v-else-if="section === 'all-shares'">
          <div class="checkbox-container inline">
            <input type="checkbox" id="show_deleted_shares_all" :checked="showDeleted" @change="onShowDeleted" />
            <label for="show_deleted_shares_all">{{ t('settings.system.show_deleted_shares') }}</label>
          </div>
          <label class="visually-hidden-label" for="user-filter-all">{{ t('settings.allShares.allUsers') }}</label>
          <OtSelect
            id="user-filter-all"
            class="user-filter-select"
            :model-value="selectedUserId || ''"
            :options="userOptions"
            @change="onUserFilter"
          />
        </template>
      </template>
    </PageHead>




    <!-- From one section to the next as from one page to the next -->
    <Transition name="ot-page" mode="out-in">
      <SystemSettings v-if="section === 'system'" ref="systemSettings" :part="systemPart" />
      <SsoSettings v-else-if="section === 'sso'" ref="ssoSettings" />
      <BrandingSettings v-else-if="section === 'branding'" ref="brandingSettings" />
      <EmailTemplates v-else-if="section === 'email-templates'" ref="emailTemplates" @navItemClicked="onSection" />
      <Users v-else-if="section === 'users'" ref="usersPanel" @viewUserShares="viewUserShares" />
      <AllShares v-else-if="section === 'all-shares'" ref="allSharesPanel" />
    </Transition>
    </div>
    </div>
  </div>
</template>
