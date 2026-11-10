// Every screen has its own address: the back button works and a link can be shared.
// The settings used to live in an overlay reached through '#settings/<tab>.<section>' links;
// those links, still in e-mails and bookmarks, must land on the page that replaced them.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { resolveRoute, settingsTabToPath, legacyHashToPath } from '../../resources/js/lib/router.js'

test('the home page is the send screen', () => {
  assert.deepEqual(resolveRoute('/'), { name: 'send', params: {} })
})

test('each section of the application has its page', () => {
  assert.equal(resolveRoute('/my-shares').name, 'my-shares')
  assert.equal(resolveRoute('/requests').name, 'requests')
  assert.equal(resolveRoute('/stats').name, 'stats')
  assert.equal(resolveRoute('/profile').name, 'profile')
  assert.equal(resolveRoute('/about').name, 'about')
})

test('a trailing slash does not change the page', () => {
  assert.equal(resolveRoute('/my-shares/').name, 'my-shares')
})

test('the administration opens on the system settings', () => {
  assert.deepEqual(resolveRoute('/admin'), { name: 'admin', params: { section: 'system' } })
  assert.deepEqual(resolveRoute('/admin/users'), { name: 'admin', params: { section: 'users' } })
  assert.deepEqual(resolveRoute('/admin/sso'), { name: 'admin', params: { section: 'sso' } })
})

test('an unknown administration section is not found', () => {
  assert.equal(resolveRoute('/admin/nothing').name, 'not-found')
})

test('a share link and a password reset link keep their code', () => {
  assert.deepEqual(resolveRoute('/shares/quick-blue-fox'), { name: 'download', params: { code: 'quick-blue-fox' } })
  assert.deepEqual(resolveRoute('/reset-password/abc123'), { name: 'reset-password', params: { token: 'abc123' } })
})

test('an unknown address is not found', () => {
  assert.equal(resolveRoute('/nowhere').name, 'not-found')
})

test('each former settings tab has its page', () => {
  assert.equal(settingsTabToPath('myShares'), '/my-shares')
  assert.equal(settingsTabToPath('myProfile'), '/profile')
  assert.equal(settingsTabToPath('stats'), '/stats')
  assert.equal(settingsTabToPath('about'), '/about')
  assert.equal(settingsTabToPath('system'), '/admin/system')
  assert.equal(settingsTabToPath('branding'), '/admin/branding')
  assert.equal(settingsTabToPath('emailTemplates'), '/admin/email-templates')
  assert.equal(settingsTabToPath('users'), '/admin/users')
  assert.equal(settingsTabToPath('allShares'), '/admin/all-shares')
})

test('a section of a former tab becomes an anchor of its page', () => {
  assert.equal(settingsTabToPath('system', 'auth'), '/admin/system#auth')
  assert.equal(settingsTabToPath('system', 'auth', 'self_registration'), '/admin/system#self_registration')
})

test('an unknown tab leads nowhere', () => {
  assert.equal(settingsTabToPath('nothing'), null)
})

test('an old settings link lands on the page that replaced it', () => {
  assert.equal(legacyHashToPath('#settings/myProfile.linked_accounts'), '/profile#linked_accounts')
  assert.equal(legacyHashToPath('#settings/users'), '/admin/users')
})

test('an ordinary anchor is not an old settings link', () => {
  assert.equal(legacyHashToPath('#security'), null)
  assert.equal(legacyHashToPath(''), null)
})
