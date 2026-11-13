// A setting as the text the server keeps: an empty one goes out empty, never as the text "null"
// (the checks of the server would read it as an entry and refuse the whole page)
export const settingValueAsText = (value) => {
  if (value === null || value === undefined) return ''
  if (typeof File !== 'undefined' && value instanceof File) return value.name
  if (Array.isArray(value)) return value.join(',')
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}
