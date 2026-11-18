// What the build of the image leaves out. The CI scans the image with Trivy before it builds the one
// it publishes, from the same folder: Trivy keeps its database in .cache/trivy there, 1.6 GB that
// went into every published image (up to 1.5.8), and filled the disk of a server that pulled them.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readFileSync } from 'node:fs'

const ignored = readFileSync(new URL('../../.dockerignore', import.meta.url), 'utf8')
  .split('\n')
  .map((line) => line.trim())
  .filter((line) => line && !line.startsWith('#'))

test('the caches of the tools of the CI stay out of the image', () => {
  assert.ok(ignored.some((line) => ['.cache', '.cache/', '/.cache', '**/.cache'].includes(line)), '.cache is left out')
})
