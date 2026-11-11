import assert from 'node:assert/strict'
// The language switch's entries, pinned before `languageEntries` is split.
import { test } from 'node:test'
import { hrefForLocale, languageEntries, languageName } from '../src/site/lib/languageNav.js'

const HREF = 'https://portal.example/nieuws?pagina=2#top'

test('one entry per locale, trimmed, once each, in the portal\'s order', () => {
	const entries = languageEntries([' nl ', 'en', 'nl', '', 7, null, 'de', 'en '], HREF)
	assert.deepEqual(entries.map((e) => e.locale), ['nl', 'en', 'de'])
	for (const entry of entries) {
		assert.deepEqual(entry, { locale: entry.locale, label: languageName(entry.locale), href: hrefForLocale(HREF, entry.locale) })
	}
	assert.deepEqual(Object.keys(entries[0]), ['locale', 'label', 'href'])
})

test('no page address, or no locales list, gives no entries', () => {
	assert.deepEqual(languageEntries(['nl', 'en'], ''), [])
	assert.deepEqual(languageEntries(['nl', 'en'], null), [])
	assert.deepEqual(languageEntries('nl', HREF), [])
	assert.deepEqual(languageEntries(null, HREF), [])
	assert.deepEqual(languageEntries([], HREF), [])
})
