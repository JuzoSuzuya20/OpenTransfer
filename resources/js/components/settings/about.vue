<script setup>
import { ref, onMounted, computed } from 'vue'
import { ExternalLink } from 'lucide-vue-next'
import { domData } from '../../domData'
import { getSystemInfo } from '../../api'
import { store } from '../../store'

const version = ref('unknown')
const systemInfo = ref(null)
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

const erugoWebsiteUrl = computed(() => {
  const baseUrl = 'https://erugo.app'
  if (version.value && version.value !== 'unknown') {
    return `${baseUrl}?version=${encodeURIComponent(version.value)}`
  }
  return baseUrl
})

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
      <p class="about-credit">
        {{ $t('settings.about.created_by') }}
        <a href="https://github.com/deanward" target="_blank" rel="noopener noreferrer">Dean Ward</a>.
        {{ $t('settings.about.support_cta') }}
        <a href="https://ko-fi.com/deanward" target="_blank" rel="noopener noreferrer">{{ $t('settings.about.support_link') }}</a>.
      </p>

      <dl class="month-figures">
        <div>
          <dt>{{ $t('settings.about.version') }}</dt>
          <dd>{{ version }}</dd>
        </div>
        <div>
          <dt>{{ $t('settings.about.license') }}</dt>
          <dd>
            <a href="https://github.com/DeanWard/erugo/blob/main/LICENSE" target="_blank" rel="noopener noreferrer">MIT</a>
          </dd>
        </div>
      </dl>

      <div class="about-links">
        <a class="ot-act" :href="erugoWebsiteUrl" target="_blank" rel="noopener noreferrer">
          <ExternalLink aria-hidden="true" />
          {{ $t('settings.about.visit_website') }}
        </a>
        <a class="ot-act" href="https://github.com/DeanWard/erugo" target="_blank" rel="noopener noreferrer">
          <ExternalLink aria-hidden="true" />
          {{ $t('settings.about.visit_github') }}
        </a>
        <a class="ot-act" href="https://erugo.app/docs/" target="_blank" rel="noopener noreferrer">
          <ExternalLink aria-hidden="true" />
          {{ $t('settings.about.documentation') }}
        </a>
        <a class="ot-act" href="https://discord.gg/M74X2wmqY8" target="_blank" rel="noopener noreferrer">
          <ExternalLink aria-hidden="true" />
          {{ $t('settings.about.discord') }}
        </a>
        <a class="ot-act" href="https://ko-fi.com/deanward" target="_blank" rel="noopener noreferrer">
          <ExternalLink aria-hidden="true" />
          {{ $t('settings.about.donate') }}
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
      <p class="about-loading" v-if="loading">{{ $t('settings.about.loading') }}</p>
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
            <dt>{{ $t('settings.about.max_upload') }}</dt>
            <dd>{{ systemInfo.max_upload_size.formatted }}</dd>
          </div>
          <div>
            <dt>{{ $t('settings.about.storage') }}</dt>
            <dd>{{ systemInfo.storage.used_formatted }} {{ $t('settings.about.of') }} {{ systemInfo.storage.disk_total_formatted }}</dd>
          </div>
        </dl>
        <div
          class="about-meter"
          role="meter"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="systemInfo.storage.disk_usage_percent"
          :aria-label="$t('settings.about.storage')"
        >
          <span :style="{ width: `${Math.min(100, systemInfo.storage.disk_usage_percent)}%` }"></span>
        </div>
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
.about-credit,
.about-loading {
  font-size: 0.8125rem;
  color: var(--ot-muted);
}
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
  height: 8px;
  border-radius: 4px;
  background: var(--ot-field);
  overflow: hidden;

  span {
    display: block;
    height: 100%;
    border-radius: 4px;
    background: var(--ot-accent);
    transform-origin: left;
    animation: ot-grow-x 700ms var(--ot-ease-out) both;
  }
}
</style>
