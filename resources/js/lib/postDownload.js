// Starts a download by posting a form: what it carries (the password of a share) travels in the
// body, never in an address that a browser history, a proxy or a server log would keep
export const postDownload = (action, fields = {}, doc = document) => {
  const form = doc.createElement('form')
  form.method = 'POST'
  form.action = action
  for (const [name, value] of Object.entries(fields)) {
    const input = doc.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = value
    form.appendChild(input)
  }
  doc.body.appendChild(form)
  form.submit()
  setTimeout(() => doc.body.removeChild(form), 0)
}
