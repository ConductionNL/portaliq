/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Opsomming widget's description for the editor (design D1 row 99, 63).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlList',
	group: 'content',
	label: 'Opsomming',
	nlds: 'Unordered List',
	synonyms: ['lijst', 'opsomming', 'bullets', 'stappen', 'punten'],
	fields: [
		{ name: 'items', kind: 'json', label: 'Regels' },
		{ name: 'ordered', kind: 'boolean', label: 'Genummerd' },
		{ name: 'display', kind: 'string', label: 'Weergave: list of steps' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 2 },
	scope: 'public',
}
