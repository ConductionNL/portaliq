/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Gegevens op een rij widget's description for the editor (design D1 row 24).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlDescriptionList',
	group: 'content',
	label: 'Gegevens op een rij',
	nlds: 'Description List',
	synonyms: ['gegevens', 'kenmerken', 'overzicht', 'in het kort', 'feiten'],
	fields: [{ name: 'items', kind: 'json', label: 'Regels (term, uitleg)' }],
	defaultSize: { gridWidth: 6, gridHeight: 3 },
	scope: 'public',
}
