<script setup>
import { ref, computed, onMounted } from 'vue'
import { useTranslate, useTolgee } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { Send } from 'lucide-vue-next'
import PageHead from '../components/layout/PageHead.vue'
import AppLink from '../components/layout/AppLink.vue'
import { sendReverseShareInvite, getMyShares } from '../api'
import { niceFileSize } from '../utils'

const { t } = useTranslate()
const tolgee = useTolgee(['language'])
const toast = useToast()

// Ask someone outside the organisation to drop files; what they send lands in "My shares"
const invite = ref({ email: '', name: '', message: '' })
const sending = ref(false)
const sendInvite = async () => {
  sending.value = true
  try {
    await sendReverseShareInvite(invite.value.email, invite.value.name, invite.value.message)
    toast.success(t.value('reverse_invite_send.success'))
    invite.value = { email: '', name: '', message: '' }
  } catch (error) {
    console.error(error)
    toast.error(t.value('reverse_invite_send.error'))
  } finally {
    sending.value = false
  }
}

const received = ref([])
const loaded = ref(false)
onMounted(async () => {
  try {
    received.value = (await getMyShares()).filter((share) => share.shared_with_me)
  } catch (error) {
    console.error('Failed to load the shares', error)
  } finally {
    loaded.value = true
  }
})
const dateFormat = computed(() => new Intl.DateTimeFormat(tolgee.value.getLanguage(), { day: 'numeric', month: 'short' }))
</script>

<template>
  <div class="page requests">
    <PageHead :title="t('nav.file_requests')" :description="t('requests.description')" />

    <div class="requests-layout">
      <section class="ot-card requests-form" aria-labelledby="h-request">
        <h2 class="ot-card-title" id="h-request">{{ t('requests.new') }}</h2>
        <form @submit.prevent="sendInvite">
          <div class="input-container">
            <label for="invite-email">{{ t('settings.users.email') }}</label>
            <input type="email" id="invite-email" v-model="invite.email" required autocomplete="email" />
          </div>
          <div class="input-container">
            <label for="invite-name">{{ t('settings.users.name') }}</label>
            <input type="text" id="invite-name" v-model="invite.name" autocomplete="name" />
          </div>
          <div class="input-container">
            <label for="invite-message">{{ t('invite.labels.message') }}</label>
            <textarea id="invite-message" v-model="invite.message" rows="3" />
          </div>
          <button type="submit" class="block cta" :disabled="sending || !invite.email">
            <Send aria-hidden="true" />
            {{ t('button.reverse_share_invite_send') }}
          </button>
        </form>
      </section>

      <section class="ot-card requests-received" aria-labelledby="h-received">
        <h2 class="ot-card-title" id="h-received">{{ t('requests.received') }}</h2>
        <ul class="received-list" v-if="received.length > 0">
          <li v-for="share in received" :key="share.id" class="received-row">
            <div class="received-name">{{ share.name }}</div>
            <div class="received-meta">
              {{ t('shares.items', { count: share.file_count }) }} · {{ niceFileSize(share.size) }} ·
              {{ dateFormat.format(new Date(share.created_at)) }}
            </div>
          </li>
        </ul>
        <p class="received-empty" v-else-if="loaded">{{ t('requests.none') }}</p>
        <AppLink to="/my-shares" class="received-link" v-if="received.length > 0">{{ t('requests.see_all') }}</AppLink>
      </section>
    </div>
  </div>
</template>
