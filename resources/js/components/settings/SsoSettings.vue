<script setup>
import OtSelect from '../layout/OtSelect.vue'
import { useConfirmDialog } from '../../composables/useConfirmDialog'
import { ref, computed, onMounted } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { useToast } from 'vue-toastification'
import { Plus, Eye, EyeOff, Copy, Check, Trash2, PlugZap, ChevronDown } from 'lucide-vue-next'
import {
  getSettingsByGroup,
  saveSettingsById,
  getAuthProviders,
  bulkUpdateAuthProviders,
  getAvailableProviderTypes,
  deleteAuthProvider,
  getCallbackUrl,
  testAuthProvider
} from '../../api'
import { mapSettings } from '../../utils'
import { parseSsoDomains } from '../../lib/ssoDomains'

const { t } = useTranslate()
const toast = useToast()

// --- Who must sign in through SSO
const requiredDomains = ref('')
const domainList = computed(() => parseSsoDomains(requiredDomains.value))

// --- Providers
const providers = ref([])
const types = ref([])
const newType = ref('')
const loaded = ref(false)
const shown = ref({})
const results = ref({})
const testing = ref({})
const copied = ref('')

const enabledProviders = computed(() => providers.value.filter((provider) => provider.enabled))
const lockoutRisk = computed(() => domainList.value.length > 0 && enabledProviders.value.length === 0)

const load = async () => {
  try {
    const settings = mapSettings(await getSettingsByGroup('system.auth'))
    requiredDomains.value = settings.sso_required_domains ?? ''
    providers.value = (await getAuthProviders()).map((provider) => ({ ...provider, editing: false }))
    types.value = await getAvailableProviderTypes()
  } catch (error) {
    console.error(error)
    toast.error(t.value('sso.load_failed'))
  } finally {
    loaded.value = true
  }
}
onMounted(load)

// The fields each kind of provider needs, in the order of its console
const FIELDS = {
  Keycloak: ['server_url', 'realm', 'client_id', 'client_secret'],
  OIDC: ['base_url', 'client_id', 'client_secret']
}
const fieldsOf = (provider) =>
  (FIELDS[provider.class] ?? Object.keys(provider.provider_config)).filter((key) => key in provider.provider_config && key !== 'logout_at_provider')
const isSecret = (key) => /secret|token|password|key/.test(key)
const fieldLabel = (key) => t.value(`sso.field.${key}`, t.value(`settings.system.auth_provider_config_${key}`))
const testable = (provider) => ['Keycloak', 'OIDC'].includes(provider.class)

