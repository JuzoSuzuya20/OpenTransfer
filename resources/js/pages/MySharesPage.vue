<script setup>
import { ref, computed, onMounted } from 'vue'
import { useTranslate, useTolgee } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { SquareArrowOutUpRight, HardDriveDownload, CalendarPlus, CalendarX2, Gauge, Copy, Check, Trash2 } from 'lucide-vue-next'
import RowMenu from '../components/layout/RowMenu.vue'
import AppLink from '../components/layout/AppLink.vue'
import { getMyShares, expireShare, extendShare, setDownloadLimit, pruneExpiredShares } from '../api'
import { niceFileSize } from '../utils'
import { shareState, monthSummary, groupByWeek } from '../lib/shares'

const { t } = useTranslate()
const tolgee = useTolgee(['language'])
const toast = useToast()

const shares = ref([])
const loaded = ref(false)
const filter = ref('all')
const showDeleted = ref(localStorage.getItem('showDeletedShares') === 'true')

const load = async () => {
  try {
    shares.value = await getMyShares(showDeleted.value)
  } catch (error) {
    toast.error(t.value('shares.load_failed'))
  } finally {
    loaded.value = true
  }
}
onMounted(load)

const now = ref(new Date())
const stateOf = (share) => shareState(share, now.value)

const FILTERS = {
  all: () => true,
  active: (share) => ['active', 'ending'].includes(stateOf(share)),
  expired: (share) => ['expired', 'deleted', 'failed'].includes(stateOf(share))
}
const filtered = computed(() => shares.value.filter(FILTERS[filter.value]))
const groups = computed(() => groupByWeek(filtered.value, now.value))
const month = computed(() => monthSummary(shares.value, now.value))
const openedPercent = computed(() => (month.value.created === 0 ? 0 : Math.round((month.value.opened / month.value.created) * 100)))

const RING = 2 * Math.PI * 15

// --- Wording
const dateFormat = computed(() => new Intl.DateTimeFormat(tolgee.value.getLanguage(), { day: 'numeric', month: 'short' }))
const monthFormat = computed(() => new Intl.DateTimeFormat(tolgee.value.getLanguage(), { month: 'long', year: 'numeric' }))

const groupTitle = (group) => {
  if (group.kind === 'this-week') return t.value('shares.this_week')
  if (group.kind === 'last-week') return t.value('shares.last_week')
  const label = monthFormat.value.format(group.month)
  return label.charAt(0).toUpperCase() + label.slice(1)
}
const groupSummary = (group) => {
  const downloads = group.shares.reduce((total, share) => total + share.download_count, 0)
  return t.value('shares.group_summary', { count: group.shares.length, downloads })
}

const STATE_PILL = { active: 'is-success', ending: 'is-warning', expired: 'is-danger', failed: 'is-danger', deleted: '' }
const stateLabel = (share) => t.value(`shares.state.${stateOf(share)}`)

const shareMeta = (share) => {
  const parts = [t.value('shares.items', { count: share.file_count }), niceFileSize(share.size)]
  if (share.shared_with_me) parts.push(t.value('shares.received'))
  if (share.password_protected) parts.push(t.value('shares.password'))
  if (share.download_limit) parts.push(t.value('shares.limit', { count: share.download_limit }))
  const date = dateFormat.value.format(new Date(share.expires_at))
  parts.push(new Date(share.expires_at) > now.value ? t.value('shares.expires_on', { date }) : t.value('shares.expired_on', { date }))
  return parts.join(' · ')
}
const downloadsLabel = (share) => (share.download_limit ? `${share.download_count}/${share.download_limit}` : share.download_count)

// --- Actions
const shareUrl = (share) => `${window.location.origin}/shares/${share.long_id}`
const copied = ref(null)
const copyLink = async (share) => {
  try {
    await navigator.clipboard.writeText(shareUrl(share))
    copied.value = share.id
    setTimeout(() => (copied.value = copied.value === share.id ? null : copied.value), 1500)
  } catch (error) {
    toast.error(t.value('shares.copy_failed'))
  }
}

const editingLimit = ref(null)
const limitValue = ref('')
const LIMITS = [1, 3, 5, 10, 25, 50, 100]
const startLimit = (share) => {
  editingLimit.value = share.id
  limitValue.value = share.download_limit ? String(share.download_limit) : ''
}
const saveLimit = async (share) => {
  try {
    await setDownloadLimit(share.id, limitValue.value ? Number(limitValue.value) : -1)
    toast.success(t.value('shares.limit_saved'))
    editingLimit.value = null
    load()
  } catch (error) {
    toast.error(t.value('shares.limit_failed'))
  }
}

const run = async (promise, success, failure) => {
  try {
    await promise
    toast.success(t.value(success))
    load()
  } catch (error) {
    toast.error(t.value(failure))
  }
}

const menuItems = (share) => {
  const state = stateOf(share)
  const online = state === 'active' || state === 'ending'
  return [
    { label: t.value('shares.open_page'), icon: SquareArrowOutUpRight, action: () => window.open(shareUrl(share), '_blank', 'noopener') },
    { label: t.value('shares.download'), icon: HardDriveDownload, disabled: !online, action: () => (window.location.href = `/api/shares/${share.long_id}/download`) },
    { label: t.value('shares.extend'), icon: CalendarPlus, disabled: state === 'deleted', action: () => run(extendShare(share.id), 'settings.success.shareExtended', 'settings.error.shareExtended') },
    { label: t.value('shares.set_limit'), icon: Gauge, disabled: state === 'deleted', action: () => startLimit(share) },
    { label: t.value('shares.expire_now'), icon: CalendarX2, danger: true, disabled: !online, action: () => run(expireShare(share.id), 'settings.success.shareExpired', 'settings.error.shareExpired') }
  ]
}

