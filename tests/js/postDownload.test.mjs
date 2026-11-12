// A share behind a password is downloaded by a posted form: the password travels in the body,
// never in an address that a browser history, a proxy or a server log would keep.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { postDownload } from '../../resources/js/lib/postDownload.js'

const fakeDocument = () => {
  const made = []
  const element = (tag) => {
    const node = { tag, children: [], submitted: false, appendChild: (child) => node.children.push(child), submit: () => (node.submitted = true) }
    made.push(node)
    return node
  }
  const body = { children: [], appendChild: (child) => body.children.push(child), removeChild: (child) => body.children.splice(body.children.indexOf(child), 1) }
  return { made, body, createElement: element }
}

test('the password goes in the body of a posted form', () => {
  const doc = fakeDocument()
  postDownload('/api/shares/abc/download', { password: 's3cret' }, doc)

  const form = doc.made.find((node) => node.tag === 'form')
  assert.equal(form.method, 'POST')
  assert.equal(form.action, '/api/shares/abc/download')
  assert.doesNotMatch(form.action, /s3cret/)
  assert.deepEqual(form.children.map(({ type, name, value }) => ({ type, name, value })), [{ type: 'hidden', name: 'password', value: 's3cret' }])
  assert.equal(form.submitted, true)
})

test('the form leaves the page once sent', async () => {
  const doc = fakeDocument()
  postDownload('/api/shares/abc/download', { password: 's3cret' }, doc)

  await new Promise((resolve) => setTimeout(resolve, 0))
  assert.deepEqual(doc.body.children, [])
})
