/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Knop widget's description for the editor (design D1 row 8).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlButtonLink',
	group: 'content',
	label: 'Knop',
	nlds: 'Button',
	synonyms: ['knop', 'button', 'actie', 'link als knop', 'doorgaan'],
	fields: [
		{ name: 'label', kind: 'string', label: 'Tekst op de knop' },
		{ name: 'href', kind: 'string', label: 'Adres' },
		{
			name: 'kind',
			kind: 'string',
			label: 'Soort: primary, secondary of subtle',
		},
	],
	defaultSize: { gridWidth: 3, gridHeight: 1 },
	scope: 'public',
}