const toggleDeleted = (event) => {
  showDeleted.value = event.target.checked
  localStorage.setItem('showDeletedShares', showDeleted.value)
  load()
}
const prune = async () => {
  if (!confirm(t.value('settings.confirm.pruneExpiredShares'))) return
  run(pruneExpiredShares(), 'settings.success.pruneExpiredShares', 'settings.error.pruneExpiredShares')
}
</script>

<template>
  <div class="page my-shares">
    <aside class="ot-card month-card" aria-labelledby="h-month">
      <h2 class="ot-card-title" id="h-month">{{ t('shares.this_month') }}</h2>
      <div class="month-ring">
        <svg width="84" height="84" viewBox="0 0 36 36" role="img" :aria-label="t('shares.opened_ring', { percent: openedPercent })">
          <circle cx="18" cy="18" r="15" class="ring-track" />
          <circle
            cx="18"
            cy="18"
            r="15"
            class="ring-fill"
            :stroke-dasharray="`${(openedPercent / 100) * RING} ${RING}`"
            transform="rotate(-90 18 18)"
          />
          <text x="18" y="21" text-anchor="middle">{{ openedPercent }}%</text>
        </svg>
        <div>
          <div class="month-ring-title">{{ t('shares.opened_title') }}</div>
          <div class="month-ring-text">{{ t('shares.opened_text', { opened: month.opened, count: month.created }) }}</div>
        </div>
      </div>
      <dl class="month-figures">
        <div>
          <dt>{{ t('send.stats.downloads') }}</dt>
          <dd class="is-accent">{{ month.downloads }}</dd>
        </div>
        <div>
          <dt>{{ t('shares.sent_volume') }}</dt>
          <dd class="is-accent">{{ niceFileSize(month.bytes) }}</dd>
        </div>
        <div>
          <dt>{{ t('shares.received_files') }}</dt>
          <dd>{{ month.receivedFiles }}</dd>
        </div>
      </dl>
    </aside>

    <section class="my-shares-list" aria-labelledby="h-list">
      <div class="list-head">
        <h1 class="ot-page-title" id="h-list">{{ t('nav.my_shares') }}</h1>
        <div class="ot-segmented" role="tablist" :aria-label="t('shares.filter')">
          <button
            v-for="key in ['all', 'active', 'expired']"
            :key="key"
            role="tab"
            type="button"
            :aria-selected="filter === key"
            @click="filter = key"
          >
            {{ t(`shares.filter_${key}`) }}
          </button>
        </div>
      </div>

      <div class="list-tools">
        <div class="checkbox-container inline">
          <input type="checkbox" id="show_deleted_shares" :checked="showDeleted" @change="toggleDeleted" />
          <label for="show_deleted_shares">{{ t('settings.system.show_deleted_shares') }}</label>
        </div>
        <button type="button" class="ot-act" @click="prune">
          <Trash2 aria-hidden="true" />
          {{ t('settings.button.myShares.pruneExpired') }}
        </button>
      </div>

      <div class="share-group" v-for="group in groups" :key="group.key">
        <div class="share-group-head">
          <h2>{{ groupTitle(group) }}</h2>
          <span class="ot-pill">{{ groupSummary(group) }}</span>
        </div>

        <article v-for="share in group.shares" :key="share.id" class="share-row" :class="`is-${stateOf(share)}`">
          <div class="share-row-main">
            <h3 class="share-row-name">{{ share.name }}</h3>
            <div class="share-row-meta">{{ shareMeta(share) }}</div>
          </div>
          <span class="ot-pill" :class="STATE_PILL[stateOf(share)]">{{ stateLabel(share) }}</span>
          <div class="share-row-downloads">
            <div class="share-row-count">{{ downloadsLabel(share) }}</div>
            <div class="share-row-count-label">{{ t('shares.downloads_label', { count: share.download_count }) }}</div>
          </div>
          <div class="share-row-actions">
            <button type="button" class="ot-act" @click="copyLink(share)" :disabled="stateOf(share) === 'deleted'">
              <Check v-if="copied === share.id" aria-hidden="true" />
              <Copy v-else aria-hidden="true" />
              {{ copied === share.id ? t('send.copied') : t('send.copy_link') }}
            </button>
            <RowMenu :label="t('shares.more_actions', { name: share.name })" :items="menuItems(share)" />
          </div>

          <form class="share-row-limit" v-if="editingLimit === share.id" @submit.prevent="saveLimit(share)">
            <label :for="`limit-${share.id}`">{{ t('send.downloads') }}</label>
            <select :id="`limit-${share.id}`" v-model="limitValue">
              <option value="">{{ t('send.unlimited') }}</option>
              <option v-for="limit in LIMITS" :key="limit" :value="String(limit)">{{ limit }}</option>
            </select>
            <button type="submit">{{ t('admin.save') }}</button>
            <button type="button" class="ghost" @click="editingLimit = null">{{ t('send.close') }}</button>
          </form>
        </article>
      </div>

      <div class="ot-card share-empty" v-if="loaded && groups.length === 0">
        <p>{{ filter === 'all' ? t('shares.empty') : t('shares.empty_filter') }}</p>
        <AppLink to="/" class="button-link" v-if="filter === 'all'">{{ t('nav.new_share') }}</AppLink>
      </div>
    </section>
  </div>
</template>
