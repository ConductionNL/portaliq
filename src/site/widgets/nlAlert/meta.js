/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Melding widget's description for the editor (design D1 row 3).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlAlert',
	group: 'feedback',
	label: 'Melding',
	nlds: 'Alert',
	synonyms: ['melding', 'waarschuwing', 'let op', 'fout', 'gelukt', 'attentie'],
	fields: [
		{
			name: 'kind',
			kind: 'string',
			label: 'Soort: info, ok, warning, error of plain',
		},
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{ name: 'text', kind: 'text', label: 'Tekst (**vet** mag)' },
		{ name: 'action', kind: 'json', label: 'Knop: {label, href}' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 2 },
	scope: 'public',
}
