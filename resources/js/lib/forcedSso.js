// Where the sign-in page leads when an admin forced an identity provider (like the "forced"
// OpenID Connect of ITSM-NG): straight to it, except where that would trap someone. The form stays
// when asked for (?noAUTO, kept for local accounts: admins), after a sign-out just made (the
// provider may still hold a session and sign straight back in), with a message of the server
// waiting (an SSO that failed would loop), for a guest link or an invite, and for a password reset.
const KEEP_THE_FORM = ['noauto', 'invite_token', 'invite_id']

// Set by a sign-out made on purpose, read (and cleared) by the next sign-in page of the tab
export const SIGNED_OUT_KEY = 'ot-signed-out'

export const forcedSsoTarget = ({ url, path = '/', search = '', signedOut = false, flash = false, shown = true }) => {
  if (!url || !shown || signedOut || flash) return null
  if (String(path).startsWith('/reset-password')) return null
  const keys = [...new URLSearchParams(search).keys()].map((key) => key.toLowerCase())
  if (keys.some((key) => KEEP_THE_FORM.includes(key))) return null
  return url
}
