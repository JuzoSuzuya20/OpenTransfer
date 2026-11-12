// The − and + of a number field (components/layout/OtNumber.vue): the next value for a press.

const bound = (value) => (value === undefined || value === null || value === '' ? null : Number(value))

// direction: -1 or 1. An empty field starts from its minimum, or from one increment going up.
export const stepValue = (value, direction, { min, max, increment = 1 } = {}) => {
  const lowest = bound(min)
  const highest = bound(max)
  const current = bound(value)
  let next
  if (current === null || Number.isNaN(current)) {
    next = lowest ?? (direction > 0 ? increment : 0)
  } else {
    next = current + direction * increment
  }
  if (lowest !== null) next = Math.max(lowest, next)
  if (highest !== null) next = Math.min(highest, next)
  return next
}
