<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { pinLeaving } from '../lib/motion'
import { useTranslate, useTolgee } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { Trash2 } from 'lucide-vue-next'
import ShareRow from '../components/layout/ShareRow.vue'
import AppLink from '../components/layout/AppLink.vue'
import { getMyShares, pruneExpiredShares } from '../api'
import { useConfirmDialog } from '../composables/useConfirmDialog'
import { useLiveRefresh } from '../composables/useLiveRefresh'
import { niceFileSize } from '../utils'
import { shareState, monthSummary, groupByWeek } from '../lib/shares'
import { pruneProgress, sharesToDelete } from '../lib/pruneProgress'

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
    // The states are read against the time of the data: a share just expired shows as expired
    now.value = new Date()
  } catch (error) {
    toast.error(t.value('shares.load_failed'))
  } finally {
    loaded.value = true
  }
}
onMounted(load)

// Downloads and expiries change without this page: it stays current while it is open
const { now } = useLiveRefresh(load)
const stateOf = (share) => shareState(share, now.value)

const FILTERS = {
  all: () => true,
  active: (share) => ['active', 'ending', 'pending'].includes(stateOf(share)),
  expired: (share) => ['expired', 'deleted', 'failed'].includes(stateOf(share))
}
const filtered = computed(() => shares.value.filter(FILTERS[filter.value]))
const groups = computed(() => groupByWeek(filtered.value, now.value))
const month = computed(() => monthSummary(shares.value, now.value))
const openedPercent = computed(() => (month.value.created === 0 ? 0 : Math.round((month.value.opened / month.value.created) * 100)))

const RING = 2 * Math.PI * 15

// --- Wording
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

const toggleDeleted = (event) => {
  showDeleted.value = event.target.checked
  localStorage.setItem('showDeletedShares', showDeleted.value)
  load()
}
const confirmDialog = useConfirmDialog()
const prune = async () => {
  const sure = await confirmDialog.show({
    title: t.value('dialog.title'),
    message: t.value('settings.confirm.pruneExpiredShares'),
    okText: t.value('settings.button.myShares.pruneExpired'),
    cancelText: t.value('dialog.cancel'),
    tone: 'danger'
  })
  if (!sure) return

  let returned
  try {
    returned = await pruneExpiredShares()
  } catch (error) {
    toast.error(t.value('settings.error.pruneExpiredShares'))
    return
  }
  const batch = sharesToDelete(returned)
  if (batch.length === 0) {
    toast.info(t.value('shares.nothing_to_delete'))
    return
  }
  deleting.value = { batch, done: 0, total: batch.length, over: false, slow: false, startedAt: Date.now() }
  followDeletion()
}

// The deletion runs in the background (a queued job): the page reads the list again every second
// and shows how far it got, instead of saying it is done before it is
const deleting = ref(null)
let followTimer = null
const followDeletion = async () => {
  if (!deleting.value) return
  await load()
  if (!deleting.value) return
  const progress = pruneProgress(deleting.value.batch, shares.value)
  deleting.value = { ...deleting.value, ...progress }
  if (progress.over) {
    toast.success(t.value('shares.deleting_done', { count: progress.total }))
    // The full bar stays a moment, then the panel goes
    followTimer = setTimeout(() => (deleting.value = null), 1400)
    return
  }
  // Two minutes on: the job is slow or the worker busy; the page keeps refreshing on its own
  if (Date.now() - deleting.value.startedAt > 120000) {
    deleting.value = { ...deleting.value, slow: true }
    return
  }
  followTimer = setTimeout(followDeletion, 1000)
}
onBeforeUnmount(() => clearTimeout(followTimer))
const isDeleting = (share) => Boolean(deleting.value && !deleting.value.over && deleting.value.batch.includes(share.id) && share.status !== 'deleted')
// Never an empty bar: it moves from the start, then follows the shares done
const deletingFraction = computed(() => (deleting.value ? Math.max(0.06, deleting.value.done / deleting.value.total) : 0))
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
        <button type="button" class="ot-act" @click="prune" :disabled="Boolean(deleting && !deleting.over)">
          <Trash2 aria-hidden="true" />
          {{ t('settings.button.myShares.pruneExpired') }}
        </button>
      </div>

      <Transition name="ot-pop">
        <div class="ot-card prune-progress" :class="{ 'is-over': deleting.over }" v-if="deleting" role="status" aria-live="polite">
          <div class="prune-progress-head">
            <span class="prune-progress-title">
              {{ deleting.over ? t('shares.deleting_done', { count: deleting.total }) : t('shares.deleting_title') }}
            </span>
            <span class="prune-progress-count">{{ t('shares.deleting_count', { done: deleting.done, total: deleting.total }) }}</span>
          </div>
          <div
            class="prune-progress-track"
            role="progressbar"
            :aria-label="t('shares.deleting_title')"
            aria-valuemin="0"
            :aria-valuemax="deleting.total"
            :aria-valuenow="deleting.done"
          >
            <div class="prune-progress-bar" :style="{ transform: `scaleX(${deletingFraction})` }"></div>
          </div>
          <p class="prune-progress-slow" v-if="deleting.slow">{{ t('shares.deleting_slow') }}</p>
        </div>
      </Transition>

      <!-- The rows arrive one after the other, leave sideways, and the others close up on a spring -->
      <TransitionGroup name="ot-list" @before-leave="pinLeaving" tag="div" class="share-groups ot-list-host">
        <div class="share-group" v-for="(group, groupIndex) in groups" :key="group.key" :style="{ '--ot-i': groupIndex }">
          <div class="share-group-head">
            <h2>{{ groupTitle(group) }}</h2>
            <span class="ot-pill">{{ groupSummary(group) }}</span>
          </div>

          <TransitionGroup name="ot-list" @before-leave="pinLeaving" tag="div" class="share-group-rows ot-list-host">
            <ShareRow
              v-for="(share, index) in group.shares"
              :key="share.id"
              :share="share"
              :now="now"
              :deleting="isDeleting(share)"
              :style="{ '--ot-i': index }"
              @changed="load"
            />
          </TransitionGroup>
        </div>
      </TransitionGroup>

      <div class="ot-card share-empty" v-if="loaded && groups.length === 0">
        <p>{{ filter === 'all' ? t('shares.empty') : t('shares.empty_filter') }}</p>
        <AppLink to="/" class="button-link" v-if="filter === 'all'">{{ t('nav.new_share') }}</AppLink>
      </div>
    </section>
  </div>
</template>
