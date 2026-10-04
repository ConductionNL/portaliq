/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Tabel widget's description for the editor (design D1 row 96).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlTable',
	group: 'content',
	label: 'Tabel',
	nlds: 'Table',
	synonyms: ['tabel', 'overzicht', 'rijen', 'kolommen', 'gegevens'],
	fields: [
		{ name: 'caption', kind: 'string', label: 'Wat de tabel toont' },
		{ name: 'columns', kind: 'json', label: 'Kolomkoppen' },
		{ name: 'rows', kind: 'json', label: 'Rijen' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 4 },
	scope: 'public',
}
