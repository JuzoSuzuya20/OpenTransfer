// What the interface says about shares. Every figure is computed from the shares themselves:
// nothing is estimated.

const HOUR = 3600 * 1000
const ENDING_WITHIN = 48 * HOUR

// active, ending (within two days, or one download from its limit), expired, deleted or failed
export const shareState = (share, now = new Date()) => {
  if (share.status === 'deleted') return 'deleted'
  if (share.status === 'failed') return 'failed'

  const left = new Date(share.expires_at).getTime() - now.getTime()
  const limit = share.download_limit
  if (left <= 0 || (limit && share.download_count >= limit)) return 'expired'
  if (left < ENDING_WITHIN || (limit && limit - share.download_count <= 1)) return 'ending'
  return 'active'
}

const isOnline = (share, now) => ['active', 'ending'].includes(shareState(share, now))
const ownShares = (shares) => shares.filter((share) => !share.shared_with_me)

// The figures above the send form: what the person has online right now
export const sendSummary = (shares, now = new Date()) => {
  const online = ownShares(shares).filter((share) => isOnline(share, now))

  return {
    active: online.length,
    endingWithinADay: online.filter((share) => new Date(share.expires_at).getTime() - now.getTime() < 24 * HOUR).length,
    onlineBytes: online.reduce((total, share) => total + share.size, 0),
    downloads: online.reduce((total, share) => total + share.download_count, 0),
    neverOpened: online.filter((share) => share.download_count === 0).length
  }
}

const startOfMonth = (now) => new Date(now.getFullYear(), now.getMonth(), 1)

// The shares created since the first of the month, and the files received through requests
export const monthSummary = (shares, now = new Date()) => {
  const since = startOfMonth(now).getTime()
  const thisMonth = shares.filter((share) => new Date(share.created_at).getTime() >= since)
  const own = ownShares(thisMonth)

  return {
    created: own.length,
    opened: own.filter((share) => share.download_count > 0).length,
    downloads: own.reduce((total, share) => total + share.download_count, 0),
    bytes: own.reduce((total, share) => total + share.size, 0),
    receivedFiles: thisMonth.filter((share) => share.shared_with_me).reduce((total, share) => total + share.file_count, 0)
  }
}

const startOfWeek = (now) => {
  const day = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const sinceMonday = (day.getDay() + 6) % 7
  day.setDate(day.getDate() - sinceMonday)
  return day
}

// This week (from Monday), last week, then one group per month; newest first
export const groupByWeek = (shares, now = new Date()) => {
  const thisWeek = startOfWeek(now).getTime()
  const lastWeek = thisWeek - 7 * 24 * HOUR
  const groups = []
  const byKey = new Map()

  const sorted = [...shares].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
  for (const share of sorted) {
    const created = new Date(share.created_at)
    let group
    if (created.getTime() >= thisWeek) group = { key: 'this-week', kind: 'this-week' }
    else if (created.getTime() >= lastWeek) group = { key: 'last-week', kind: 'last-week' }
    else {
      const month = new Date(created.getFullYear(), created.getMonth(), 1)
      group = { key: `month-${month.getFullYear()}-${month.getMonth()}`, kind: 'month', month }
    }

    if (!byKey.has(group.key)) {
      byKey.set(group.key, { ...group, shares: [] })
      groups.push(byKey.get(group.key))
    }
    byKey.get(group.key).shares.push(share)
  }

  return groups
}
