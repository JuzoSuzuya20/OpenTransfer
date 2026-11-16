// What a wrong password says (four tries on the server, PasswordAttempts): the usual sentence the
// first time, then how many tries remain, then how long to wait, in whole minutes. The same for
// the sign-in, the password of a share and the password of a file request.
export const MAX_ATTEMPTS = 4

export const attemptsNotice = ({ attemptsLeft, retryAfter, locked = false } = {}) => {
  const left = attemptsLeft == null || attemptsLeft === '' ? null : Number(attemptsLeft)
  const wait = retryAfter == null || retryAfter === '' ? null : Number(retryAfter)
  if (locked || wait !== null || left === 0) {
    return { kind: 'locked', minutes: Math.max(1, Math.ceil((wait ?? 60) / 60)) }
  }
  if (left === null || !Number.isFinite(left) || left >= MAX_ATTEMPTS - 1) return { kind: 'wrong' }
  return { kind: 'left', count: left }
}

// The sentence itself: the usual one for a wrong password (its key), then how many tries remain,
// or how long to wait
export const attemptsMessage = (t, wrongKey, notice) => {
  if (notice.kind === 'locked') return t('attempts.locked', { minutes: notice.minutes })
  if (notice.kind === 'left') return `${t(wrongKey)} ${t('attempts.left', { count: notice.count })}`
  return t(wrongKey)
}
