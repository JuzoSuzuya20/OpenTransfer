// The custom selects (language, limits, settings) are driven from the keyboard as a native select
// is: arrows, Home/End, pages, and typing the first letters of an option.
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { moveIndex, typeaheadIndex } from '../../resources/js/lib/listbox.js'

test('the arrows move one option and stop at both ends', () => {
  assert.equal(moveIndex(2, 'ArrowDown', 5), 3)
  assert.equal(moveIndex(4, 'ArrowDown', 5), 4)
  assert.equal(moveIndex(2, 'ArrowUp', 5), 1)
  assert.equal(moveIndex(0, 'ArrowUp', 5), 0)
})

test('Home and End reach the first and the last option', () => {
  assert.equal(moveIndex(2, 'Home', 5), 0)
  assert.equal(moveIndex(2, 'End', 5), 4)
})

test('Page Up and Page Down jump ten options, within the list', () => {
  assert.equal(moveIndex(3, 'PageDown', 30), 13)
  assert.equal(moveIndex(25, 'PageDown', 30), 29)
  assert.equal(moveIndex(3, 'PageUp', 30), 0)
})

test('with no option active yet, down starts at the top and up at the bottom', () => {
  assert.equal(moveIndex(-1, 'ArrowDown', 5), 0)
  assert.equal(moveIndex(-1, 'ArrowUp', 5), 4)
})

test('any other key leaves the active option where it is', () => {
  assert.equal(moveIndex(2, 'a', 5), 2)
})

const LANGUAGES = ['English', 'Deutsch', 'Español', 'Français', 'Italiano', 'Nederlands', 'Português']

test('typing letters finds the option that starts with them', () => {
  assert.equal(typeaheadIndex(LANGUAGES, 0, 'ned'), 5)
  assert.equal(typeaheadIndex(LANGUAGES, 0, 'POR'), 6)
})

test('typing ignores accents: "fr" finds Français, "es" finds Español', () => {
  assert.equal(typeaheadIndex(LANGUAGES, 0, 'fr'), 3)
  assert.equal(typeaheadIndex(LANGUAGES, 0, 'es'), 2)
})

test('the same letter again goes to the next option that starts with it', () => {
  // English (0) and Español (2) both start with E
  assert.equal(typeaheadIndex(LANGUAGES, 0, 'e'), 2)
  assert.equal(typeaheadIndex(LANGUAGES, 2, 'ee'), 0)
})

test('typing what no option starts with finds nothing', () => {
  assert.equal(typeaheadIndex(LANGUAGES, 0, 'zz'), -1)
})
