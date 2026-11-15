/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Groep knoppen widget's description for the editor (design D1 row 2).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlActionGroup',
	group: 'content',
	label: 'Groep knoppen',
	nlds: 'Action Group',
	synonyms: ['knoppen', 'acties', 'keuzes', 'knoppenbalk'],
	fields: [
		{ name: 'buttons', kind: 'json', label: 'Knoppen (tekst, adres, soort)' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 1 },
	scope: 'public',
}
