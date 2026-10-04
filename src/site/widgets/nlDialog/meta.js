/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Venster widget's description for the editor (design D1 row 25, 4, 58).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlDialog',
	group: 'feedback',
	label: 'Venster',
	nlds: 'Dialog',
	synonyms: [
		'venster',
		'pop-up',
		'dialoog',
		'modal',
		'overlay',
		'melding met knop',
	],
	fields: [
		{ name: 'buttonLabel', kind: 'string', label: 'Tekst op de knop' },
		{ name: 'heading', kind: 'string', label: 'Kop in het venster' },
		{ name: 'text', kind: 'text', label: 'Tekst' },
		{ name: 'variant', kind: 'string', label: 'Soort: dialog, modal of alert' },
	],
	defaultSize: { gridWidth: 4, gridHeight: 1 },
	scope: 'public',
}
