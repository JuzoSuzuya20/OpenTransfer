<script setup>
// Ctrl+K (or the search field of the header): every page and every settings section, found by
// typing. The field is a combobox, the results a listbox: arrows move, Enter opens, Escape closes.
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch, inject, useId } from 'vue'
import {
  Search,
  Settings,
  Palette,
  Users,
  User,
  Boxes,
  FolderOpen,
  BarChart3,
  Mail,
  Fingerprint,
  Share2,
  Send,
  AtSign,
  Database,
  Upload,
  Inbox,
  Info,
  ShieldCheck,
  KeyRound,
  Link2,
  CornerDownLeft
} from 'lucide-vue-next'
import { store } from '../store'
import { domData } from '../domData'
import { useTranslate } from '@tolgee/vue'

const { t } = useTranslate()
const navigate = inject('navigate')
const uid = useId()

const isOpen = ref(false)
const searchQuery = ref('')
const selectedIndex = ref(0)
const searchInput = ref(null)

const GROUPS = ['pages', 'admin', 'system']

const commands = computed(() => {
  const isAdmin = store.isAdmin()
  const fileRequests = domData().allow_reverse_shares === true
  const all = [
    { id: 'send', label: t.value('nav.send'), keywords: 'send upload new share files envoyer', to: '/', icon: Upload, group: 'pages' },
    { id: 'my-shares', label: t.value('nav.my_shares'), keywords: 'my shares files uploads', to: '/my-shares', icon: Boxes, group: 'pages' },
    fileRequests && { id: 'requests', label: t.value('nav.file_requests'), keywords: 'file requests reverse guest invite', to: '/requests', icon: Inbox, group: 'pages' },
    { id: 'profile', label: t.value('nav.profile'), keywords: 'my profile account password two factor passkey', to: '/profile', icon: User, group: 'pages' },
    { id: 'about', label: t.value('settings.title.about'), keywords: 'about version license system information', to: '/about', icon: Info, group: 'pages' },
    isAdmin && { id: 'stats', label: t.value('nav.statistics'), keywords: 'stats statistics dashboard analytics downloads storage', to: '/stats', icon: BarChart3, group: 'pages' },

    isAdmin && { id: 'system', label: t.value('settings.title.system'), keywords: 'system settings configuration', to: '/admin/system', icon: Settings, group: 'admin' },
    isAdmin && { id: 'sso', label: t.value('sso.title'), keywords: 'sso single sign-on keycloak oidc oauth identity provider', to: '/admin/sso', icon: Fingerprint, group: 'admin' },
    isAdmin && { id: 'branding', label: t.value('settings.title.branding'), keywords: 'branding logo favicon brand powered by', to: '/admin/branding', icon: Palette, group: 'admin' },
    isAdmin && { id: 'email-templates', label: t.value('settings.title.emailTemplates'), keywords: 'email templates mail notifications', to: '/admin/email-templates', icon: Mail, group: 'admin' },
    isAdmin && { id: 'users', label: t.value('settings.title.users'), keywords: 'users accounts members admin', to: '/admin/users', icon: Users, group: 'admin' },
    isAdmin && { id: 'all-shares', label: t.value('settings.title.allShares'), keywords: 'all shares files admin manage', to: '/admin/all-shares', icon: FolderOpen, group: 'admin' },

    isAdmin && { id: 'general', label: t.value('settings.system.general'), keywords: 'general application name url language login message', to: '/admin/system#general', icon: Settings, group: 'system' },
    isAdmin && { id: 'shares', label: t.value('settings.system.shares'), keywords: 'shares expiry size limits', to: '/admin/system#shares', icon: Share2, group: 'system' },
    isAdmin && { id: 'reverse', label: t.value('settings.system.reverse_shares'), keywords: 'reverse shares file requests guest invite', to: '/admin/system#reverse_shares', icon: Inbox, group: 'system' },
    isAdmin && { id: 'urls', label: t.value('settings.system.share_url_generation'), keywords: 'url generation pattern haiku shortcode link', to: '/admin/system#share_url_generation', icon: Link2, group: 'system' },
    isAdmin && { id: 'emails', label: t.value('settings.system.emails'), keywords: 'emails notifications download expiry warning', to: '/admin/system#emails', icon: Send, group: 'system' },
    isAdmin && { id: 'smtp', label: t.value('settings.system.smtp'), keywords: 'smtp mail server host port encryption', to: '/admin/system#smtp', icon: AtSign, group: 'system' },
    isAdmin && { id: 'auth', label: t.value('settings.system.auth'), keywords: 'self registration signup sign up new users', to: '/admin/system#auth', icon: KeyRound, group: 'system' },
    isAdmin && { id: 'security', label: t.value('settings.system.security.title'), keywords: 'security two factor mfa 2fa altcha ip', to: '/admin/system#security', icon: ShieldCheck, group: 'system' },
    isAdmin && { id: 'backups', label: t.value('settings.system.backups.title'), keywords: 'backups database backup restore', to: '/admin/system#backups', icon: Database, group: 'system' },
    isAdmin && { id: 'sso-required', label: t.value('sso.required_title'), keywords: 'sso required mandatory domain', to: '/admin/sso#sso-required', icon: Fingerprint, group: 'system' },
    isAdmin && { id: 'providers', label: t.value('sso.providers_title'), keywords: 'identity providers keycloak oidc oauth microsoft google', to: '/admin/sso#auth_providers', icon: Fingerprint, group: 'system' }
  ]
  return all.filter(Boolean)
})

