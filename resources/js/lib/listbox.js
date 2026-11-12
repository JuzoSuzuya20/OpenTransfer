// Keyboard logic of the custom selects, kept apart from the component so it can be tested:
// which option becomes active for a key, and which one the letters typed so far point at.

const PAGE = 10

// The active option after a navigation key; the list does not wrap around
export const moveIndex = (current, key, count) => {
  if (count <= 0) return -1
  const last = count - 1
  switch (key) {
    case 'ArrowDown':
      return current < 0 ? 0 : Math.min(current + 1, last)
    case 'ArrowUp':
      return current < 0 ? last : Math.max(current - 1, 0)
    case 'Home':
      return 0
    case 'End':
      return last
    case 'PageDown':
      return Math.min(Math.max(current, 0) + PAGE, last)
    case 'PageUp':
      return Math.max(current - PAGE, 0)
    default:
      return current
  }
}

const fold = (text) =>
  String(text)
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLocaleLowerCase()

// The option the typed letters point at, or -1. The same letter typed again moves on to the next
// option that starts with it; several letters look for the option that starts with all of them.
export const typeaheadIndex = (labels, current, query) => {
  const typed = fold(query)
  if (!typed || labels.length === 0) return -1
  const repeated = [...typed].every((char) => char === typed[0])
  const needle = repeated ? typed[0] : typed
  // One letter (or the same one again) looks after the active option; a word may be the active one
  const start = repeated ? current + 1 : Math.max(current, 0)
  for (let step = 0; step < labels.length; step++) {
    const index = (((start + step) % labels.length) + labels.length) % labels.length
    if (fold(labels[index]).startsWith(needle)) return index
  }
  return -1
}
