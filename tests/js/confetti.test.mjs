// A burst of confetti of every colour when files are dropped or downloaded: they fly out of the
// point of the gesture, fall under gravity, slow down in the air and fade as they go. Nothing at
// all when the system asks for less motion.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createBurst, stepConfetto, burstIsOver, burst } from '../../resources/js/lib/confetti.js'

// A repeatable "random" for the tests
const seeded = (seed = 1) => () => ((seed = (seed * 16807) % 2147483647) - 1) / 2147483646

test('a burst starts from the point of the gesture, in every colour', () => {
  const pieces = createBurst({ x: 300, y: 200, count: 120, random: seeded() })

  assert.equal(pieces.length, 120)
  assert.ok(pieces.every((p) => p.x === 300 && p.y === 200))
  const hues = new Set(pieces.map((p) => Math.round(p.hue / 30)))
  assert.ok(hues.size >= 10, `only ${hues.size} hues out of 12`)
  // An explosion: pieces leave in many directions, most of them upwards
  assert.ok(pieces.some((p) => p.vx < 0) && pieces.some((p) => p.vx > 0))
  assert.ok(pieces.filter((p) => p.vy < 0).length > pieces.length / 2)
})

test('a piece falls under gravity and slows down in the air', () => {
  const [piece] = createBurst({ x: 0, y: 0, count: 1, random: seeded(7) })
  const start = { ...piece }
  let p = piece
  for (let i = 0; i < 10; i++) p = stepConfetto(p, 16)

  assert.ok(p.vy > start.vy, 'gravity pulls it down')
  assert.ok(Math.abs(p.vx) < Math.abs(start.vx), 'the air slows it down')
  assert.notEqual(p.rotation, start.rotation)
})

test('pieces fade out, faster towards the end, and the burst ends', () => {
  let pieces = createBurst({ x: 0, y: 0, count: 30, random: seeded(3) })
  const opacities = []
  let elapsed = 0
  while (!burstIsOver(pieces) && elapsed < 10000) {
    pieces = pieces.map((p) => stepConfetto(p, 16))
    opacities.push(pieces[0].opacity)
    elapsed += 16
  }

  assert.ok(burstIsOver(pieces))
  assert.ok(elapsed < 3500, `the burst lasted ${elapsed} ms`)
  // Not linear: the fading speeds up
  const drops = opacities.map((o, i) => (i ? opacities[i - 1] - o : 0)).filter((d) => d > 0)
  assert.ok(drops[drops.length - 1] > drops[0])
})

test('nothing is drawn when the system asks for less motion', () => {
  const win = { matchMedia: () => ({ matches: true }), document: { body: { appendChild: () => assert.fail('no canvas') } } }

  assert.equal(burst({ x: 10, y: 10 }, { win }), false)
})
