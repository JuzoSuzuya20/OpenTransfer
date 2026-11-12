<script setup>
// One share in a list (My shares, All shares): name, figures, state, downloads and its actions.
import { ref, computed } from 'vue'
import { useTranslate, useTolgee } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { SquareArrowOutUpRight, HardDriveDownload, CalendarPlus, CalendarX2, Gauge, Copy, Check } from 'lucide-vue-next'
import RowMenu from './RowMenu.vue'
import UserAvatar from './UserAvatar.vue'
import OtSelect from './OtSelect.vue'
import { expireShare, extendShare, setDownloadLimit } from '../../api'
import { niceFileSize } from '../../utils'
import { shareState } from '../../lib/shares'

const props = defineProps({
  share: { type: Object, required: true },
  now: { type: Date, required: true },
  // All shares: who owns it (user_name, user_avatar_seed)
  showOwner: { type: Boolean, default: false }
})
const emit = defineEmits(['changed'])

const { t } = useTranslate()
const tolgee = useTolgee(['language'])
const toast = useToast()

const state = computed(() => shareState(props.share, props.now))
const STATE_PILL = { active: 'is-success', pending: '', ending: 'is-warning', expired: 'is-danger', failed: 'is-danger', deleted: '' }
const online = computed(() => ['active', 'ending', 'pending'].includes(state.value))

const dateFormat = computed(() => new Intl.DateTimeFormat(tolgee.value.getLanguage(), { day: 'numeric', month: 'short' }))
const meta = computed(() => {
  const share = props.share
  const parts = [t.value('shares.items', { count: share.file_count }), niceFileSize(share.size)]
  if (share.shared_with_me) parts.push(t.value('shares.received'))
  if (share.password_protected) parts.push(t.value('shares.password'))
  if (share.download_limit) parts.push(t.value('shares.limit', { count: share.download_limit }))
  const date = dateFormat.value.format(new Date(share.expires_at))
  parts.push(new Date(share.expires_at) > props.now ? t.value('shares.expires_on', { date }) : t.value('shares.expired_on', { date }))
  return parts.join(' · ')
})
const downloads = computed(() =>
  props.share.download_limit ? `${props.share.download_count}/${props.share.download_limit}` : props.share.download_count
)

const shareUrl = () => `${window.location.origin}/shares/${props.share.long_id}`
const copied = ref(false)
const copyLink = async () => {
  try {
    await navigator.clipboard.writeText(shareUrl())
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch (error) {
    toast.error(t.value('shares.copy_failed'))
  }
}

const run = async (promise, success, failure) => {
  try {
    await promise
    toast.success(t.value(success))
    emit('changed')
  } catch (error) {
    toast.error(error?.code === 'max_expiry_reached' ? t.value('shares.max_expiry_reached', { days: error.data?.max_expiry_time }) : t.value(failure))
  }
}

const editingLimit = ref(false)
const limitValue = ref('')
const LIMITS = [1, 3, 5, 10, 25, 50, 100]
const limitOptions = computed(() => [
  { value: '', label: t.value('send.unlimited') },
  ...LIMITS.map((limit) => ({ value: String(limit), label: String(limit) }))
])
const startLimit = () => {
  editingLimit.value = true
  limitValue.value = props.share.download_limit ? String(props.share.download_limit) : ''
}
const saveLimit = async () => {
  try {
    await setDownloadLimit(props.share.id, limitValue.value ? Number(limitValue.value) : -1)
    toast.success(t.value('shares.limit_saved'))
    editingLimit.value = false
    emit('changed')
  } catch (error) {
    toast.error(t.value('shares.limit_failed'))
  }
}

const menuItems = computed(() => [
  { label: t.value('shares.open_page'), icon: SquareArrowOutUpRight, action: () => window.open(shareUrl(), '_blank', 'noopener') },
  {
    label: t.value('shares.download'),
    icon: HardDriveDownload,
    disabled: !online.value,
    action: () => (window.location.href = `/api/shares/${props.share.long_id}/download`)
  },
  {
    label: t.value('shares.extend'),
    icon: CalendarPlus,
    disabled: state.value === 'deleted',
    action: () => run(extendShare(props.share.id), 'settings.success.shareExtended', 'settings.error.shareExtended')
  },
  { label: t.value('shares.set_limit'), icon: Gauge, disabled: state.value === 'deleted', action: startLimit },
  {
    label: t.value('shares.expire_now'),
    icon: CalendarX2,
    danger: true,
    disabled: !online.value,
    action: () => run(expireShare(props.share.id), 'settings.success.shareExpired', 'settings.error.shareExpired')
  }
])
</script>

<template>
  <article class="share-row" :class="`is-${state}`">
    <div class="share-row-main">
      <h3 class="share-row-name">{{ share.name }}</h3>
      <div class="share-row-meta">
        <span class="share-row-owner" v-if="showOwner">
          <UserAvatar :seed="share.user_avatar_seed ?? ''" :name="share.user_name" :size="18" />
          {{ share.user_name }}
        </span>
        {{ meta }}
      </div>
    </div>
    <span class="ot-pill" :class="STATE_PILL[state]">{{ t(`shares.state.${state}`) }}</span>
    <div class="share-row-downloads">
      <div class="share-row-count">{{ downloads }}</div>
      <div class="share-row-count-label">{{ t('shares.downloads_label', { count: share.download_count }) }}</div>
    </div>
    <div class="share-row-actions">
      <button type="button" class="ot-act" @click="copyLink" :disabled="state === 'deleted'">
        <Check v-if="copied" class="ot-check-pop" aria-hidden="true" />
        <Copy v-else aria-hidden="true" />
        {{ copied ? t('send.copied') : t('send.copy_link') }}
      </button>
      <RowMenu :label="t('shares.more_actions', { name: share.name })" :items="menuItems" />
    </div>

    <form class="share-row-limit" v-if="editingLimit" @submit.prevent="saveLimit">
      <label :for="`limit-${share.id}`">{{ t('send.downloads') }}</label>
      <OtSelect :id="`limit-${share.id}`" v-model="limitValue" :options="limitOptions" />
      <button type="submit">{{ t('admin.save') }}</button>
      <button type="button" class="ghost" @click="editingLimit = false">{{ t('send.close') }}</button>
    </form>
  </article>
</template>
