<script setup>
import { ref, onMounted, computed } from 'vue'
import { ExternalLink } from 'lucide-vue-next'
import { domData } from '../../domData'
import { getSystemInfo } from '../../api'
import { store } from '../../store'
import { versionUrl, REPOSITORY } from '../../lib/version'
import { niceFileSize } from '../../utils'
import OtSkeleton from '../layout/OtSkeleton.vue'

const version = ref('unknown')
const systemInfo = ref(null)

// The disk the shares live on: what the shares take, what the rest of the disk holds (the system,
// other data), and what is free. The shares alone used to be written over a bar of the whole disk.
const storageBar = computed(() => {
  const storage = systemInfo.value?.storage
  if (!storage?.disk_total_bytes) return null
  const share = (bytes) => Math.max(0, Math.min(100, (bytes / storage.disk_total_bytes) * 100))
  // A few megabytes on a large disk would not show: a sliver at least
  const shares = storage.used_bytes > 0 ? Math.max(share(storage.used_bytes), 0.6) : 0
  return { shares, others: Math.max(0, share(storage.disk_used_bytes) - share(storage.used_bytes)) }
})
const loading = ref(true)

onMounted(async () => {
  const data = domData()
  version.value = data.version || 'unknown'
  
  // Only fetch system info for admins
  if (store.isAdmin()) {
    try {
      systemInfo.value = await getSystemInfo()
    } catch (error) {
      console.error('Failed to load system info:', error)
    } finally {
      loading.value = false
    }
  } else {
    loading.value = false
  }
})

// The version links to its release, or to its commit for a build of main between two releases
const versionLink = computed(() => versionUrl(version.value))

const databaseDisplayName = computed(() => {
  if (!systemInfo.value) return ''
  const driver = systemInfo.value.database_driver
  const names = {
    sqlite: 'SQLite',
    mysql: 'MySQL',
    pgsql: 'PostgreSQL',
    sqlsrv: 'SQL Server'
  }
  return names[driver] || driver
})
</script>

