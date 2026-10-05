/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Voortgangsbalk widget's description for the editor (design D1 row 72).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlProgressBar',
	group: 'feedback',
	label: 'Voortgangsbalk',
	nlds: 'Progress Bar',
	synonyms: ['voortgang', 'balk', 'percentage', 'hoe ver', 'stand'],
	fields: [
		{ name: 'value', kind: 'number', label: 'Waarde' },
		{ name: 'max', kind: 'number', label: 'Maximum' },
		{ name: 'label', kind: 'string', label: 'Wat de balk toont' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 1 },
	scope: 'public',
}
