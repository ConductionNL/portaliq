// The designer's widget palette in its groups, pinned before `paletteGroups` is split.
import { test } from 'node:test'
import { DUTCH_LABELS, paletteGroups } from '../src/lib/widgetPalette.js'
import { golden } from './support/golden.mjs'

const pinned = golden('palette-groups')
const dutchKey = Object.keys(DUTCH_LABELS)[0]

const ENTRIES = [
	{ key: 'text', label: 'Text', group: 'content', publicSafe: true, description: 'Plain words' },
	{ key: dutchKey, label: 'English label', group: 'nav', publicSafe: true },
	{ key: 'form', label: 'Form', group: 'forms', publicSafe: true, description: 'Zaak starten' },
	{ key: 'cases', label: 'Cases', group: 'mijn', publicSafe: false, description: 'Mijn zaken' },
	{ key: 'odd', label: 'Odd', group: 'nonsense', publicSafe: true },
	{ key: 'nogroup', label: '', publicSafe: true },
	{ key: 'later', label: 'Later', group: 'content', publicSafe: true },
	{ key: 'hidden', label: 'Hidden', publicSafe: false },
]

for (const query of ['', 'zaak', 'odd', 'nothing at all', dutchKey, 'text', 'Mijn', 'hid']) {
	test(`palette for "${query}"`, () => pinned.check(`q:${query}`, paletteGroups(ENTRIES, query)))
}
test('no entries', () => {
	pinned.check('null', paletteGroups(null))
	pinned.check('empty', paletteGroups([]))
})
test('save the golden', () => pinned.save())
