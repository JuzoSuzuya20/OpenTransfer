<script setup>
// Every share of the instance, for an admin: the rows of My shares, with their owner.
// The page head (AdminPage) holds the owner filter and the "deleted shares" switch.
import { ref, computed, onMounted } from 'vue'
import { pinLeaving } from '../../lib/motion'
import { useLiveRefresh } from '../../composables/useLiveRefresh'
import { useTranslate, useTolgee } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import ShareRow from '../layout/ShareRow.vue'
import { getAllShares } from '../../api'
import { shareState, groupByWeek } from '../../lib/shares'

const { t } = useTranslate()
const tolgee = useTolgee(['language'])
const toast = useToast()

const shares = ref([])
const loaded = ref(false)
const showDeletedShares = ref(localStorage.getItem('allSharesShowDeleted') === 'true')
const selectedUserId = ref(null)
const filter = ref('all')

const loadShares = async () => {
  try {
    shares.value = await getAllShares(showDeletedShares.value, selectedUserId.value)
    now.value = new Date()
  } catch (error) {
    toast.error(t.value('shares.load_failed'))
  } finally {
    loaded.value = true
  }
}
onMounted(loadShares)
// Downloads and expiries change without this page: it stays current while it is open
const { now } = useLiveRefresh(loadShares)

const stateOf = (share) => shareState(share, now.value)
const FILTERS = {
  all: () => true,
  active: (share) => ['active', 'ending', 'pending'].includes(stateOf(share)),
  expired: (share) => ['expired', 'deleted', 'failed'].includes(stateOf(share))
}
const groups = computed(() => groupByWeek(shares.value.filter(FILTERS[filter.value]), now.value))

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

const setShowDeletedShares = (value) => {
  showDeletedShares.value = value
  localStorage.setItem('allSharesShowDeleted', value)
  loadShares()
}
const setUserFilter = (userId) => {
  selectedUserId.value = userId
  loadShares()
}

defineExpose({ setShowDeletedShares, loadShares, setUserFilter })
</script>

<template>
  <section class="all-shares" aria-labelledby="h-all-shares">
    <div class="list-head">
      <h2 class="visually-hidden-label" id="h-all-shares">{{ t('settings.title.allShares') }}</h2>
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

    <TransitionGroup name="ot-list" @before-leave="pinLeaving" tag="div" class="share-groups ot-list-host">
      <div class="share-group" v-for="(group, groupIndex) in groups" :key="group.key" :style="{ '--ot-i': groupIndex }">
        <div class="share-group-head">
          <h3>{{ groupTitle(group) }}</h3>
          <span class="ot-pill">{{ groupSummary(group) }}</span>
        </div>
        <TransitionGroup name="ot-list" @before-leave="pinLeaving" tag="div" class="share-group-rows ot-list-host">
          <ShareRow
            v-for="(share, index) in group.shares"
            :key="share.id"
            :share="share"
            :now="now"
            show-owner
            :style="{ '--ot-i': index }"
            @changed="loadShares"
          />
        </TransitionGroup>
      </div>
    </TransitionGroup>

    <div class="ot-card share-empty" v-if="loaded && groups.length === 0">
      <p>{{ shares.length === 0 ? t('settings.allShares.noShares') : t('shares.empty_filter') }}</p>
    </div>
  </section>
</template>

<style lang="scss" scoped>
.all-shares {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.list-head {
  display: flex;
  justify-content: flex-end;
}
</style>
