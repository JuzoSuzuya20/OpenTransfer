<script setup>
// File requests (piste B): ask in one line, then follow what is waiting and what came back.
// Both lists stay current while the page is open.
import { ref, computed, onMounted, nextTick } from 'vue'
import { useTranslate, useTolgee } from '@tolgee/vue'
import { useToast } from '../toasts'
import { Send, SquareArrowOutUpRight, Link2, Copy, Check } from 'lucide-vue-next'
import OtSelect from '../components/layout/OtSelect.vue'
import OtPassword from '../components/layout/OtPassword.vue'
import Expand from '../components/layout/Expand.vue'
import OtSkeleton from '../components/layout/OtSkeleton.vue'
import PageHead from '../components/layout/PageHead.vue'
import AppLink from '../components/layout/AppLink.vue'
import { pinLeaving } from '../lib/motion'
import { useLiveRefresh } from '../composables/useLiveRefresh'
import { sendReverseShareInvite, cancelReverseShareInvite, getMyShares, getMyReverseShareInvites } from '../api'
import { useConfirmDialog } from '../composables/useConfirmDialog'
import { niceFileSize } from '../utils'
import { domData } from '../domData'
import { keepFilesChoices } from '../lib/keepFilesDays'

const { t } = useTranslate()
const tolgee = useTolgee(['language'])
const toast = useToast()

// --- Asking: by e-mail, or a link the member passes on themselves
const VALIDITY_DAYS = [1, 3, 7, 14, 30]
const validityOptions = computed(() => VALIDITY_DAYS.map((days) => ({ value: days, label: t.value('requests.validity_days', { count: days }) })))
// The rules are the member's: the guest only drops files. How long the files received stay
// online (within the administration's limit) and a password the guest gives first.
const keepChoices = keepFilesChoices({ max: domData().max_expiry_time, fallback: domData().default_expiry_time })
const keepOptions = computed(() => keepChoices.days.map((days) => ({ value: days, label: t.value('requests.validity_days', { count: days }) })))
const blankInvite = (delivery = 'email') => ({ delivery, email: '', name: '', message: '', days: 7, keep: keepChoices.chosen, password: '' })
const invite = ref(blankInvite())
const byLink = computed(() => invite.value.delivery === 'link')
const canAsk = computed(() => Boolean(invite.value.name && (byLink.value || invite.value.email)))

// The link just made, shown once to be copied: it is not kept anywhere to show again
const createdLink = ref(null)
const copied = ref(false)
const showLink = (response, name, days, protectedByPassword = false) => {
  createdLink.value = { url: response.data.link, name, until: new Date(Date.now() + days * 24 * 3600 * 1000), protectedByPassword }
  copied.value = false
}

const sending = ref(false)
const sendInvite = async () => {
  sending.value = true
  const { email, name, message, days, delivery, keep, password } = invite.value
  try {
    const response = await sendReverseShareInvite(email, name, message, { delivery, days, password, keepFilesDays: keep })
    if (delivery === 'link') {
      showLink(response, name, days, Boolean(password))
      toast.success(t.value('requests.link_created'))
    } else {
      toast.success(t.value('reverse_invite_send.success'))
    }
    invite.value = blankInvite(delivery)
    load()
  } catch (error) {
    console.error(error)
    toast.error(t.value('reverse_invite_send.error'))
  } finally {
    sending.value = false
  }
}
const copyLink = async () => {
  try {
    await navigator.clipboard.writeText(createdLink.value.url)
    copied.value = true
    setTimeout(() => (copied.value = false), 1600)
  } catch (error) {
    // Refused by the browser: the field is selected, ready for Ctrl+C
    document.getElementById('request-link')?.select()
  }
}

