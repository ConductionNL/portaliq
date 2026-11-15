// The rows of a news block on a record page, pinned before `rows` is split.
import { test } from 'node:test'
import { golden } from './support/golden.mjs'
import { loadSfc } from './support/render-sfc.mjs'

const block = await loadSfc('src/site/components/collections/NewsBlock.vue')
const pinned = golden('news-block-rows')
const translated = (title, targetLanguage) => ({ translatedByAi: true, text: 'Body', title, targetLanguage })

const ITEMS = [
	{ id: 'n1', audienceLabel: ' Ouders ', publishedAt: '2026-11-03T08:00:00Z', title: 'Studiedag' },
	{ '@self': { id: 's2' }, publishedAt: '2026-10-30', title: 'Schoolreis', translation: translated('School trip', 'en') },
	{ title: 'Geen id', translation: translated('No id', '') },
	{ id: 'n4', title: 'Half vertaald', translation: { translatedByAi: true, text: 'x', title: '' } },
	{ id: 'n5', translation: { translatedByAi: false, text: 'x', title: 'Not labelled' } },
	null,
]

for (const locale of ['nl', 'en']) {
	test(`rows in ${locale}`, () => pinned.check(locale, block.computed.rows.call({ items: ITEMS, locale })))
}
test('save the golden', () => pinned.save())
