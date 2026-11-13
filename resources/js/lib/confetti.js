// A burst of confetti of every colour, from the point of a gesture (files dropped, a download).
// Nothing linear: gravity bends every path, the air slows it, and the pieces fade faster and
// faster as they go. Drawn on a canvas of its own above the page, which goes once the burst ends.

const GRAVITY = 0.0012 // px per ms²
const AIR = 0.9984 // speed kept per ms
const SHAPES = ['rect', 'strip', 'circle']

// The pieces of one burst, all at the origin, flying out mostly upwards
export const createBurst = ({ x, y, count = 140, random = Math.random }) =>
  Array.from({ length: count }, (_, index) => {
    const angle = -Math.PI / 2 + (random() - 0.5) * 2.6
    const speed = 0.45 + random() * 0.7
    return {
      x,
      y,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed,
      // Every colour of the wheel, a little apart from one another
      hue: ((index / count) * 360 + random() * 24) % 360,
      shape: SHAPES[Math.floor(random() * SHAPES.length)],
      size: 5 + random() * 5,
      rotation: random() * Math.PI * 2,
      spin: (random() - 0.5) * 0.024,
      flutter: random() * Math.PI * 2,
      age: 0,
      life: 1600 + random() * 800,
      opacity: 1
    }
  })

// One piece, dt milliseconds later
export const stepConfetto = (piece, dt) => {
  const kept = Math.pow(AIR, dt)
  const vx = piece.vx * kept
  const vy = (piece.vy + GRAVITY * dt) * kept
  const age = piece.age + dt
  const progress = Math.min(age / piece.life, 1)
  return {
    ...piece,
    x: piece.x + vx * dt,
    y: piece.y + vy * dt,
    vx,
    vy,
    rotation: piece.rotation + piece.spin * dt,
    flutter: piece.flutter + 0.012 * dt,
    age,
    // Fades out accelerating (an ease-in): barely at first, all at once at the end
    opacity: 1 - progress * progress * progress
  }
}

export const burstIsOver = (pieces) => pieces.every((piece) => piece.age >= piece.life)

const draw = (context, piece) => {
  context.save()
  context.globalAlpha = piece.opacity
  context.fillStyle = `hsl(${piece.hue} 90% 62%)`
  context.translate(piece.x, piece.y)
  context.rotate(piece.rotation)
  // Turning over in the air: seen edge on, then flat again
  context.scale(1, Math.cos(piece.flutter))
  if (piece.shape === 'circle') {
    context.beginPath()
    context.arc(0, 0, piece.size / 2, 0, Math.PI * 2)
    context.fill()
  } else {
    const width = piece.shape === 'strip' ? piece.size * 1.6 : piece.size
    const height = piece.shape === 'strip' ? piece.size * 0.35 : piece.size * 0.6
    context.fillRect(-width / 2, -height / 2, width, height)
  }
  context.restore()
}

// Starts a burst at { x, y } (viewport coordinates); false when the system asks for less motion
export const burst = ({ x, y }, { win = globalThis.window, count = 140 } = {}) => {
  if (!win || win.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return false
  const doc = win.document
  const canvas = doc.createElement('canvas')
  const ratio = win.devicePixelRatio || 1
  canvas.width = win.innerWidth * ratio
  canvas.height = win.innerHeight * ratio
  canvas.setAttribute('aria-hidden', 'true')
  canvas.className = 'ot-confetti'
  doc.body.appendChild(canvas)
  const context = canvas.getContext('2d')
  context.scale(ratio, ratio)

  let pieces = createBurst({ x, y, count })
  let last = null
  const frame = (now) => {
    const dt = last === null ? 16 : Math.min(now - last, 32)
    last = now
    pieces = pieces.map((piece) => stepConfetto(piece, dt))
    context.clearRect(0, 0, win.innerWidth, win.innerHeight)
    pieces.forEach((piece) => piece.opacity > 0 && draw(context, piece))
    if (burstIsOver(pieces)) {
      canvas.remove()
    } else {
      win.requestAnimationFrame(frame)
    }
  }
  win.requestAnimationFrame(frame)
  return true
}

// The middle of an element (the button that started a download), as a burst origin
export const centreOf = (element) => {
  const box = element.getBoundingClientRect()
  return { x: box.left + box.width / 2, y: box.top + box.height / 2 }
}
