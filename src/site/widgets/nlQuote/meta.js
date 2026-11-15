/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Citaat widget's description for the editor (design D1 row 6).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlQuote',
	group: 'content',
	label: 'Citaat',
	nlds: 'Blockquote',
	synonyms: ['citaat', 'quote', 'aanhaling', 'uitspraak'],
	fields: [
		{ name: 'text', kind: 'text', label: 'Citaat' },
		{ name: 'source', kind: 'string', label: 'Van wie' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 2 },
	scope: 'public',
}
