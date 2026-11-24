/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The newest messages' description for the editor (site-nlds-widget-palette T9).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlInbox',
	group: 'mijn',
	label: 'Nieuwste berichten',
	nlds: 'Task List, Data Badge',
	synonyms: ['berichten', 'inbox', 'post', 'messages'],
	fields: [
		{ name: 'label', kind: 'string', label: 'Kop' },
		{ name: 'app', kind: 'string', label: 'App (leeg: alle berichten)' },
		{ name: 'collection', kind: 'string', label: 'Collectie van de app' },
		{ name: 'limit', kind: 'number', label: 'Aantal' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 4 },
	scope: 'signedIn',
}
