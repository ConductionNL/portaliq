/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Tekst widget's description for the editor (design D1 row 69).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlParagraph',
	group: 'content',
	label: 'Tekst',
	nlds: 'Paragraph',
	synonyms: ['tekst', 'alinea', 'paragraaf', 'uitleg', 'broodtekst'],
	fields: [
		{ name: 'text', kind: 'text', label: 'Tekst' },
		{ name: 'lead', kind: 'boolean', label: 'Groter, als inleiding' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 2 },
	scope: 'public',
}
