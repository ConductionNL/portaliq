// The dated list's rows, pinned before the row builder is split.
import { test } from 'node:test'
import { eventRows } from '../src/site/widgets/nlEventList/events.js'
import { golden } from './support/golden.mjs'

const pinned = golden('event-rows')
const now = new Date('2026-11-10T12:00:00Z')

const ITEMS = [
	{ date: ' 2026-11-20 ', title: ' Ouderavond ', href: '/agenda/ouderavond', meta: ' Aula ', note: ' Vol ', noteTone: 'warning' },
	{ date: '2026-11-12', endDate: '2026-11-14', title: 'Toetsweek', dateLabel: ' 12 t/m 14 nov ', noteTone: 'loud' },
	{ date: '2026-11-01', title: 'Voorbij' },
	{ date: '2026-11-09', endDate: '2026-11-11', title: 'Loopt nog' },
	{ date: 'geen datum', title: 'Fout' },
	{ date: '2026-12-01', title: '   ' },
	null,
	{ date: '2026-11-15', title: 'Link elders', href: 'https://elders.example/x', note: 3 },
]

test('all rows, past included', () => pinned.check('all', eventRows(ITEMS, { upcomingOnly: false, limit: 20, locale: 'nl', now })))
test('upcoming only', () => pinned.check('upcoming', eventRows(ITEMS, { upcomingOnly: true, limit: 20, locale: 'nl', now })))
test('cut to the limit, and the default limit', () => {
	pinned.check('limit-2', eventRows(ITEMS, { upcomingOnly: false, limit: 2, locale: 'en', now }))
	pinned.check('limit-bad', eventRows(ITEMS, { upcomingOnly: false, limit: 'x', now }))
	pinned.check('limit-high', eventRows(ITEMS, { upcomingOnly: false, limit: 99, now }).length)
})
test('no list', () => pinned.check('none', eventRows('x', { upcomingOnly: false, limit: 5, now })))
test('save the golden', () => pinned.save())