// Waiting too long, or expired: the same request once more (a new link for a link), with its rules.
// Its password is kept hashed: a protected request comes back in the form, for the member to type
// the password again, rather than once more without one.
const passwordField = ref(null)
const askAgain = async (request) => {
  const keepFilesDays = request.keep_files_days ?? null
  if (request.password_protected) {
    invite.value = {
      ...blankInvite(request.delivery),
      email: request.recipient_email,
      name: request.recipient_name,
      message: request.message ?? '',
      keep: keepFilesDays ?? keepChoices.chosen
    }
    createdLink.value = null
    toast.info(t.value('requests.password_again'))
    await nextTick()
    passwordField.value?.focus()
    return
  }
  try {
    if (request.delivery === 'link') {
      const response = await sendReverseShareInvite(null, request.recipient_name, request.message ?? '', { delivery: 'link', keepFilesDays })
      showLink(response, request.recipient_name, 7)
      toast.success(t.value('requests.link_created'))
    } else {
      await sendReverseShareInvite(request.recipient_email, request.recipient_name, request.message ?? '', { keepFilesDays })
      toast.success(t.value('requests.resent', { name: request.recipient_name }))
    }
    load()
  } catch (error) {
    toast.error(t.value('reverse_invite_send.error'))
  }
}

// Taken back: the link stops working at once (a guest who opened it can no longer drop), and an
// expired one leaves the list
const confirmDialog = useConfirmDialog()
const cancelling = ref(null)
const cancelRequest = async (request) => {
  const expired = request.state === 'expired'
  const sure = await confirmDialog.show({
    title: t.value(expired ? 'requests.remove_title' : 'requests.cancel_title'),
    message: t.value(expired ? 'requests.remove_message' : 'requests.cancel_message', { name: request.recipient_name }),
    okText: t.value(expired ? 'requests.remove' : 'requests.cancel'),
    cancelText: t.value('requests.keep'),
    tone: 'danger'
  })
  if (!sure) return
  cancelling.value = request.id
  try {
    await cancelReverseShareInvite(request.id)
    invites.value = invites.value.filter((item) => item.id !== request.id)
    toast.success(t.value(expired ? 'requests.removed' : 'requests.cancelled', { name: request.recipient_name }))
  } catch (error) {
    toast.error(t.value(error.code === 'request_received' ? 'requests.cancel_received' : 'requests.cancel_failed'))
    load()
  } finally {
    cancelling.value = null
  }
}

// --- Following
const invites = ref([])
const received = ref([])
const loaded = ref(false)
const load = async () => {
  try {
    const [sent, shares] = await Promise.all([getMyReverseShareInvites(), getMyShares()])
    invites.value = sent
    received.value = shares.filter((share) => share.shared_with_me)
  } catch (error) {
    console.error('Failed to load the requests', error)
  } finally {
    loaded.value = true
  }
}
onMounted(load)
useLiveRefresh(load)

// A request that came back is one of the received shares: "waiting" keeps the others
const waiting = computed(() => invites.value.filter((request) => request.state !== 'received'))
const receivedBytes = computed(() => received.value.reduce((total, share) => total + share.size, 0))

const dateFormat = computed(() => new Intl.DateTimeFormat(tolgee.value.getLanguage(), { day: 'numeric', month: 'short' }))
const day = (value) => dateFormat.value.format(new Date(value))
// A share received in the last two days is marked new
const isNew = (share) => Date.now() - new Date(share.created_at).getTime() < 2 * 24 * 3600 * 1000
</script>

