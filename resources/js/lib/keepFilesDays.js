import { positiveNumber } from './positiveNumber.js'

// How long the files received for a request stay online, as the member chooses it: a few usual
// lengths, never past the longest a share may stay (the administration's limit, then a choice of
// its own), starting on the default lifetime of a share
const USUAL_DAYS = [1, 3, 7, 14, 30, 60, 90]

export const keepFilesChoices = ({ max, fallback }) => {
  const limit = positiveNumber(max)
  const wanted = positiveNumber(fallback, 7)
  const chosen = limit !== null ? Math.min(wanted, limit) : wanted
  const days = new Set(USUAL_DAYS.filter((day) => limit === null || day <= limit))
  if (limit !== null) days.add(limit)
  days.add(chosen)

  return { days: [...days].sort((a, b) => a - b), chosen }
}
