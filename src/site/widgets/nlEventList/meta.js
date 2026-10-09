/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The dated list's description for the editor (site-school-blocks).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlEventList',
	group: 'content',
	label: 'Agenda',
	nlds: 'Link List, Card',
	synonyms: [
		'agenda',
		'kalender',
		'activiteiten',
		'data',
		'binnenkort',
		'evenementen',
		'cursusdagen',
	],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{ name: 'subtitle', kind: 'string', label: 'Regel onder de kop' },
		{
			name: 'items',
			kind: 'json',
			label: 'Data (date, endDate, dateLabel, title, href, meta, note, noteTone)',
		},
		{ name: 'display', kind: 'string', label: 'Weergave: tiles of labels' },
		{ name: 'upcomingOnly', kind: 'boolean', label: 'Alleen wat nog komt' },
		{ name: 'limit', kind: 'number', label: 'Aantal (1 tot 20)' },
		{ name: 'moreLabel', kind: 'string', label: 'Tekst van de link eronder' },
		{ name: 'moreHref', kind: 'string', label: 'Adres van de link eronder' },
		{ name: 'framed', kind: 'boolean', label: 'Als kaart' },
		{
			name: 'source',
			kind: 'json',
			label: 'Uit het aanbod in plaats van de data: {"types": ["event"]}, of uit een app: {"app": "learniq", "kind": "schoolDay", "categories": ["holiday"], "range": "schoolYear"}',
		},
	],
	defaultSize: { gridWidth: 4, gridHeight: 4 },
	scope: 'public',
}
