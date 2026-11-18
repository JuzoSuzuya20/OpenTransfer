// Where the sign-in page leads when an admin forced an identity provider (like the "forced"
// OpenID Connect of ITSM-NG): straight to it, except where that would trap someone. The form stays
// when asked for (?noAUTO, kept for local accounts: admins), after a sign-out just made (the
// provider may still hold a session and sign straight back in), with a message of the server
// waiting (an SSO that failed would loop), for a guest link or an invite, and for a password reset.
const KEEP_THE_FORM = ['noauto', 'invite_token', 'invite_id']

// Set by a sign-out made on purpose, read (and cleared) by the next sign-in page of the tab
export const SIGNED_OUT_KEY = 'ot-signed-out'

// The form of local accounts is needed: asked for (?noAUTO), a guest link or an invite, a reset
const keepsTheForm = ({ path = '/', search = '' }) => {
  if (String(path).startsWith('/reset-password')) return true
  const keys = [...new URLSearchParams(search).keys()].map((key) => key.toLowerCase())
  return keys.some((key) => KEEP_THE_FORM.includes(key))
}

export const forcedSsoTarget = ({ url, path = '/', search = '', signedOut = false, flash = false, shown = true }) => {
  if (!url || !shown || signedOut || flash) return null
  return keepsTheForm({ path, search }) ? null : url
}

// With a forced provider, the sign-in page shows its button alone, led to it or not (after a
// sign-out just made, with a message of the server): the form of local accounts at ?noAUTO only
export const forcedSsoOnly = ({ url, path = '/', search = '' }) => Boolean(url) && !keepsTheForm({ path, search })

// The provider of the address the sign-in page is led to (/auth/provider/{id}/login)
export const forcedProviderId = (url) => {
  const match = String(url ?? '').match(/^\/auth\/provider\/(\d+)\/login$/)
  return match ? Number(match[1]) : null
}

// Decided once the session had its chance and the page rendered for it: the end of the wait
// (store.restoringSession) shows the sign-in page on the next render only, and decided at once the
// page still read "not shown" and never led anywhere
export const afterTheSessionSettles = (restored, nextTick, lead) =>
  Promise.resolve(restored)
    .catch(() => {})
    .then(() => nextTick())
    .then(lead)
