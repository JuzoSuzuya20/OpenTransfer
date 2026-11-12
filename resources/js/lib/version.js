// The version the image was built with (git describe, without its "v"): "1.0.0" for a release,
// "1.0.0-3-g096bfb7" for a build of main three commits after it, "096bfb7" before any release.

export const REPOSITORY = 'https://github.com/JuzoSuzuya20/OpenTransfer'

export const versionUrl = (version) => {
  const text = String(version ?? '')
  if (/^\d+\.\d+\.\d+$/.test(text)) return `${REPOSITORY}/releases/tag/v${text}`
  const commit = text.match(/-g([0-9a-f]{7,40})$/) ?? text.match(/^([0-9a-f]{7,40})$/)
  return commit ? `${REPOSITORY}/commit/${commit[1]}` : null
}
