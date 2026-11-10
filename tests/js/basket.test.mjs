// The send screen lists what will be sent: each file at the top level, and each folder as one row
// with its file count. Each row shows how far its upload went.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { basketRows } from '../../resources/js/lib/basket.js'

const file = (fullPath, size, type = 'application/pdf') => ({ name: fullPath.split('/').pop(), fullPath, size, type })

test('a file at the top level is a row of its own', () => {
  const rows = basketRows([file('plans.pdf', 100)])

  assert.deepEqual(rows, [{ key: 'plans.pdf', kind: 'file', name: 'plans.pdf', type: 'application/pdf', count: 1, size: 100, progress: 0, state: 'waiting', files: [rows[0].files[0]] }])
})

test('the files of a folder make one row', () => {
  const rows = basketRows([file('Relevés/a.jpg', 100, 'image/jpeg'), file('Relevés/sub/b.jpg', 300, 'image/jpeg'), file('cctp.docx', 10)])

  assert.deepEqual(
    rows.map((row) => [row.kind, row.name, row.count, row.size]),
    [
      ['folder', 'Relevés', 2, 400],
      ['file', 'cctp.docx', 1, 10]
    ]
  )
})

test('a sent file is done', () => {
  const [row] = basketRows([file('plans.pdf', 100)], { completed: ['plans.pdf'] })

  assert.equal(row.state, 'done')
  assert.equal(row.progress, 100)
})

test('the file being sent shows its own progress', () => {
  const [row] = basketRows([file('plans.pdf', 100)], { currentPath: 'plans.pdf', currentProgress: 42.4 })

  assert.equal(row.state, 'uploading')
  assert.equal(row.progress, 42)
})

test('a folder progresses with the bytes of its files', () => {
  const [row] = basketRows([file('Relevés/a.jpg', 100), file('Relevés/b.jpg', 300)], {
    completed: ['Relevés/a.jpg'],
    currentPath: 'Relevés/b.jpg',
    currentProgress: 50
  })

  assert.equal(row.state, 'uploading')
  assert.equal(row.progress, 63)
})

test('a folder is done when all its files are', () => {
  const [row] = basketRows([file('Relevés/a.jpg', 100), file('Relevés/b.jpg', 300)], { completed: ['Relevés/a.jpg', 'Relevés/b.jpg'] })

  assert.equal(row.state, 'done')
})