// Accents and case do not count: "systeme" finds "Système"
const fold = (text) => String(text).normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
const fuzzyMatch = (text, query) => {
  const haystack = fold(text)
  const needle = fold(query)
  if (haystack.includes(needle)) return { match: true, score: 100 - haystack.indexOf(needle) }
  // Otherwise every letter typed, in order, with a bonus for the ones that follow each other
  let at = 0
  let score = 0
  let streak = 0
  for (let i = 0; i < haystack.length && at < needle.length; i++) {
    if (haystack[i] === needle[at]) {
      score += 10 + streak
      streak += 5
      at++
    } else {
      streak = 0
    }
  }
  return { match: at === needle.length, score }
}

const filteredCommands = computed(() => {
  const query = searchQuery.value.trim()
  if (!query) return commands.value
  return commands.value
    .map((command) => {
      const label = fuzzyMatch(command.label, query)
      const keywords = fuzzyMatch(command.keywords, query)
      return { ...command, match: label.match || keywords.match, score: Math.max(label.score, keywords.score) }
    })
    .filter((command) => command.match)
    .sort((a, b) => b.score - a.score)
})

// Without a query the results stay in their groups; with one they are ranked, still under a heading
const sections = computed(() => {
  const index = new Map(filteredCommands.value.map((command, position) => [command.id, position]))
  return GROUPS.map((group) => ({
    group,
    items: filteredCommands.value.filter((command) => command.group === group).sort((a, b) => index.get(a.id) - index.get(b.id))
  })).filter((section) => section.items.length > 0)
})
// The order the arrows follow: the one shown
const ordered = computed(() => sections.value.flatMap((section) => section.items))
const optionId = (command) => `${uid}-${command.id}`
const activeCommand = computed(() => ordered.value[selectedIndex.value] ?? null)

watch(filteredCommands, () => (selectedIndex.value = 0))

const open = () => {
  isOpen.value = true
  searchQuery.value = ''
  selectedIndex.value = 0
  nextTick(() => searchInput.value?.focus())
}
const close = () => {
  isOpen.value = false
  searchQuery.value = ''
}
const selectCommand = (command) => {
  close()
  navigate(command.to)
}

const scrollSelectedIntoView = () => {
  nextTick(() => {
    if (activeCommand.value) document.getElementById(optionId(activeCommand.value))?.scrollIntoView({ block: 'nearest' })
  })
}
const handleKeydown = (event) => {
  const count = ordered.value.length
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      selectedIndex.value = Math.min(selectedIndex.value + 1, count - 1)
      scrollSelectedIntoView()
      break
    case 'ArrowUp':
      event.preventDefault()
      selectedIndex.value = Math.max(selectedIndex.value - 1, 0)
      scrollSelectedIntoView()
      break
    case 'Enter':
      event.preventDefault()
      if (activeCommand.value) selectCommand(activeCommand.value)
      break
    case 'Escape':
      event.preventDefault()
      close()
      break
    case 'Tab':
      // The palette is a modal: the focus stays in its field
      event.preventDefault()
      break
  }
}

const handleGlobalKeydown = (event) => {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    if (!store.isLoggedIn()) return
    isOpen.value ? close() : open()
  }
}
const clickOutside = (event) => {
  if (event.target === event.currentTarget) close()
}
const groupLabel = (group) =>
  group === 'admin' ? t.value('nav.administration') : t.value(`commandPalette.category.${group}`)

