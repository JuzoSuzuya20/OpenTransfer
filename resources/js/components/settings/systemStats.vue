<script setup>
// The instance at a glance, for an admin: four headline figures, then downloads over time beside
// storage, file types, shares and users. Every figure comes from the server: nothing is estimated.
import { ref, onMounted, computed, watch } from 'vue'
import { useTranslate, useTolgee } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { getSystemStats } from '../../api'
import UserAvatar from '../layout/UserAvatar.vue'

const { t } = useTranslate()
const tolgee = useTolgee(['language'])
const toast = useToast()

const stats = ref(null)
const loading = ref(true)
const selectedDays = ref(30)
const PERIODS = [7, 14, 30, 60, 90]

const loadStats = async () => {
  try {
    loading.value = true
    stats.value = await getSystemStats(selectedDays.value)
  } catch (error) {
    toast.error(t.value('settings.stats.error_loading'))
  } finally {
    loading.value = false
  }
}
onMounted(loadStats)
watch(selectedDays, loadStats)

const refreshStats = () => loadStats()
defineExpose({ refreshStats })
defineEmits(['navItemClicked'])

// --- Downloads by day: one series, one hue, no legend; the table holds every value
const language = computed(() => tolgee.value.getLanguage())
const shortDay = computed(() => new Intl.DateTimeFormat(language.value, { day: 'numeric', month: 'short' }))
const longDay = computed(() => new Intl.DateTimeFormat(language.value, { weekday: 'long', day: 'numeric', month: 'long' }))
const days = computed(() =>
  Object.entries(stats.value?.downloads?.by_day ?? {}).map(([date, count]) => ({ date: new Date(`${date}T12:00:00`), count }))
)
const maxCount = computed(() => Math.max(1, ...days.value.map((day) => day.count)))
const busiest = computed(() => Math.max(0, ...days.value.map((day) => day.count)))
// About six dates under the axis, whatever the period
const labelEvery = computed(() => Math.max(1, Math.ceil(days.value.length / 6)))
const hovered = ref(null)
const barHeight = (count) => `${(count / maxCount.value) * 100}%`

// --- Files by category: magnitudes of one kind, one hue, largest first
const totalFiles = computed(() => Object.values(stats.value?.file_types?.by_category ?? {}).reduce((sum, c) => sum + c.count, 0))
const categories = computed(() =>
  Object.entries(stats.value?.file_types?.by_category ?? {})
    .map(([key, data]) => ({ key, ...data }))
    .filter((category) => category.count > 0)
    .sort((a, b) => b.count - a.count)
)
const percentOf = (part, whole) => (whole ? Math.round((part / whole) * 100) : 0)

const diskTone = computed(() => {
  const used = stats.value?.storage?.disk_usage_percent ?? 0
  return used > 95 ? 'is-danger' : used > 80 ? 'is-warning' : ''
})
</script>

