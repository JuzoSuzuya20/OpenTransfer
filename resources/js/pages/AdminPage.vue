<script setup>
import { ref, computed, watch, inject, onMounted } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { Save, UserPlus, Info } from 'lucide-vue-next'
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

const systemSettings = ref(null)
const ssoSettings = ref(null)
const brandingSettings = ref(null)
const emailTemplates = ref(null)
const usersPanel = ref(null)
const allSharesPanel = ref(null)

const { value: selfRegistrationEnabled } = useSetting('self_registration_enabled', 'system.auth', false)

const sections = computed(() => [
  { id: 'system', label: t.value('settings.title.system'), description: t.value('settings.description.system') },
  { id: 'sso', label: t.value('sso.title'), description: t.value('sso.description') },
  { id: 'branding', label: t.value('settings.title.branding'), description: t.value('settings.description.branding') },
  {
    id: 'email-templates',
    label: t.value('settings.title.emailTemplates'),
    description: t.value('settings.description.emailTemplates')
  },
  { id: 'users', label: t.value('settings.title.users'), description: t.value('settings.description.users') },
  { id: 'all-shares', label: t.value('settings.title.allShares'), description: t.value('settings.description.allShares') }
])
const current = computed(() => sections.value.find((section) => section.id === props.section))

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
    <PageHead :title="t('nav.administration')">
      <template #actions>
        <button v-if="section === 'system'" @click="systemSettings?.saveSettings()">
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
          <AppLink class="admin-tip" to="/admin/system#self_registration">
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

    <nav class="sub-nav" :aria-label="t('nav.administration')">
      <AppLink
        v-for="item in sections"
        :key="item.id"
        :to="`/admin/${item.id}`"
        class="sub-nav-item"
        :class="{ active: section === item.id }"
        :aria-current="section === item.id ? 'page' : null"
      >
        {{ item.label }}
      </AppLink>
    </nav>

    <p class="page-description" v-if="current">{{ current.description }}</p>

    <!-- From one section to the next as from one page to the next -->
    <Transition name="ot-page" mode="out-in">
      <SystemSettings v-if="section === 'system'" ref="systemSettings" @navItemClicked="onSection" />
      <SsoSettings v-else-if="section === 'sso'" ref="ssoSettings" />
      <BrandingSettings v-else-if="section === 'branding'" ref="brandingSettings" />
      <EmailTemplates v-else-if="section === 'email-templates'" ref="emailTemplates" @navItemClicked="onSection" />
      <Users v-else-if="section === 'users'" ref="usersPanel" @viewUserShares="viewUserShares" />
      <AllShares v-else-if="section === 'all-shares'" ref="allSharesPanel" />
    </Transition>
  </div>
</template>