const addProvider = async () => {
  const type = types.value.find((item) => item.class === newType.value)
  if (!type) return
  const uuid = window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`
  providers.value.push({
    name: type.name,
    provider_name: type.name,
    icon: type.icon,
    class: type.class,
    provider_config: { ...type.provider_config },
    uuid,
    enabled: false,
    allow_registration: false,
    trust_email: false,
    editing: true,
    callback_url: await getCallbackUrl(uuid),
    post_logout_redirect_uri: providers.value[0]?.post_logout_redirect_uri ?? `${window.location.origin}/`
  })
  newType.value = ''
}

const confirmDialog = useConfirmDialog()
const removeProvider = async (provider) => {
  const sure = await confirmDialog.show({
    title: t.value('dialog.title'),
    message: t.value('settings.system.delete_auth_provider_confirmation'),
    okText: t.value('dialog.delete'),
    cancelText: t.value('dialog.cancel'),
    tone: 'danger'
  })
  if (!sure) return
  if (!provider.id) {
    providers.value = providers.value.filter((item) => item !== provider)
    return
  }
  try {
    await deleteAuthProvider(provider.id)
    providers.value = providers.value.filter((item) => item !== provider)
    toast.success(t.value('sso.provider_deleted'))
  } catch (error) {
    toast.error(t.value('sso.provider_delete_failed'))
  }
}

const runTest = async (provider) => {
  testing.value[provider.uuid] = true
  try {
    results.value[provider.uuid] = await testAuthProvider(provider.class, provider.provider_config)
  } catch (error) {
    results.value[provider.uuid] = { discovery: 'invalid_config' }
  } finally {
    testing.value[provider.uuid] = false
  }
}
const resultLines = (result) => {
  if (!result) return []
  if (result.discovery === 'invalid_config') return [{ ok: false, text: t.value('sso.test.invalid_config') }]
  const lines = [{ ok: result.discovery === 'ok', text: t.value(`sso.test.discovery_${result.discovery}`, { issuer: result.issuer ?? '' }) }]
  if (result.discovery === 'ok') {
    lines.push({ ok: result.client === 'ok', warn: result.client === 'unknown', text: t.value(`sso.test.client_${result.client}`) })
    lines.push({ ok: result.logout, warn: !result.logout, text: result.logout ? t.value('sso.test.logout_yes') : t.value('sso.test.logout_no') })
  }
  return lines
}

const copy = async (text, key) => {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = key
    setTimeout(() => (copied.value = copied.value === key ? '' : copied.value), 1500)
  } catch (error) {
    toast.error(t.value('shares.copy_failed'))
  }
}

const save = async () => {
  try {
    await saveSettingsById({ sso_required_domains: domainList.value.join(', ') })
  } catch (error) {
    toast.error(error.code === 'sso_domains_invalid' ? t.value('sso.domains_invalid', { domains: (error.data?.invalid ?? []).join(', ') }) : t.value('sso.save_failed'))
    return
  }
  try {
    await bulkUpdateAuthProviders(providers.value)
    toast.success(t.value('sso.saved'))
    await load()
  } catch (error) {
    toast.error(t.value('sso.providers_invalid'))
  }
}

defineExpose({ save })
</script>

<template>
  <div class="admin-sections sso-settings">
    <section class="ot-card setting-group" id="sso-required" aria-labelledby="h-sso-required">
      <h2 class="ot-card-title" id="h-sso-required">{{ t('sso.required_title') }}</h2>
      <div class="setting-row">
        <div class="setting-fields">
          <div class="input-container">
            <label for="sso_required_domains">{{ t('sso.required_label') }}</label>
            <textarea id="sso_required_domains" v-model="requiredDomains" rows="2" :placeholder="t('sso.required_placeholder')" />
          </div>
          <div class="domain-chips" v-if="domainList.length > 0">
            <span class="ot-pill" v-for="domain in domainList" :key="domain">@{{ domain }}</span>
          </div>
          <p class="sso-warning" v-if="lockoutRisk" role="alert">{{ t('sso.lockout_risk') }}</p>
        </div>
        <div class="section-help">
          <p>{{ t('sso.required_help') }}</p>
          <p>{{ t('sso.required_help_admins') }}</p>
        </div>
      </div>
    </section>

    <section class="ot-card setting-group" id="auth_providers" aria-labelledby="h-providers">
      <h2 class="ot-card-title" id="h-providers">{{ t('sso.providers_title') }}</h2>

      <p class="sso-empty" v-if="loaded && providers.length === 0">{{ t('sso.no_provider') }}</p>

      <article v-for="provider in providers" :key="provider.uuid" class="sso-provider" :class="{ open: provider.editing }">
        <header class="sso-provider-head">
          <span class="sso-provider-icon" v-html="provider.icon" aria-hidden="true"></span>
          <div class="sso-provider-names">
            <h3>{{ provider.name }}</h3>
            <span>{{ provider.provider_name }}</span>
          </div>
          <span class="ot-pill" :class="{ 'is-success': provider.enabled }">
            {{ provider.enabled ? t('settings.system.enabled') : t('settings.system.disabled') }}
          </span>
          <button type="button" class="ot-act" @click="provider.editing = !provider.editing" :aria-expanded="provider.editing">
            <ChevronDown class="sso-chevron" :class="{ 'is-open': provider.editing }" aria-hidden="true" />
            {{ provider.editing ? t('sso.collapse') : t('settings.provider.edit') }}
          </button>
        </header>

        <div class="sso-provider-body" v-if="provider.editing">
          <div class="sso-form">
            <div class="input-container">
              <label :for="`name-${provider.uuid}`">{{ t('sso.field.name') }}</label>
              <input type="text" :id="`name-${provider.uuid}`" v-model="provider.name" />
              <p class="help-text">{{ t('sso.field.name_help') }}</p>
            </div>

            <div class="input-container" v-for="key in fieldsOf(provider)" :key="key">
              <label :for="`${key}-${provider.uuid}`">{{ fieldLabel(key) }}</label>
              <div class="field-with-action" v-if="isSecret(key)">
                <input
                  :type="shown[`${provider.uuid}-${key}`] ? 'text' : 'password'"
                  :id="`${key}-${provider.uuid}`"
                  v-model="provider.provider_config[key]"
                  autocomplete="off"
                />
                <button
                  type="button"
                  class="field-action"
                  @click="shown[`${provider.uuid}-${key}`] = !shown[`${provider.uuid}-${key}`]"
                  :aria-label="shown[`${provider.uuid}-${key}`] ? t('send.hide_password') : t('send.show_password')"
                >
                  <EyeOff v-if="shown[`${provider.uuid}-${key}`]" class="ot-check-pop" aria-hidden="true" />
                  <Eye v-else class="ot-check-pop" aria-hidden="true" />
                </button>
              </div>
              <input
                v-else
                type="text"
                :id="`${key}-${provider.uuid}`"
                v-model="provider.provider_config[key]"
                :placeholder="t(`sso.placeholder.${key}`, '')"
                autocomplete="off"
              />
              <p class="help-text" v-if="t(`sso.hint.${provider.class}.${key}`, '')">{{ t(`sso.hint.${provider.class}.${key}`, '') }}</p>
            </div>

            <div class="sso-options">
              <div class="checkbox-container">
                <input type="checkbox" :id="`enabled-${provider.uuid}`" v-model="provider.enabled" />
                <label :for="`enabled-${provider.uuid}`">{{ t('sso.option.enabled') }}</label>
              </div>
              <div class="checkbox-container">
                <input type="checkbox" :id="`trust-${provider.uuid}`" v-model="provider.trust_email" />
                <label :for="`trust-${provider.uuid}`">{{ t('sso.option.trust_email') }}</label>
              </div>
              <p class="help-text">{{ t('sso.option.trust_email_help') }}</p>
              <div class="checkbox-container">
                <input type="checkbox" :id="`register-${provider.uuid}`" v-model="provider.allow_registration" />
                <label :for="`register-${provider.uuid}`">{{ t('sso.option.allow_registration') }}</label>
              </div>
              <template v-if="'logout_at_provider' in provider.provider_config">
                <div class="checkbox-container">
                  <input type="checkbox" :id="`logout-${provider.uuid}`" v-model="provider.provider_config.logout_at_provider" />
                  <label :for="`logout-${provider.uuid}`">{{ t('sso.option.logout_at_provider', { name: provider.provider_name }) }}</label>
                </div>
              </template>
            </div>

            <div class="sso-test" v-if="testable(provider)">
              <button type="button" class="secondary" @click="runTest(provider)" :disabled="testing[provider.uuid]">
                <PlugZap aria-hidden="true" />
                {{ testing[provider.uuid] ? t('sso.test.running') : t('sso.test.button') }}
              </button>
              <ul class="sso-test-results" v-if="results[provider.uuid]" aria-live="polite">
                <li v-for="line in resultLines(results[provider.uuid])" :key="line.text" :class="line.ok ? 'is-ok' : line.warn ? 'is-warn' : 'is-error'">
                  {{ line.text }}
                </li>
              </ul>
            </div>
          </div>

          <!-- What the admin declares at the provider, ready to copy -->
          <aside class="sso-declare" :aria-label="t('sso.declare_title', { name: provider.provider_name })">
            <h4>{{ t('sso.declare_title', { name: provider.provider_name }) }}</h4>
            <ol>
              <li v-if="provider.class === 'Keycloak'">{{ t('sso.declare.keycloak_client') }}</li>
              <li>
                <span>{{ t('sso.declare.redirect_uri') }}</span>
                <span class="sso-copy">
                  <code>{{ provider.callback_url }}</code>
                  <button type="button" class="ot-act icon-only" @click="copy(provider.callback_url, `cb-${provider.uuid}`)" :aria-label="t('sso.copy')">
                    <Check v-if="copied === `cb-${provider.uuid}`" class="ot-check-pop" aria-hidden="true" />
                    <Copy v-else aria-hidden="true" />
                  </button>
                </span>
              </li>
              <li v-if="provider.provider_config.logout_at_provider">
                <span>{{ t('sso.declare.post_logout_uri') }}</span>
                <span class="sso-copy">
                  <code>{{ provider.post_logout_redirect_uri }}</code>
                  <button type="button" class="ot-act icon-only" @click="copy(provider.post_logout_redirect_uri, `pl-${provider.uuid}`)" :aria-label="t('sso.copy')">
                    <Check v-if="copied === `pl-${provider.uuid}`" class="ot-check-pop" aria-hidden="true" />
                    <Copy v-else aria-hidden="true" />
                  </button>
                </span>
              </li>
              <li v-if="provider.class === 'Keycloak'">{{ t('sso.declare.keycloak_secret') }}</li>
            </ol>
            <button type="button" class="ghost sso-delete" @click="removeProvider(provider)">
              <Trash2 aria-hidden="true" />
              {{ t('settings.system.delete_auth_provider') }}
            </button>
          </aside>
        </div>
      </article>

      <div class="sso-add">
        <label class="visually-hidden-label" for="new-provider-type">{{ t('settings.system.select_auth_provider') }}</label>
        <OtSelect
          id="new-provider-type"
          v-model="newType"
          :options="types.map((type) => ({ value: type.class, label: type.name }))"
          :placeholder="t('settings.system.select_auth_provider')"
        />
        <button type="button" @click="addProvider" :disabled="!newType">
          <Plus aria-hidden="true" />
          {{ t('sso.add_provider') }}
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped lang="scss">
// The chevron of a provider turns over on a spring as its settings open
.sso-chevron {
  transition: transform var(--ot-dur-slow) var(--ot-ease-spring);

  &.is-open {
    transform: rotate(180deg);
  }
}
.domain-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.sso-warning {
  margin: 0;
  padding: 10px 14px;
  border-left: 3px solid var(--ot-warning);
  border-radius: var(--ot-radius);
  background: var(--ot-warning-soft);
  color: var(--ot-text);
  font-size: 0.8125rem;
}
.sso-empty {
  margin: 0;
  color: var(--ot-muted);
}

.sso-provider {
  border: 1px solid var(--ot-line);
  border-radius: var(--ot-radius);
  background: var(--ot-field);

  &.open {
    background: var(--ot-card);
  }
}
.sso-provider-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;

  .sso-provider-icon {
    display: inline-flex;
    width: 22px;
    height: 22px;
    color: var(--ot-accent-text);
    fill: currentColor;

    :deep(svg) {
      width: 22px;
      height: 22px;
    }
  }
  .sso-provider-names {
    flex: 1;
    min-width: 0;

    h3 {
      margin: 0;
      font-size: 0.9375rem;
      font-weight: 700;
      color: var(--ot-heading);
    }
    span {
      font-size: 0.75rem;
      color: var(--ot-muted);
    }
  }
}

.sso-provider-body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 0.9fr);
  gap: 20px;
  padding: 4px 14px 16px;
  border-top: 1px solid var(--ot-line);

  @media (max-width: 900px) {
    grid-template-columns: minmax(0, 1fr);
  }
}
.sso-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-top: 12px;

  .input-container input {
    margin-bottom: 0;
  }
  .help-text {
    margin: 4px 0 0;
    color: var(--ot-muted);
  }
}
.sso-options {
  display: flex;
  flex-direction: column;
  gap: 2px;

  .checkbox-container {
    grid-template-rows: none;
    padding: 4px 0;

    label {
      font-size: 0.875rem;
    }
  }
  .help-text {
    margin: -2px 0 6px 30px;
  }
}
.sso-test {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;
}
.sso-test-results {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 0.8125rem;

  li {
    padding: 6px 10px;
    border-radius: var(--ot-radius-sm);

    &.is-ok {
      background: var(--ot-success-soft);
      color: var(--ot-success);
    }
    &.is-warn {
      background: var(--ot-warning-soft);
      color: var(--ot-warning);
    }
    &.is-error {
      background: var(--ot-danger-soft);
      color: var(--ot-danger-text);
    }
  }
}

.sso-declare {
  margin-top: 12px;
  padding: 14px 16px;
  border-radius: var(--ot-radius);
  background: var(--ot-field);
  display: flex;
  flex-direction: column;
  gap: 10px;

  h4 {
    margin: 0;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ot-accent-text);
  }
  ol {
    margin: 0;
    padding-left: 18px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    font-size: 0.8125rem;
    color: var(--ot-text);
  }
  li span {
    display: block;
  }
  .sso-copy {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;

    code {
      flex: 1;
      min-width: 0;
      padding: 6px 8px;
      border: 1px solid var(--ot-line);
      border-radius: var(--ot-radius-sm);
      background: var(--ot-card);
      color: var(--ot-heading);
      font-size: 0.75rem;
      overflow-wrap: anywhere;
    }
  }
  .sso-delete {
    align-self: flex-start;
    margin-top: 6px;
    color: var(--ot-danger-text);
  }
}

.sso-add {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;

  .ot-select {
    width: auto;
    min-width: 220px;
    margin: 0;
  }
  :deep(.ot-select-trigger) {
    height: var(--ot-control);
  }
  button {
    margin: 0;
  }
}
</style>