onMounted(() => document.addEventListener('keydown', handleGlobalKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', handleGlobalKeydown))

defineExpose({ open, close })
</script>

<template>
  <Teleport to="body">
    <Transition name="ot-dialog">
      <div v-if="isOpen" class="command-palette-overlay" @click="clickOutside">
        <div class="command-palette" role="dialog" aria-modal="true" :aria-label="t('nav.search')">
          <div class="command-palette-header">
            <Search class="search-icon" aria-hidden="true" />
            <input
              ref="searchInput"
              v-model="searchQuery"
              type="text"
              role="combobox"
              aria-autocomplete="list"
              aria-expanded="true"
              :aria-controls="`${uid}-results`"
              :aria-activedescendant="activeCommand ? optionId(activeCommand) : undefined"
              :aria-label="t('nav.search')"
              :placeholder="t('nav.search_placeholder')"
              autocomplete="off"
              spellcheck="false"
              @keydown="handleKeydown"
              class="command-search-input"
            />
            <kbd class="keyboard-hint">Esc</kbd>
          </div>

          <div class="command-palette-body" :id="`${uid}-results`" role="listbox" :aria-label="t('nav.search')">
            <p v-if="ordered.length === 0" class="no-results" role="status">{{ t('commandPalette.noResults') }}</p>

            <div v-for="section in sections" :key="section.group" class="command-group" role="group" :aria-labelledby="`${uid}-g-${section.group}`">
              <div class="command-group-label" :id="`${uid}-g-${section.group}`">{{ groupLabel(section.group) }}</div>
              <div
                v-for="command in section.items"
                :key="command.id"
                :id="optionId(command)"
                class="command-item"
                :class="{ selected: command === activeCommand }"
                role="option"
                :aria-selected="command === activeCommand"
                @click="selectCommand(command)"
                @mousemove="selectedIndex = ordered.indexOf(command)"
              >
                <component :is="command.icon" class="command-icon" aria-hidden="true" />
                <span class="command-label">{{ command.label }}</span>
                <CornerDownLeft v-if="command === activeCommand" class="command-enter" aria-hidden="true" />
              </div>
            </div>
          </div>

          <div class="command-palette-footer" aria-hidden="true">
            <span class="hint"><kbd>↑</kbd><kbd>↓</kbd> {{ t('commandPalette.navigate') }}</span>
            <span class="hint"><kbd>↵</kbd> {{ t('commandPalette.select') }}</span>
            <span class="hint"><kbd>Esc</kbd> {{ t('send.close') }}</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style lang="scss" scoped>
// The veil and the palette: the card of a dialog, near the top so the results have room
.command-palette-overlay {
  position: fixed;
  inset: 0;
  z-index: 400;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 12vh 16px 16px;
  background: var(--overlay-background-color);
}

.command-palette {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: 580px;
  max-height: min(560px, 76vh);
  overflow: hidden;
  background: var(--ot-card);
  color: var(--ot-text);
  border: 1px solid var(--ot-line);
  border-radius: var(--ot-radius-lg);
  box-shadow: var(--ot-shadow);
  transform-origin: top center;
}

.command-palette-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 14px;
  border-bottom: 1px solid var(--ot-line-soft);

  .search-icon {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    color: var(--ot-muted);
  }
}
input.command-search-input {
  flex: 1;
  height: 54px;
  margin: 0;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: none;
  color: var(--ot-heading);
  font-size: 1rem;

  &:hover,
  &:focus {
    border: 0;
  }
  &:focus-visible {
    outline: none;
  }
}

.command-palette-body {
  flex: 1;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 6px;
}
.command-group + .command-group {
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px solid var(--ot-line-soft);
}
.command-group-label {
  padding: 8px 10px 4px;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--ot-muted);
}

.command-item {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 40px;
  padding: 0 10px;
  border-radius: var(--ot-radius);
  color: var(--ot-text);
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  transition:
    background-color var(--ot-dur-fast) var(--ot-ease-out),
    color var(--ot-dur-fast) var(--ot-ease-out);

  .command-icon {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    color: var(--ot-muted);
    transition: color var(--ot-dur-fast) var(--ot-ease-out);
  }
  .command-label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .command-enter {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    color: var(--ot-muted);
    animation: ot-check-pop var(--ot-dur) var(--ot-ease-spring) both;
  }

  // The active result: the accent tint of the segmented filters
  &.selected {
    background: var(--ot-accent-soft);
    color: var(--ot-heading);

    .command-icon {
      color: var(--ot-accent-text);
    }
  }

  @media (forced-colors: active) {
    &.selected {
      outline: 2px solid Highlight;
    }
  }
}

.no-results {
  margin: 0;
  padding: 28px 12px;
  text-align: center;
  color: var(--ot-muted);
  font-size: 0.875rem;
}

.command-palette-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 6px 18px;
  padding: 10px 14px;
  border-top: 1px solid var(--ot-line-soft);
  color: var(--ot-muted);
  font-size: 0.75rem;

  .hint {
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
}
kbd {
  min-width: 20px;
  text-align: center;
}

@media (max-width: 600px) {
  .command-palette-overlay {
    padding-top: 16px;
  }
  .command-palette-footer {
    display: none;
  }
}
</style>
