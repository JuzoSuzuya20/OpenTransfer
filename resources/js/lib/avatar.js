// The avatar of an account, drawn here in the DiceBear "Line Face" style (CC0 1.0, by DiceBear):
// nothing is fetched from DiceBear, the seed comes from the server and the same seed gives the same face.
import { Avatar, Style } from '@dicebear/core'
import lineFace from '@dicebear/styles/line-face.json' with { type: 'json' }

// Wrapped once: a bare definition is deprecated by DiceBear and goes away in its version 11
const style = new Style(lineFace)
const cache = new Map()

export const avatarDataUri = (seed) => {
  if (!seed) return null
  if (!cache.has(seed)) {
    cache.set(seed, new Avatar(style, { seed: String(seed) }).toDataUri())
  }
  return cache.get(seed)
}
