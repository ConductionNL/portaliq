/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public news list's description for the editor (site-school-blocks).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlNewsList',
	group: 'content',
	label: 'Nieuws',
	nlds: 'Link List, Card, Image',
	synonyms: ['nieuws', 'berichten', 'actueel', 'laatste nieuws', 'gerelateerd'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{ name: 'limit', kind: 'number', label: 'Aantal berichten (1 tot 12)' },
		{
			name: 'featured',
			kind: 'boolean',
			label: 'Nieuwste bericht groot, met foto',
		},
		{ name: 'display', kind: 'string', label: 'Weergave: list of compact' },
		{
			name: 'leadPlaceholder',
			kind: 'string',
			label: 'Tekst op de plek van de foto zolang die er niet is',
		},
		{
			name: 'showAudience',
			kind: 'boolean',
			label: 'Laat zien voor wie het is',
		},
		{
			name: 'moreLabel',
			kind: 'string',
			label: 'Tekst van de link naar al het nieuws',
		},
		{ name: 'moreHref', kind: 'string', label: 'Adres van die link' },
		{
			name: 'articleRoute',
			kind: 'string',
			label: 'Pagina waar een bericht opent',
		},
	],
	defaultSize: { gridWidth: 8, gridHeight: 4 },
	scope: 'public',
}
