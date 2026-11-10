// The rows of the send screen: each top-level file, and each folder as one row, with how far
// its upload went. A folder progresses with the bytes of its files.

const keyOf = (file) => file.fullPath || file.name

export const basketRows = (files, { completed = [], currentPath = '', currentProgress = 0 } = {}) => {
  const rows = []
  const folders = new Map()

  for (const file of files) {
    const path = keyOf(file)
    const slash = path.indexOf('/')
    if (slash === -1) {
      rows.push({ key: path, kind: 'file', name: file.name, type: file.type, count: 1, size: 0, files: [] })
      rows[rows.length - 1].files.push(file)
      continue
    }

    const folder = path.slice(0, slash)
    if (!folders.has(folder)) {
      const row = { key: `${folder}/`, kind: 'folder', name: folder, type: '', count: 0, size: 0, files: [] }
      folders.set(folder, row)
      rows.push(row)
    }
    folders.get(folder).files.push(file)
  }

  return rows.map((row) => {
    let sent = 0
    let uploading = false
    for (const file of row.files) {
      if (completed.includes(keyOf(file))) {
        sent += file.size
      } else if (keyOf(file) === currentPath) {
        sent += (file.size * currentProgress) / 100
        uploading = true
      }
    }
    const size = row.files.reduce((total, file) => total + file.size, 0)
    const done = row.files.every((file) => completed.includes(keyOf(file)))
    const progress = done ? 100 : size === 0 ? 0 : Math.round((sent / size) * 100)

    return {
      ...row,
      count: row.files.length,
      size,
      progress,
      state: done ? 'done' : uploading || sent > 0 ? 'uploading' : 'waiting'
    }
  })
}
