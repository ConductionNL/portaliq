/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Uitleg bij een woord widget's description for the editor (design D1 row 98).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlToggletip',
	group: 'content',
	label: 'Uitleg bij een woord',
	nlds: 'Toggletip',
	synonyms: ['uitleg', 'toelichting', 'wat betekent dit', 'vraagteken', 'begrip'],
	fields: [
		{ name: 'term', kind: 'string', label: 'Het woord' },
		{ name: 'explanation', kind: 'text', label: 'De uitleg' },
	],
	defaultSize: { gridWidth: 4, gridHeight: 1 },
	scope: 'public',
}
