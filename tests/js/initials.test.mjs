// The download page shows who sent the share as a round badge with their initials.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import initials from '../../resources/js/lib/initials.js'

test('first and last name give two letters', () => {
  assert.equal(initials('Camille Martin'), 'CM')
})

test('middle names are left out', () => {
  assert.equal(initials('Jean Pierre de La Fontaine'), 'JF')
})

test('a single name gives one letter', () => {
  assert.equal(initials('camille'), 'C')
})

test('accented letters are kept whole', () => {
  assert.equal(initials('Élodie Öztürk'), 'ÉÖ')
})

test('surrounding and repeated spaces are ignored', () => {
  assert.equal(initials('  Ana   Silva '), 'AS')
})

test('no name gives no initials', () => {
  assert.equal(initials(''), '')
  assert.equal(initials(null), '')
})
