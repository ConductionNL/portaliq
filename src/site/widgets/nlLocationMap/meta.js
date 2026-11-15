/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The location map widget's description for the editor: the places of an
 * organisation as a numbered list beside a sketch map (board LocatiesKaart).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlLocationMap',
	group: 'content',
	label: 'Locaties op een kaart',
	nlds: 'Heading, Link List',
	synonyms: ['kaart', 'locaties', 'adressen', 'vestigingen', 'gebouwen', 'openingstijden', 'route'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop boven de lijst, {count} wordt het aantal' },
		{ name: 'intro', kind: 'text', label: 'Regel boven de kaart' },
		{
			name: 'locations',
			kind: 'json',
			label: 'Locaties (naam, adres, openingstijden, soort, breedtegraad, lengtegraad, links)',
		},
		{ name: 'allLabel', kind: 'string', label: 'Naam van het filter op alles' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 6 },
	scope: 'public',
}
