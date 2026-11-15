/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Video widget's description for the editor (design D1 row 100).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlVideo',
	group: 'content',
	label: 'Video',
	nlds: 'Video',
	synonyms: ['video', 'film', 'opname', 'bewegend beeld'],
	fields: [
		{ name: 'src', kind: 'string', label: 'Adres van de video' },
		{ name: 'caption', kind: 'string', label: 'Onderschrift' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 4 },
	scope: 'public',
}
