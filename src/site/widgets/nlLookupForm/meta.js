/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lookup form's description for the editor (site-matches-the-zuiddrecht-boards).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlLookupForm',
	group: 'forms',
	label: 'Opzoekformulier',
	nlds: 'Form Field, Button',
	synonyms: ['postcode', 'huisnummer', 'opzoeken', 'afvalkalender', 'zoekformulier'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Regel boven de velden' },
		{ name: 'fields', kind: 'json', label: 'Velden (name, label, value, width)' },
		{ name: 'buttonLabel', kind: 'string', label: 'Tekst op de knop' },
		{ name: 'href', kind: 'string', label: 'Pagina die antwoord geeft' },
	],
	defaultSize: { gridWidth: 7, gridHeight: 2 },
	scope: 'public',
}
