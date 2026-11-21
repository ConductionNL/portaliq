/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list's description for the editor (site-nlds-widget-palette T9).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlCases',
	group: 'mijn',
	label: 'Mijn zaken',
	nlds: 'Case Card',
	synonyms: ['zaken', 'aanvragen', 'lopende zaken', 'cases'],
	fields: [
		{ name: 'label', kind: 'string', label: 'Kop' },
		{ name: 'app', kind: 'string', label: 'App' },
		{ name: 'collection', kind: 'string', label: 'Collectie van de app' },
		{ name: 'openOnly', kind: 'boolean', label: 'Alleen lopende zaken' },
		{ name: 'limit', kind: 'number', label: 'Aantal' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 4 },
	scope: 'signedIn',
}
