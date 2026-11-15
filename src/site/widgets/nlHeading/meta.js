/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Kop widget's description for the editor (design D1 row 41, 48).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlHeading',
	group: 'content',
	label: 'Kop',
	nlds: 'Heading',
	synonyms: ['titel', 'kop', 'heading', 'hoofdstuk', 'tussenkop'],
	fields: [
		{ name: 'text', kind: 'string', label: 'Tekst' },
		{ name: 'level', kind: 'number', label: 'Niveau 1 tot 6' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 1 },
	scope: 'public',
}
