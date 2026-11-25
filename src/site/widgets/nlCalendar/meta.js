/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The calendar's description for the editor (site-nlds-widget-palette T9).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlCalendar',
	group: 'mijn',
	label: 'Agenda',
	nlds: 'Calendar',
	synonyms: ['kalender', 'planning', 'afspraken', 'binnenkort', 'calendar'],
	fields: [
		{ name: 'label', kind: 'string', label: 'Kop' },
		{ name: 'app', kind: 'string', label: 'App' },
		{ name: 'page', kind: 'string', label: 'Pagina van de app met de agenda' },
		{ name: 'range', kind: 'string', label: 'Periode: day, week of month' },
		{ name: 'limit', kind: 'number', label: 'Aantal' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 3 },
	scope: 'signedIn',
}
