// The sign-in screen tells the accounts of the organisation to use its identity provider, with the
// same reading of the list of domains as the server (app/Services/SsoPolicy.php).
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { parseSsoDomains, ssoDomainOf } from '../../resources/js/lib/ssoDomains.js'

test('the list is read like the server reads it', () => {
  assert.deepEqual(parseSsoDomains(' @Entreprise.FR,\n mairie.fr ; entreprise.fr'), ['entreprise.fr', 'mairie.fr'])
  assert.deepEqual(parseSsoDomains(''), [])
  assert.deepEqual(parseSsoDomains(null), [])
})

test('an address of a listed domain gets its domain back', () => {
  assert.equal(ssoDomainOf('Camille@ENTREPRISE.fr', 'entreprise.fr'), 'entreprise.fr')
})

test('another domain, even one ending the same way, gets nothing', () => {
  assert.equal(ssoDomainOf('x@fausse-entreprise.fr', 'entreprise.fr'), null)
  assert.equal(ssoDomainOf('y@sous.entreprise.fr', 'entreprise.fr'), null)
  assert.equal(ssoDomainOf('not an address', 'entreprise.fr'), null)
})
