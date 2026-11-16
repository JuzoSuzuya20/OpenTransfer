// An upload left unfinished, kept by the browser (file names, the name and message of the share)
// to offer it again: to the account that started it only, for a week. A computer shared by several
// accounts keeps it for the next person, and sign-out clears it (api.js, logout).
export const UPLOAD_STATE_KEY = 'opentransfer_upload_in_progress'
const MAX_AGE = 7 * 24 * 3600 * 1000

export const interruptedUploadFor = (state, userId, now = Date.now()) => {
  if (!state || typeof state !== 'object') return null
  if (state.userId == null || userId == null || String(state.userId) !== String(userId)) return null
  if (!(now - state.timestamp < MAX_AGE)) return null
  return state
}
