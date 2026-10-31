/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The start tiles' description for the editor (site-nlds-widget-palette T10).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlStartTiles',
	group: 'nav',
	label: 'Starttegels',
	nlds: 'Link List, Card',
	synonyms: ['regelen', 'aanvragen', 'tegels', 'direct regelen', 'start', 'tiles'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{ name: 'columns', kind: 'number', label: 'Kolommen (2, 3 of 4)' },
		{ name: 'moreLabel', kind: 'string', label: 'Tekst van de link eronder' },
		{ name: 'moreHref', kind: 'string', label: 'Adres van de link eronder' },
		{ name: 'overlap', kind: 'boolean', label: 'Over de band erboven schuiven' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 3 },
	scope: 'public',
}
