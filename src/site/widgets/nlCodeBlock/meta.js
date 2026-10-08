/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Codeblok widget's description for the editor (design D1 row 15).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlCodeBlock',
	group: 'content',
	label: 'Codeblok',
	nlds: 'Code Block',
	synonyms: ['code', 'codeblok', 'voorbeeld', 'script'],
	fields: [
		{ name: 'code', kind: 'text', label: 'Code' },
		{ name: 'label', kind: 'string', label: 'Wat het voorbeeld toont' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 3 },
	scope: 'public',
}
