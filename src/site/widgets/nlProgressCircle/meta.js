/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Voortgangscirkel widget's description for the editor (design D1 row 73).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlProgressCircle',
	group: 'feedback',
	label: 'Voortgangscirkel',
	nlds: 'Progress Circle',
	synonyms: ['voortgang', 'cirkel', 'percentage', 'hoe ver', 'ring'],
	fields: [
		{ name: 'value', kind: 'number', label: 'Waarde' },
		{ name: 'max', kind: 'number', label: 'Maximum' },
		{ name: 'label', kind: 'string', label: 'Wat de cirkel toont' },
	],
	defaultSize: { gridWidth: 3, gridHeight: 3 },
	scope: 'public',
}
