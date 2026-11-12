// A size in the reader's language: its units (Mo in French), its decimal separator, as many
// decimals as the size needs (two under 10, one under 100, none above), a no-break space before
// the unit.
const UNITS = {
  en: ['bytes', 'KB', 'MB', 'GB', 'TB'],
  fr: ['octets', 'Ko', 'Mo', 'Go', 'To'],
  de: ['Byte', 'KB', 'MB', 'GB', 'TB'],
  es: ['bytes', 'KB', 'MB', 'GB', 'TB'],
  it: ['byte', 'KB', 'MB', 'GB', 'TB'],
  nl: ['bytes', 'KB', 'MB', 'GB', 'TB'],
  pt: ['bytes', 'KB', 'MB', 'GB', 'TB']
}

export const formatFileSize = (bytes, locale = 'en') => {
  const language = UNITS[locale] ? locale : 'en'
  const units = UNITS[language]
  let value = Math.max(0, Number(bytes) || 0)
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  const digits = unit === 0 ? 0 : value < 10 ? 2 : value < 100 ? 1 : 0
  const number = new Intl.NumberFormat(language, { maximumFractionDigits: digits }).format(value)
  return `${number} ${units[unit]}`
}
