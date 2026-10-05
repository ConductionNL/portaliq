/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Link widget's description for the editor (design D1 row 53).
 *
 * NO IMPORTS, on purpose: the editor reads every meta to build the palette,
 * and a meta that imported its component would pull every widget and its CSS
 * into the editor bundle, which is the cost this split exists to avoid.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlLink',
	group: 'content',
	label: 'Link',
	nlds: 'Link',
	synonyms: ['hyperlink', 'verwijzing', 'koppeling', 'anchor'],
	fields: [
		{ name: 'label', kind: 'string', label: 'Tekst' },
		{ name: 'href', kind: 'string', label: 'Adres' },
	],
	defaultSize: { gridWidth: 3, gridHeight: 1 },
	scope: 'public',
}
