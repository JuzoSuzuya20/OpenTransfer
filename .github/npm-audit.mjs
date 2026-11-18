// The audit of the JS dependencies, for the CI: as `npm audit --audit-level=high`, any high or
// critical advisory fails it, except one accepted in npm-audit-exceptions.json (next to this file),
// with its reason, until its date; past it, the advisory fails the audit again, to be looked at.
import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const BLOCKING = new Set(['high', 'critical'])

/** The advisories of the report that fail the audit: high or critical, not accepted on that day */
export function refused(report, exceptions, today) {
  const accepted = new Map(exceptions.map((e) => [e.id, e]))
  const advisories = new Map()
  for (const vulnerability of Object.values(report.vulnerabilities ?? {})) {
    for (const via of vulnerability.via ?? []) {
      // A string names another vulnerable package: its own advisories are counted there
      if (typeof via !== 'object' || !BLOCKING.has(via.severity)) continue
      const id = String(via.url ?? '').split('/').pop() || `${via.name}#${via.source}`
      advisories.set(id, { id, package: via.name, severity: via.severity, title: via.title })
    }
  }

  return [...advisories.values()].filter((a) => {
    const exception = accepted.get(a.id)
    return !exception || exception.package !== a.package || today > exception.until
  })
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const exceptions = JSON.parse(readFileSync(new URL('./npm-audit-exceptions.json', import.meta.url), 'utf8'))
  let output
  try {
    output = execFileSync('npm', ['audit', '--json'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
  } catch (error) {
    // npm audit exits non-zero as soon as it finds anything: the report is still on its output
    output = error.stdout
    if (!output) throw error
  }
  const today = new Date().toISOString().slice(0, 10)
  const failing = refused(JSON.parse(output), exceptions, today)
  for (const e of exceptions) {
    console.log(`Accepted until ${e.until}: ${e.id} (${e.package}): ${e.reason}`)
  }
  if (failing.length) {
    for (const a of failing) console.error(`${a.severity}: ${a.id} (${a.package}) ${a.title}`)
    console.error(`${failing.length} high or critical advisory(ies) not accepted: npm audit for the details`)
    process.exit(1)
  }
  console.log('No high or critical advisory left to look at')
}
