/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Uitklapbare tekst widget's description for the editor (design D1 row 1).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlAccordion',
	group: 'layout',
	label: 'Uitklapbare tekst',
	nlds: 'Accordion',
	synonyms: [
		'uitklappen',
		'vragen',
		'veelgestelde vragen',
		'faq',
		'accordeon',
		'inklappen',
	],
	fields: [
		{ name: 'items', kind: 'json', label: 'Onderdelen (titel, tekst)' },
		{ name: 'headingLevel', kind: 'number', label: 'Niveau van de titels' },
		{ name: 'firstOpen', kind: 'boolean', label: 'Eerste staat open' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 4 },
	scope: 'public',
}
