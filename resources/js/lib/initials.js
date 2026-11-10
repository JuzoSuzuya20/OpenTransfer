// First letter of the first and of the last word of a name, in upper case.
const initials = (name) => {
  const words = (name ?? '').trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) {
    return ''
  }

  const first = Array.from(words[0])[0]
  const last = words.length > 1 ? Array.from(words[words.length - 1])[0] : ''

  return (first + last).toUpperCase()
}

export default initials