<template>
  <p class="stats-loading" v-if="loading && !stats">{{ $t('settings.stats.loading') }}</p>

  <div class="stats" v-if="stats" :class="{ 'is-loading': loading }">
    <!-- The four headline figures in one band: aligned on top, no card left with a hole -->
    <section class="ot-card stats-band" :aria-label="$t('settings.title.stats')">
      <div class="stats-band-item">
        <h2>{{ $t('settings.stats.nav.storage') }}</h2>
        <span class="ot-figure is-accent">{{ stats.storage.used_formatted }}</span>
        <span class="ot-stat-sub">
          {{ $t('settings.stats.figures.storage_sub', { percent: stats.storage.shares_usage_percent, free: stats.storage.disk_free_formatted }) }}
        </span>
      </div>
      <div class="stats-band-item">
        <h2>{{ $t('settings.stats.shares.active_shares') }}</h2>
        <span class="ot-figure is-success">{{ stats.shares.active }}</span>
        <span class="ot-stat-sub">{{ $t('settings.stats.figures.shares_sub', { expired: stats.shares.expired, total: stats.shares.total }) }}</span>
      </div>
      <div class="stats-band-item">
        <h2>{{ $t('settings.stats.nav.downloads') }}</h2>
        <span class="ot-figure is-accent">{{ stats.downloads.total_in_period }}</span>
        <span class="ot-stat-sub">
          {{ $t('settings.stats.figures.downloads_sub', { days: selectedDays, unique: stats.downloads.unique_downloaders }) }}
        </span>
      </div>
      <div class="stats-band-item">
        <h2>{{ $t('settings.stats.nav.users') }}</h2>
        <span class="ot-figure">{{ stats.users.total }}</span>
        <span class="ot-stat-sub">{{ $t('settings.stats.figures.users_sub', { admins: stats.users.admins, guests: stats.users.guests }) }}</span>
      </div>
    </section>

    <div class="stats-columns">
      <div class="stats-column is-wide">
        <section class="ot-card stats-card" id="downloads" aria-labelledby="h-downloads">
          <div class="stats-card-head">
            <h2 class="ot-card-title" id="h-downloads">{{ $t('settings.stats.downloads.title') }}</h2>
            <div class="ot-segmented" role="tablist" :aria-label="$t('settings.stats.downloads.time_period')">
              <button
                v-for="period in PERIODS"
                :key="period"
                type="button"
                role="tab"
                :aria-selected="selectedDays === period"
                @click="selectedDays = period"
              >
                {{ $t('settings.stats.downloads.days_option', { days: period }) }}
              </button>
            </div>
          </div>

          <div class="downloads-chart">
            <div
              class="chart-plot"
              role="img"
              :aria-label="$t('settings.stats.figures.chart_summary', { total: stats.downloads.total_in_period, days: selectedDays, max: busiest })"
              @pointerleave="hovered = null"
            >
              <span class="chart-max" aria-hidden="true">{{ maxCount }}</span>
              <div
                v-for="(day, index) in days"
                :key="index"
                class="chart-slot"
                :class="{ 'is-hovered': hovered === index }"
                @pointerenter="hovered = index"
              >
                <div class="chart-bar" v-if="day.count > 0" :style="{ height: barHeight(day.count), '--ot-i': index }"></div>
              </div>
              <div class="chart-tip" v-if="hovered !== null && days[hovered]" :style="{ left: `${((hovered + 0.5) / days.length) * 100}%` }" aria-hidden="true">
                <strong>{{ days[hovered].count }}</strong>
                <span>{{ longDay.format(days[hovered].date) }}</span>
              </div>
            </div>
            <div class="chart-axis" aria-hidden="true">
              <span v-for="(day, index) in days" :key="index">{{ index % labelEvery === 0 ? shortDay.format(day.date) : '' }}</span>
            </div>
          </div>

          <details class="chart-table">
            <summary>{{ $t('settings.stats.figures.table') }}</summary>
            <table>
              <thead>
                <tr>
                  <th scope="col">{{ $t('settings.stats.figures.day') }}</th>
                  <th scope="col">{{ $t('settings.stats.nav.downloads') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(day, index) in days" :key="index">
                  <td>{{ longDay.format(day.date) }}</td>
                  <td>{{ day.count }}</td>
                </tr>
              </tbody>
            </table>
          </details>

          <dl class="month-figures">
            <div>
              <dt>{{ $t('settings.stats.downloads.all_time') }}</dt>
              <dd>{{ stats.downloads.all_time }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.downloads.unique_downloaders') }}</dt>
              <dd>{{ stats.downloads.unique_downloaders }}</dd>
            </div>
          </dl>

          <div class="stats-sub" v-if="stats.downloads.top_shares?.length">
            <h3>{{ $t('settings.stats.downloads.top_shares') }}</h3>
            <ol class="stats-ranking">
              <li v-for="share in stats.downloads.top_shares" :key="share.id">
                <span class="stats-ranking-name">{{ share.name || share.long_id }}</span>
                <span class="stats-ranking-value">{{ share.download_count }}</span>
              </li>
            </ol>
          </div>
        </section>

        <section class="ot-card stats-card" id="shares" aria-labelledby="h-shares">
          <h2 class="ot-card-title" id="h-shares">{{ $t('settings.stats.shares.title') }}</h2>
          <dl class="month-figures is-two">
            <div>
              <dt>{{ $t('settings.stats.shares.active_shares') }}</dt>
              <dd class="is-success">{{ stats.shares.active }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.shares.total_shares') }}</dt>
              <dd>{{ stats.shares.total }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.shares.expired') }}</dt>
              <dd>{{ stats.shares.expired }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.shares.last_7_days') }}</dt>
              <dd>{{ stats.shares.recent_7_days }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.shares.deleted') }}</dt>
              <dd>{{ stats.shares.deleted }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.shares.total_files') }}</dt>
              <dd>{{ stats.shares.total_files }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.shares.protected') }}</dt>
              <dd>{{ stats.shares.password_protected }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.figures.files_per_share') }}</dt>
              <dd>{{ stats.shares.avg_files_per_share }}</dd>
            </div>
          </dl>
        </section>
      </div>

      <div class="stats-column">
        <section class="ot-card stats-card" id="storage" aria-labelledby="h-storage">
          <h2 class="ot-card-title" id="h-storage">{{ $t('settings.stats.storage.disk_usage') }}</h2>
          <div class="ot-stat">
            <div class="ot-stat-value">
              <span class="ot-figure" :class="diskTone">{{ stats.storage.disk_usage_percent }} %</span>
              <span class="ot-stat-sub">{{ $t('settings.stats.storage.of') }} {{ stats.storage.disk_total_formatted }}</span>
            </div>
          </div>
          <div
            class="stats-meter"
            role="meter"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="stats.storage.disk_usage_percent"
            :aria-label="$t('settings.stats.storage.disk_usage')"
          >
            <span :class="diskTone" :style="{ width: `${Math.min(100, stats.storage.disk_usage_percent)}%` }"></span>
          </div>
          <dl class="month-figures">
            <div>
              <dt>{{ $t('settings.stats.storage.share_storage') }}</dt>
              <dd class="is-accent">{{ stats.storage.used_formatted }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.storage.available_space') }}</dt>
              <dd>{{ stats.storage.disk_free_formatted }}</dd>
            </div>
          </dl>
        </section>

        <section class="ot-card stats-card" id="file_types" aria-labelledby="h-file-types">
          <h2 class="ot-card-title" id="h-file-types">{{ $t('settings.stats.file_types.title') }}</h2>
          <ul class="stats-categories" v-if="categories.length">
            <li v-for="category in categories" :key="category.key">
              <div class="stats-category-line">
                <span class="stats-category-name">{{ $t(`settings.stats.file_types.categories.${category.key}`) }}</span>
                <span class="stats-category-value">{{ category.count }}</span>
              </div>
              <div class="stats-meter is-thin" aria-hidden="true">
                <span :style="{ width: `${Math.max(2, percentOf(category.count, totalFiles))}%` }"></span>
              </div>
              <div class="stats-category-sub">
                {{ $t('settings.stats.figures.share_of_files', { percent: percentOf(category.count, totalFiles) }) }} · {{ category.total_size_formatted }}
              </div>
            </li>
          </ul>
        </section>

        <section class="ot-card stats-card" id="users" aria-labelledby="h-users">
          <h2 class="ot-card-title" id="h-users">{{ $t('settings.stats.users.title') }}</h2>
          <dl class="month-figures">
            <div>
              <dt>{{ $t('settings.stats.users.active') }}</dt>
              <dd>{{ stats.users.active }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.users.admins') }}</dt>
              <dd>{{ stats.users.admins }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.users.guests') }}</dt>
              <dd>{{ stats.users.guests }}</dd>
            </div>
            <div>
              <dt>{{ $t('settings.stats.users.with_shares') }}</dt>
              <dd>{{ stats.users.with_shares }}</dd>
            </div>
          </dl>
          <div class="stats-sub" v-if="stats.users.top_users?.length">
            <h3>{{ $t('settings.stats.users.top_users') }}</h3>
            <ol class="stats-ranking">
              <li v-for="user in stats.users.top_users" :key="user.id">
                <UserAvatar :seed="user.avatar_seed ?? ''" :name="user.name" :size="20" />
                <span class="stats-ranking-name">{{ user.name }}</span>
                <span class="stats-ranking-value">{{ user.shares_count }}</span>
              </li>
            </ol>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.stats-band {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  padding: 0;

  @media (max-width: 900px) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.stats-band-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  padding: 18px 20px 21px;

  & + & {
    border-left: 1px solid var(--ot-line-soft);
  }
  h2 {
    margin: 0 0 2px;
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--ot-text);
  }
  .ot-stat-sub {
    font-size: 0.8125rem;
    color: var(--ot-muted);
  }

  @media (max-width: 900px) {
    &:nth-child(3) {
      border-left: 0;
    }
    &:nth-child(n + 3) {
      border-top: 1px solid var(--ot-line-soft);
    }
  }
}

.stats {
  display: flex;
  flex-direction: column;
  gap: 20px;
  transition: opacity var(--ot-dur-fast) var(--ot-ease-out);

  &.is-loading {
    opacity: 0.6;
  }
}
.stats-loading {
  color: var(--ot-muted);
}
.stats-columns {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 20px;
}
.stats-column {
  flex: 1 1 320px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;

  &.is-wide {
    flex: 2 1 560px;
  }
}
.stats-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.stats-card-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;

  // Five periods do not fit a phone: they scroll rather than leave the card
  .ot-segmented {
    max-width: 100%;
    overflow-x: auto;
  }
}
.ot-figure.is-warning {
  color: var(--ot-warning);
}
.ot-figure.is-danger {
  color: var(--ot-danger);
}
.month-figures.is-two {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 10px 32px;
}
.month-figures dd.is-success {
  color: var(--ot-success);
}

// --- The chart: thin bars from one baseline, 4 px rounded data-ends, 2 px of surface between
.downloads-chart {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.chart-plot {
  position: relative;
  display: flex;
  align-items: flex-end;
  height: 180px;
  padding-top: 16px;
  border-top: 1px solid var(--ot-line-soft);
  border-bottom: 1px solid var(--ot-line);
}
.chart-max {
  position: absolute;
  top: 2px;
  left: 0;
  font-size: 0.6875rem;
  font-variant-numeric: tabular-nums;
  color: var(--ot-muted);
}
.chart-slot {
  flex: 1;
  height: 100%;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding: 0 1px;
}
// The bars rise from the baseline once, one after the other; a new period reshapes them
.chart-bar {
  width: 100%;
  max-width: 24px;
  min-height: 2px;
  border-radius: 4px 4px 0 0;
  background: var(--ot-accent);
  transform-origin: bottom;
  animation: ot-grow-y 560ms var(--ot-ease-out) both;
  animation-delay: calc(min(var(--ot-i, 0), 40) * 8ms);
  transition:
    height var(--ot-dur-slow) var(--ot-ease-out),
    background-color var(--ot-dur-fast) var(--ot-ease-out);
}
.chart-slot.is-hovered .chart-bar {
  background: var(--ot-accent-hover);
}
.chart-tip {
  position: absolute;
  top: 0;
  transform: translate(-50%, -100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 6px 10px;
  white-space: nowrap;
  pointer-events: none;
  background: var(--ot-raised);
  border: 1px solid var(--ot-line);
  border-radius: var(--ot-radius-sm);
  box-shadow: var(--ot-shadow);

  strong {
    font-size: 0.9375rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    color: var(--ot-heading);
  }
  span {
    font-size: 0.75rem;
    color: var(--ot-muted);
  }
}
.chart-axis {
  display: flex;

  span {
    flex: 1;
    min-width: 0;
    overflow: visible;
    white-space: nowrap;
    font-size: 0.6875rem;
    color: var(--ot-muted);
  }
}
.chart-table {
  font-size: 0.8125rem;

  summary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    list-style: none;
    color: var(--ot-accent-text);
    font-weight: 600;

    &::-webkit-details-marker {
      display: none;
    }
    // A chevron that turns on a spring as the table opens
    &::before {
      content: "";
      width: 6px;
      height: 6px;
      border: solid currentColor;
      border-width: 0 2px 2px 0;
      transform: rotate(-45deg);
      transition: transform var(--ot-dur-slow) var(--ot-ease-spring);
    }
  }
  &[open] summary::before {
    transform: rotate(45deg);
  }
  table {
    margin-top: 8px;
    max-height: 260px;
  }
  &[open] table {
    animation: ot-rise-in var(--ot-dur-slow) var(--ot-ease-out) both;
  }
  td:last-child,
  th:last-child {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }
}

// --- Meters: a thin rounded track, the accent for a share, a status colour for a full disk
.stats-meter {
  height: 8px;
  border-radius: 4px;
  background: var(--ot-field);
  overflow: hidden;

  &.is-thin {
    height: 6px;
  }
  span {
    display: block;
    height: 100%;
    border-radius: 4px;
    background: var(--ot-accent);
    transform-origin: left;
    animation: ot-grow-x 700ms var(--ot-ease-out) both;
    transition: width var(--ot-dur-slow) var(--ot-ease-out);

    &.is-warning {
      background: var(--ot-warning);
    }
    &.is-danger {
      background: var(--ot-danger);
    }
  }
}
.stats-categories {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.stats-category-line {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 4px;
  font-size: 0.875rem;
}
.stats-category-name {
  color: var(--ot-text);
}
.stats-category-value {
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: var(--ot-heading);
}
.stats-category-sub {
  margin-top: 4px;
  font-size: 0.75rem;
  color: var(--ot-muted);
}

// --- Rankings: the five shares most downloaded, the five people who share most
.stats-sub {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-top: 14px;
  border-top: 1px solid var(--ot-line-soft);

  h3 {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--ot-heading);
  }
}
.stats-ranking {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
  counter-reset: rank;

  li {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    background: var(--ot-field);
    border: 1px solid var(--ot-line-soft);
    border-radius: var(--ot-radius-sm);
    font-size: 0.875rem;
    counter-increment: rank;

    &::before {
      content: counter(rank);
      min-width: 1.2em;
      font-weight: 800;
      font-variant-numeric: tabular-nums;
      color: var(--ot-muted);
    }
  }
}
.stats-ranking-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--ot-text);
}
.stats-ranking-value {
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: var(--ot-heading);
}
</style>
