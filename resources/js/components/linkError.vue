<script setup>
import { computed } from 'vue'
import { useTranslate, useTolgee } from '@tolgee/vue'
import { CheckCheck, Clock, Link2Off } from 'lucide-vue-next'
import UserAvatar from './layout/UserAvatar.vue'
import { store } from '../store'

// The page a link lands on when it no longer opens: a file request already used, past its time,
// or gone (cancelled by the member, cut short when copied). It says why, and whom to ask for
// another, to someone who has no account here and nothing to sign in to.
const { t } = useTranslate()
const tolgee = useTolgee(['language'])

const REASONS = {
  request_used: { icon: CheckCheck, key: 'used' },
  request_expired: { icon: Clock, key: 'expired' },
  request_unknown: { icon: Link2Off, key: 'unknown' }
}

const error = computed(() => store.linkError ?? {})
const reason = computed(() => REASONS[error.value.reason] ?? REASONS.request_unknown)
const asker = computed(() => error.value.from || t.value('link_error.someone'))
const until = computed(() =>
  error.value.expires_at
    ? new Intl.DateTimeFormat(tolgee.value.getLanguage(), { day: 'numeric', month: 'long' }).format(new Date(error.value.expires_at))
    : ''
)
const text = computed(() => {
  if (reason.value.key === 'expired' && until.value) {
    return t.value('link_error.expired_text_until', { date: until.value, name: asker.value })
  }
  return t.value(`link_error.${reason.value.key}_text`, { name: asker.value })
})
</script>

<template>
  <div class="download">
    <div class="download-head" v-if="error.from">
      <div class="download-sender">
        <UserAvatar :seed="error.avatar_seed ?? ''" :name="error.from" :size="28" />
        {{ $t('send.request_from', { name: error.from }) }}
      </div>
    </div>

    <section class="ot-card download-state link-error" role="alert" aria-labelledby="h-link-error">
      <component :is="reason.icon" aria-hidden="true" />
      <h1 id="h-link-error">{{ $t(`link_error.${reason.key}_title`) }}</h1>
      <p>{{ text }}</p>
    </section>
  </div>
</template>

<style lang="scss">
.link-error p {
  max-width: 46ch;
  text-wrap: pretty;
}
</style>