<template>
  <div class="page requests">
    <PageHead :title="t('nav.file_requests')" :description="t('requests.description')" />

    <form class="ot-card requests-ask" aria-labelledby="h-request" @submit.prevent="sendInvite">
      <div class="requests-ask-head">
        <h2 class="ot-card-title" id="h-request">{{ t('requests.title_new') }}</h2>
        <div class="ot-segmented" role="tablist" :aria-label="t('requests.mode_label')">
          <button
            v-for="mode in ['email', 'link']"
            :key="mode"
            type="button"
            role="tab"
            :aria-selected="invite.delivery === mode"
            @click="invite.delivery = mode"
          >
            {{ t(`requests.by_${mode}`) }}
          </button>
        </div>
      </div>
      <div class="requests-ask-row" :class="{ 'is-link': byLink }">
        <div class="input-container" v-if="!byLink">
          <label for="invite-email">{{ t('settings.users.email') }}</label>
          <input type="email" id="invite-email" v-model="invite.email" required autocomplete="email" />
        </div>
        <div class="input-container">
          <label for="invite-name">{{ byLink ? t('requests.for_whom') : t('settings.users.name') }}</label>
          <input
            type="text"
            id="invite-name"
            v-model="invite.name"
            required
            :autocomplete="byLink ? 'off' : 'name'"
            :placeholder="byLink ? t('requests.for_whom_placeholder') : null"
          />
        </div>
        <div class="input-container requests-ask-message">
          <label for="invite-message">
            {{ t('invite.labels.message') }}
            <small>({{ t('requests.optional') }})</small>
          </label>
          <input type="text" id="invite-message" v-model="invite.message" :placeholder="t('requests.message_placeholder')" />
        </div>
      </div>
      <!-- The rules of the request: the guest only drops files -->
      <div class="requests-ask-row is-rules">
        <div class="input-container">
          <label for="invite-validity">{{ t('requests.validity') }}</label>
          <OtSelect id="invite-validity" v-model="invite.days" :options="validityOptions" />
        </div>
        <div class="input-container">
          <label for="invite-keep">{{ t('requests.keep_files') }}</label>
          <OtSelect id="invite-keep" v-model="invite.keep" :options="keepOptions" />
        </div>
        <div class="input-container requests-ask-password">
          <label for="invite-password">
            {{ t('send.password') }}
            <small>({{ t('requests.optional') }})</small>
          </label>
          <OtPassword id="invite-password" ref="passwordField" v-model="invite.password" autocomplete="new-password" />
        </div>
        <button type="submit" :disabled="sending || !canAsk">
          <component :is="byLink ? Link2 : Send" aria-hidden="true" />
          {{ byLink ? t('requests.create_link') : t('button.reverse_share_invite_send') }}
        </button>
      </div>

      <Expand :show="createdLink">
        <div class="requests-link-ready" role="status">
          <label for="request-link">{{ t('requests.link_ready', { name: createdLink.name }) }}</label>
          <div class="requests-link-field">
            <input type="text" id="request-link" :value="createdLink.url" readonly @focus="$event.target.select()" />
            <button type="button" class="ot-act" @click="copyLink">
              <component :is="copied ? Check : Copy" aria-hidden="true" />
              {{ copied ? t('requests.copied') : t('requests.copy_link') }}
            </button>
          </div>
          <p class="help-text">{{ t('requests.link_help', { date: day(createdLink.until) }) }}</p>
          <p class="help-text" v-if="createdLink.protectedByPassword">{{ t('requests.password_apart') }}</p>
        </div>
      </Expand>
    </form>

    <div class="requests-columns">
      <section class="ot-card requests-waiting" aria-labelledby="h-waiting">
        <div class="requests-card-head">
          <h2 class="ot-card-title" id="h-waiting">{{ t('requests.waiting') }}</h2>
          <span class="ot-pill" v-if="waiting.length">{{ waiting.length }}</span>
        </div>
        <TransitionGroup name="ot-list" @before-leave="pinLeaving" tag="ul" class="requests-list ot-list-host" v-if="waiting.length">
          <li v-for="(request, index) in waiting" :key="request.id" class="requests-item" :style="{ '--ot-i': index }">
            <div class="requests-item-main">
              <strong>{{ request.recipient_name }}</strong>
              <span>
                <template v-if="request.delivery === 'link'">{{ t('requests.via_link') }} · </template>
                {{ t('requests.sent_on', { date: day(request.created_at) }) }} ·
                <span v-if="request.state === 'expired'" class="is-expired">{{ t('requests.expired_on', { date: day(request.expires_at) }) }}</span>
                <template v-else>{{ t('requests.valid_until', { date: day(request.expires_at) }) }}</template>
              </span>
            </div>
            <div class="requests-item-actions">
              <button type="button" class="ot-act" @click="askAgain(request)">
                {{ request.delivery === 'link' ? t('requests.new_link') : request.state === 'expired' ? t('requests.ask_again') : t('requests.resend') }}
              </button>
              <button type="button" class="ot-act is-danger" :disabled="cancelling === request.id" @click="cancelRequest(request)">
                {{ request.state === 'expired' ? t('requests.remove') : t('requests.cancel') }}
              </button>
            </div>
          </li>
        </TransitionGroup>
        <p class="requests-empty" v-else-if="loaded">{{ t('requests.no_waiting') }}</p>
        <OtSkeleton v-else variant="rows" :count="2" />
      </section>

      <section class="ot-card requests-received" aria-labelledby="h-received">
        <div class="requests-card-head">
          <h2 class="ot-card-title" id="h-received">{{ t('requests.received') }}</h2>
          <span class="requests-total" v-if="received.length">
            {{ t('shares.items', { count: received.length }) }} · {{ niceFileSize(receivedBytes) }}
          </span>
        </div>
        <TransitionGroup name="ot-list" @before-leave="pinLeaving" tag="ul" class="requests-list ot-list-host" v-if="received.length">
          <li v-for="(share, index) in received" :key="share.id" class="requests-item" :style="{ '--ot-i': index }">
            <div class="requests-item-main">
              <strong>{{ share.name }}</strong>
              <span>
                {{ t('requests.from_name', { name: share.user?.name }) }} · {{ t('shares.items', { count: share.file_count }) }} ·
                {{ niceFileSize(share.size) }} · {{ day(share.created_at) }}
              </span>
            </div>
            <span class="ot-pill is-success" v-if="isNew(share)">{{ t('requests.new_badge') }}</span>
            <a class="ot-act" :href="`/shares/${share.long_id}`" target="_blank" rel="noopener">
              <SquareArrowOutUpRight aria-hidden="true" />
              {{ t('requests.open') }}
            </a>
          </li>
        </TransitionGroup>
        <p class="requests-empty" v-else-if="loaded">{{ t('requests.none') }}</p>
        <OtSkeleton v-else variant="rows" :count="2" />
        <AppLink to="/my-shares" class="requests-link" v-if="received.length">{{ t('requests.see_all') }}</AppLink>
      </section>
    </div>
  </div>
