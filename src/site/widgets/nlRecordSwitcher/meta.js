/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The record switcher's description for the editor (site-nlds-widget-palette T9).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlRecordSwitcher',
	group: 'mijn',
	label: 'Kies voor wie',
	nlds: 'Radio Group, Avatar',
	synonyms: ['wisselen', 'kinderen', 'voor wie', 'switcher'],
	fields: [
		{ name: 'label', kind: 'string', label: 'Vraag' },
		{ name: 'app', kind: 'string', label: 'App' },
		{ name: 'collection', kind: 'string', label: 'Collectie van de app' },
		{ name: 'titleFields', kind: 'json', label: 'Velden van de naam' },
		{ name: 'subtitleFields', kind: 'json', label: 'Velden eronder' },
		{ name: 'route', kind: 'string', label: 'Pagina die een keuze opent' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 2 },
	scope: 'signedIn',
}
