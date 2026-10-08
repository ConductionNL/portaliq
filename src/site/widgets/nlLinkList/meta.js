/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Lijst met links widget's description for the editor (design D1 row 54).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlLinkList',
	group: 'nav',
	label: 'Lijst met links',
	nlds: 'Link List',
	synonyms: ['links', 'verwijzingen', 'menu', 'handige links', 'doorverwijzingen'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop boven de lijst' },
		{ name: 'intro', kind: 'text', label: 'Regel onder de kop' },
		{ name: 'links', kind: 'json', label: 'Links (tekst, adres, uitleg)' },
		{
			name: 'display',
			kind: 'string',
			label: 'Weergave: plain, card, tinted of accent',
		},
	],
	defaultSize: { gridWidth: 4, gridHeight: 3 },
	scope: 'public',
}
