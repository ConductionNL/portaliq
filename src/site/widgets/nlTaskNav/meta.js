/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Stappen om te doen widget's description for the editor (design D1 row 95).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlTaskNav',
	group: 'nav',
	label: 'Stappen om te doen',
	nlds: 'Task Navigation',
	synonyms: ['stappen', 'taken', 'voortgang', 'wat moet ik nog doen', 'checklist'],
	fields: [
		{ name: 'items', kind: 'json', label: 'Stappen (tekst, adres, staat)' },
	],
	defaultSize: { gridWidth: 4, gridHeight: 3 },
	scope: 'public',
}
