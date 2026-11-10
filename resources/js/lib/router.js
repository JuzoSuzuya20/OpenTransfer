// The addresses of the application. Pure functions: App.vue holds the current route and the history.

export const ADMIN_SECTIONS = ['system', 'branding', 'email-templates', 'users', 'all-shares']

const STATIC_PAGES = {
  '/': 'send',
  '/my-shares': 'my-shares',
  '/requests': 'requests',
  '/stats': 'stats',
  '/profile': 'profile',
  '/about': 'about'
}

// The tabs of the former settings overlay, and the page each one became
const SETTINGS_TABS = {
  myShares: '/my-shares',
  myProfile: '/profile',
  stats: '/stats',
  about: '/about',
  system: '/admin/system',
  branding: '/admin/branding',
  emailTemplates: '/admin/email-templates',
  users: '/admin/users',
  allShares: '/admin/all-shares'
}

export const resolveRoute = (pathname) => {
  const path = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname

  if (STATIC_PAGES[path]) {
    return { name: STATIC_PAGES[path], params: {} }
  }

  const segments = path.split('/').filter(Boolean)

  if (segments[0] === 'admin' && segments.length <= 2) {
    const section = segments[1] ?? 'system'
    return ADMIN_SECTIONS.includes(section) ? { name: 'admin', params: { section } } : { name: 'not-found', params: {} }
  }
  if (segments[0] === 'shares' && segments.length === 2) {
    return { name: 'download', params: { code: segments[1] } }
  }
  if (segments[0] === 'reset-password' && segments.length === 2) {
    return { name: 'reset-password', params: { token: segments[1] } }
  }

  return { name: 'not-found', params: {} }
}

export const settingsTabToPath = (tab, section = null, subSection = null) => {
  const path = SETTINGS_TABS[tab]
  if (!path) {
    return null
  }

  const anchor = subSection || section
  return anchor ? `${path}#${anchor}` : path
}

export const legacyHashToPath = (hash) => {
  if (!hash || !hash.startsWith('#settings/')) {
    return null
  }

  const [tab, section, subSection] = hash.slice('#settings/'.length).split('.')
  return settingsTabToPath(tab, section, subSection)
}