<template>
  <div class="about-layout">
    <section class="ot-card about-card" aria-labelledby="h-about">
      <h2 class="ot-card-title" id="h-about">{{ $t('settings.about.title') }}</h2>
      <p class="about-intro">{{ $t('settings.about.intro') }}</p>
      <p class="about-fork">{{ $t('settings.about.fork') }}</p>
      <p class="about-credit">
        {{ $t('settings.about.created_by') }}
        <a href="https://github.com/JuzoSuzuya20" target="_blank" rel="noopener noreferrer">JuzoSuzuya20</a>
      </p>

      <dl class="month-figures">
        <div>
          <dt>{{ $t('settings.about.version') }}</dt>
          <dd>
            <a v-if="versionLink" :href="versionLink" target="_blank" rel="noopener noreferrer">{{ version }}</a>
            <template v-else>{{ version }}</template>
          </dd>
        </div>
        <div>
          <dt>{{ $t('settings.about.license') }}</dt>
          <dd>
            <a :href="`${REPOSITORY}/blob/main/LICENSE`" target="_blank" rel="noopener noreferrer">MIT</a>
          </dd>
        </div>
      </dl>

      <div class="about-links">
        <a class="ot-act" :href="REPOSITORY" target="_blank" rel="noopener noreferrer">
          <ExternalLink aria-hidden="true" />
          {{ $t('settings.about.visit_github') }}
        </a>
        <a class="ot-act" :href="`${REPOSITORY}#readme`" target="_blank" rel="noopener noreferrer">
          <ExternalLink aria-hidden="true" />
          {{ $t('settings.about.documentation') }}
        </a>
        <a class="ot-act" :href="`${REPOSITORY}/blob/main/CHANGELOG.md`" target="_blank" rel="noopener noreferrer">
          <ExternalLink aria-hidden="true" />
          {{ $t('settings.about.changelog') }}
        </a>
        <a class="ot-act" :href="`${REPOSITORY}/issues`" target="_blank" rel="noopener noreferrer">
          <ExternalLink aria-hidden="true" />
          {{ $t('settings.about.report_issue') }}
        </a>
      </div>

      <div class="about-sub">
        <h3>{{ $t('settings.about.built_with') }}</h3>
        <ul class="about-credits">
          <li>
            <a href="https://laravel.com" target="_blank" rel="noopener noreferrer">Laravel</a>
            <span>{{ $t('settings.about.credits.laravel') }}</span>
          </li>
          <li>
            <a href="https://vuejs.org" target="_blank" rel="noopener noreferrer">Vue.js</a>
            <span>{{ $t('settings.about.credits.vue') }}</span>
          </li>
          <li>
            <a href="https://tus.io" target="_blank" rel="noopener noreferrer">tus</a>
            <span>{{ $t('settings.about.credits.tus') }}</span>
          </li>
        </ul>
      </div>
    </section>

    <section class="ot-card about-card" v-if="store.isAdmin()" aria-labelledby="h-system-info">
      <h2 class="ot-card-title" id="h-system-info">{{ $t('settings.about.system_info') }}</h2>
      <OtSkeleton v-if="loading" variant="lines" :count="5" />
      <template v-else-if="systemInfo">
        <dl class="month-figures">
          <div>
            <dt>PHP</dt>
            <dd>{{ systemInfo.php_version }}</dd>
          </div>
          <div>
            <dt>Laravel</dt>
            <dd>{{ systemInfo.laravel_version }}</dd>
          </div>
          <div>
            <dt>{{ $t('settings.about.database') }}</dt>
            <dd>{{ databaseDisplayName }} {{ systemInfo.database_version }}</dd>
          </div>
          <div>
            <dt>{{ $t('settings.about.max_share') }}</dt>
            <dd>{{ systemInfo.max_share_size?.bytes ? niceFileSize(systemInfo.max_share_size.bytes) : $t('settings.about.no_limit') }}</dd>
          </div>
          <div>
            <dt>{{ $t('settings.about.storage') }}</dt>
            <dd>
              {{
                $t('settings.about.storage_detail', {
                  shares: niceFileSize(systemInfo.storage.used_bytes),
                  used: niceFileSize(systemInfo.storage.disk_used_bytes),
                  total: niceFileSize(systemInfo.storage.disk_total_bytes)
                })
              }}
            </dd>
          </div>
        </dl>
        <template v-if="storageBar">
          <div
            class="about-meter"
            role="meter"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="systemInfo.storage.disk_usage_percent"
            :aria-valuetext="$t('settings.about.storage_detail', { shares: niceFileSize(systemInfo.storage.used_bytes), used: niceFileSize(systemInfo.storage.disk_used_bytes), total: niceFileSize(systemInfo.storage.disk_total_bytes) })"
            :aria-label="$t('settings.about.storage')"
          >
            <span class="is-shares" :style="{ width: `${storageBar.shares}%` }"></span>
            <span class="is-others" :style="{ width: `${storageBar.others}%` }"></span>
          </div>
          <ul class="about-meter-legend">
            <li><i class="is-shares" aria-hidden="true"></i>{{ $t('settings.about.legend_shares') }}</li>
            <li><i class="is-others" aria-hidden="true"></i>{{ $t('settings.about.legend_others') }}</li>
            <li><i aria-hidden="true"></i>{{ $t('settings.about.legend_free', { free: niceFileSize(systemInfo.storage.disk_free_bytes) }) }}</li>
          </ul>
        </template>
      </template>
    </section>
  </div>
</template>

<style scoped lang="scss">
.about-layout {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 20px;
}
.about-card {
  flex: 1 1 340px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;

  &:first-child {
    flex: 1.6 1 480px;
  }
  p {
    margin: 0;
    max-width: 70ch;
  }
}
.about-intro {
  color: var(--ot-text);
}
.about-fork,
.about-credit,
.about-links {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.about-sub {
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
.about-credits {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;

  li {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
    font-size: 0.875rem;
  }
  a {
    min-width: 72px;
    font-weight: 700;
  }
  span {
    color: var(--ot-muted);
  }
}
.about-meter {
  display: flex;
  height: 8px;
  border-radius: 4px;
  background: var(--ot-field);
  overflow: hidden;

  span {
    display: block;
    height: 100%;
    transform-origin: left;
    animation: ot-grow-x 700ms var(--ot-ease-out) both;
  }
}
// The shares, then what the rest of the disk holds; what is left is free
.is-shares {
  background: var(--ot-accent);
}
.is-others {
  background: var(--ot-line-strong);
}
.about-meter-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  margin: 8px 0 0;
  padding: 0;
  list-style: none;
  font-size: 0.8125rem;
  color: var(--ot-muted);

  li {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  i {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    background: var(--ot-field);
    box-shadow: inset 0 0 0 1px var(--ot-line);
  }
  i.is-shares {
    background: var(--ot-accent);
    box-shadow: none;
  }
  i.is-others {
    background: var(--ot-line-strong);
    box-shadow: none;
  }
}
</style>
