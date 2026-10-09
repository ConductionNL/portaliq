/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public table's description for the editor
 * (editor-blocks-read-public-app-data). It reads one kind of an app's public
 * index; the kinds, filters and columns on offer are what the app declares
 * (`/api/content/catalogue/kinds`), not a list here.
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlPublicTable',
	group: 'content',
	label: 'Tabel uit een app',
	nlds: 'Table',
	synonyms: ['tabel', 'rooster', 'toetsrooster', 'overzicht', 'app', 'gegevens'],
	fields: [
		{ name: 'caption', kind: 'string', label: 'Wat de tabel toont' },
		{
			name: 'source',
			kind: 'json',
			label: 'Uit welke app: {"app": "learniq", "kind": "test", "filters": {"Afdeling": ["4 havo"]}}. De waarde "visitor" is de klas van de bezoeker.',
		},
		{
			name: 'columns',
			kind: 'json',
			label: 'Kolommen, uit wat de app aanbiedt: [{"key": "day", "label": "Dag"}]',
		},
		{ name: 'display', kind: 'string', label: 'Weergave: plain of boxed' },
		{ name: 'emptyLabel', kind: 'string', label: 'Tekst als er niets is' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 4 },
	scope: 'public',
}
