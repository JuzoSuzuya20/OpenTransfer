// The chance a share link pattern carries, in bits: the reckoning of PatternGenerator::bits on
// the server, which refuses a pattern under MIN_PATTERN_BITS before saving it
export const MIN_PATTERN_BITS = 45

const TOKENS = { '#': 10, A: 26, a: 26, '*': 62, X: 16 }

const classChoices = (content) => {
  const chars = new Set()
  for (let i = 0; i < content.length; i++) {
    if (content[i] === '\\' && i + 1 < content.length) {
      chars.add(content[++i])
    } else if (i + 2 < content.length && content[i + 1] === '-') {
      let end = content[i + 2]
      if (end === '\\' && i + 3 < content.length) {
        end = content[i + 3]
        i++
      }
      const [from, to] = [content.charCodeAt(i), end.charCodeAt(0)].sort((x, y) => x - y)
      for (let code = from; code <= to; code++) chars.add(String.fromCharCode(code))
      i += 2
    } else {
      chars.add(content[i])
    }
  }
  return chars.size
}

const closingBracket = (pattern, open) => {
  for (let i = open + 1; i < pattern.length; i++) {
    if (pattern[i] === '\\' && i + 1 < pattern.length) i++
    else if (pattern[i] === ']') return i
  }
  return -1
}

export const patternBits = (pattern) => {
  let bits = 0
  for (let i = 0; i < pattern.length; i++) {
    const char = pattern[i]
    if (char === '\\' && i + 1 < pattern.length) {
      i++
      continue
    }
    if (char === '[') {
      const close = closingBracket(pattern, i)
      if (close !== -1) {
        const choices = classChoices(pattern.slice(i + 1, close))
        if (choices > 1) bits += Math.log2(choices)
        i = close
        continue
      }
    }
    bits += Math.log2(TOKENS[char] ?? 1)
  }
  return bits
}
