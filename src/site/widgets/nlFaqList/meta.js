/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The FAQ list's description for the editor (public-faq-and-product-finder).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlFaqList',
	group: 'content',
	label: 'Veelgestelde vragen',
	nlds: 'Accordion, Heading, Link',
	synonyms: ['faq', 'vragen', 'veelgestelde vragen', 'antwoorden', 'help'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{
			name: 'topic',
			kind: 'string',
			label: 'Onderwerp (leeg: de vragen van deze pagina)',
		},
		{
			name: 'all',
			kind: 'boolean',
			label: 'Alle vragen, per onderwerp gegroepeerd',
		},
		{
			name: 'moreLabel',
			kind: 'string',
			label: 'Tekst van de link naar alle vragen',
		},
		{ name: 'moreHref', kind: 'string', label: 'Adres van die link' },
	],
	defaultSize: { gridWidth: 8, gridHeight: 4 },
	scope: 'public',
}
