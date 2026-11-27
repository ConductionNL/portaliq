/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact timeline's description for the editor (site-nlds-widget-palette T9).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlTimeline',
	group: 'mijn',
	label: 'Wat er is gebeurd',
	nlds: 'Contact Timeline',
	synonyms: ['tijdlijn', 'geschiedenis', 'verloop', 'timeline'],
	fields: [
		{ name: 'label', kind: 'string', label: 'Kop' },
		{ name: 'app', kind: 'string', label: 'App' },
		{ name: 'collection', kind: 'string', label: 'Collectie van de app' },
	],
	defaultSize: { gridWidth: 8, gridHeight: 5 },
	scope: 'signedIn',
}
