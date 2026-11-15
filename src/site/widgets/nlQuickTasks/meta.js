/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task tiles' description for the editor (site-school-blocks).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlQuickTasks',
	group: 'nav',
	label: 'Direct regelen',
	nlds: 'Link List, Card',
	synonyms: ['taken', 'snel naar', 'regelen', 'tegels', 'veelgevraagd', 'tiles'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{
			name: 'items',
			kind: 'json',
			label: 'Taken (label, href, icon of iconPath)',
		},
		{ name: 'columns', kind: 'number', label: 'Kolommen (2, 3 of 4)' },
		{ name: 'moreLabel', kind: 'string', label: 'Tekst van de link eronder' },
		{ name: 'moreHref', kind: 'string', label: 'Adres van de link eronder' },
		{ name: 'overlap', kind: 'boolean', label: 'Over de band erboven schuiven' },
		{ name: 'iconStyle', kind: 'string', label: 'Icoon: circle of plain' },
		{ name: 'narrow', kind: 'string', label: 'Op een telefoon: card of list' },
		{
			name: 'narrowHeading',
			kind: 'string',
			label: 'Kop op een telefoon (lijst)',
		},
		{
			name: 'narrowLimit',
			kind: 'number',
			label: 'Aantal rijen op een telefoon (lijst)',
		},
	],
	defaultSize: { gridWidth: 12, gridHeight: 3 },
	scope: 'public',
}
