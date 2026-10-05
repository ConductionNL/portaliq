/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Tabbladen widget's description for the editor (design D1 row 93).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlTabs',
	group: 'layout',
	label: 'Tabbladen',
	nlds: 'Tabs',
	synonyms: ['tabs', 'tabbladen', 'onderdelen', 'secties', 'naast elkaar'],
	fields: [{ name: 'tabs', kind: 'json', label: 'Tabbladen (titel, tekst)' }],
	defaultSize: { gridWidth: 12, gridHeight: 4 },
	scope: 'public',
}
