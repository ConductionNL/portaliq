/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The catalogue block's description for the editor (portal-public-catalogue).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlCatalogue',
	group: 'content',
	label: 'Zoeken in het aanbod',
	nlds: 'Text Input, Checkbox Group, Select, Card as Link, Page Number Navigation',
	synonyms: [
		'zoeken',
		'aanbod',
		'cursussen',
		'opleidingen',
		'nieuws en documenten',
		'filters',
		'catalogus',
	],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{ name: 'intro', kind: 'string', label: 'Regel onder de kop' },
		{
			name: 'types',
			kind: 'json',
			label: 'Soorten (news, course, programme, event); leeg is alles',
		},
		{ name: 'display', kind: 'string', label: 'Weergave: cards of dated' },
		{
			name: 'pageSize',
			kind: 'number',
			label: 'Resultaten per pagina (5 tot 20)',
		},
		{
			name: 'sort',
			kind: 'string',
			label: 'Sortering: relevance, date, dateDesc of title',
		},
		{
			name: 'countLabel',
			kind: 'string',
			label: 'Aantal zonder zoekwoord, zoals {count} cursussen',
		},
		{ name: 'searchLabel', kind: 'string', label: 'Label van het zoekveld' },
		{ name: 'placeholder', kind: 'string', label: 'Voorbeeld in het zoekveld' },
		{ name: 'showSearch', kind: 'boolean', label: 'Zoekveld tonen' },
		{ name: 'newsRoute', kind: 'string', label: 'Adres van de nieuwspagina' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 8 },
	scope: 'public',
}
