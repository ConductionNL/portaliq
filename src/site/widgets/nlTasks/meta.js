/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task list's description for the editor (site-nlds-widget-palette T9).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlTasks',
	group: 'mijn',
	label: 'Mijn taken',
	nlds: 'Task List',
	synonyms: ['taken', 'te doen', 'actie nodig', 'tasks'],
	fields: [
		{ name: 'label', kind: 'string', label: 'Kop' },
		{
			name: 'app',
			kind: 'string',
			label: 'App (leeg: de taken van het portaal)',
		},
		{ name: 'collection', kind: 'string', label: 'Collectie van de app' },
		{ name: 'dueField', kind: 'string', label: 'Veld met de uiterste datum' },
		{ name: 'limit', kind: 'number', label: 'Aantal' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 4 },
	scope: 'signedIn',
}
