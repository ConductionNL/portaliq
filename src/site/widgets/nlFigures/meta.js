/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The figures' description for the editor (site-nlds-widget-palette T9).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlFigures',
	group: 'mijn',
	label: 'Cijfers',
	nlds: 'Data Summary',
	synonyms: ['kengetallen', 'overzicht', 'aantallen', 'kpi', 'figures'],
	fields: [
		{ name: 'label', kind: 'string', label: 'Kop' },
		{ name: 'app', kind: 'string', label: 'App' },
		{ name: 'page', kind: 'string', label: 'Pagina van de app met de cijfers' },
		{ name: 'items', kind: 'json', label: 'Of eigen cijfers (value, label)' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 2 },
	scope: 'signedIn',
}
