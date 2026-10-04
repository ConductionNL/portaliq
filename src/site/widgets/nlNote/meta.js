/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Kanttekening widget's description for the editor (design D1 row 60).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlNote',
	group: 'feedback',
	label: 'Kanttekening',
	nlds: 'Note',
	synonyms: ['opmerking', 'kanttekening', 'tip', 'ter info', 'notitie'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{ name: 'text', kind: 'text', label: 'Tekst' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 2 },
	scope: 'public',
}
