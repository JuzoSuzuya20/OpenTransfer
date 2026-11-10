// The email domains whose accounts sign in through the identity provider, read like
// app/Services/SsoPolicy.php reads them: separated by spaces, commas or semicolons, any case, an optional @.

export const parseSsoDomains = (value) =>
  [...new Set(String(value ?? '').toLowerCase().split(/[\s,;]+/).filter(Boolean).map((domain) => domain.replace(/^@+/, '')))]

// The domain of the address when it is listed, null otherwise: exact domain only, like the server
export const ssoDomainOf = (email, domains) => {
  const at = String(email ?? '').lastIndexOf('@')
  if (at === -1) return null
  const domain = String(email).slice(at + 1).toLowerCase()
  return parseSsoDomains(domains).includes(domain) ? domain : null
}