</template>

<style scoped lang="scss">
.requests-ask {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.requests-ask-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px 16px;
}
// Asking fits on two lines on a wide screen: who and what (address, name, message), then the
// rules (how long the link opens, how long the files stay, a password) and the button
.requests-ask-row {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, 1.6fr);
  gap: 12px;
  align-items: end;

  // By link there is no address
  &.is-link {
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.8fr);
  }
  &.is-rules {
    grid-template-columns: 9rem 11rem minmax(0, 1fr) auto;
  }
  // The field of OtPassword is in that component: only :deep() reaches it from these scoped styles,
  // else it keeps the 10px under every field and stands that much above its line
  .ot-password :deep(input) {
    margin-bottom: 0;
  }
  .ot-select {
    margin-bottom: 0;
  }

  .input-container input {
    margin-bottom: 0;
  }
  button {
    margin: 0;
    height: var(--button-height);
  }
  label small {
    font-weight: 400;
    color: var(--ot-muted);
  }

  @media (max-width: 900px) {
    &,
    &.is-link,
    &.is-rules {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .requests-ask-message,
    .requests-ask-password {
      grid-column: 1 / -1;
    }
    button {
      grid-column: 1 / -1;
    }
  }
  @media (max-width: 560px) {
    &,
    &.is-link,
    &.is-rules {
      grid-template-columns: minmax(0, 1fr);
    }
  }
}

// The link just made: shown once, to copy and pass on
.requests-link-ready {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 12px 14px;
  border: 1px solid color-mix(in srgb, var(--ot-accent) 40%, transparent);
  border-radius: var(--ot-radius);
  background: var(--ot-accent-soft);

  label {
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--ot-heading);
  }
  .help-text {
    margin: 0;
  }
}
.requests-link-field {
  display: flex;
  gap: 8px;

  input {
    flex: 1;
    min-width: 0;
    margin: 0;
    font-family: ui-monospace, monospace;
    font-size: 0.8125rem;
  }
  button {
    flex: 0 0 auto;
    margin: 0;
  }
}

.requests-columns {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  align-items: flex-start;

  > section {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .requests-waiting {
    flex: 1 1 380px;
  }
  .requests-received {
    flex: 1.4 1 480px;
  }
}
.requests-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.requests-total {
  font-size: 0.8125rem;
  color: var(--ot-muted);
}
.requests-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.requests-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: var(--ot-radius);
  background: var(--ot-field);

  .ot-act {
    flex-shrink: 0;
    margin: 0;
  }
}
.requests-item-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 6px;
}
.requests-item-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;

  strong {
    overflow: hidden;
    color: var(--ot-heading);
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  > span {
    font-size: 0.8125rem;
    color: var(--ot-muted);
  }
  .is-expired {
    color: var(--ot-danger-text);
  }
}
.requests-empty {
  margin: 0;
  color: var(--ot-muted);
  font-size: 0.875rem;
}
.requests-link {
  align-self: flex-start;
  font-size: 0.8125rem;
  font-weight: 700;
}
</style>
