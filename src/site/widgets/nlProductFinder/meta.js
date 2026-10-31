/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The product finder's description for the editor (public-faq-and-product-finder).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlProductFinder',
	group: 'content',
	label: 'Productzoeker',
	nlds: 'Heading, Button, Link List',
	synonyms: [
		'productzoeker',
		'vergunning',
		'wegwijzer',
		'welke past',
		'ja nee vragen',
	],
	fields: [
		{
			name: 'finder',
			kind: 'string',
			label: 'Productzoeker (leeg: de eerste gepubliceerde)',
		},
	],
	defaultSize: { gridWidth: 12, gridHeight: 6 },
	scope: 'public',
}
