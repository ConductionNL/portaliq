/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The link columns' description for the editor (site-matches-the-zuiddrecht-boards).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlLinkColumns',
	group: 'nav',
	label: 'Kolommen met links',
	nlds: 'Link List, Heading',
	synonyms: ['kolommen', 'bestuur', 'organisatie', 'band', 'linkgroepen'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop boven de kolommen' },
		{ name: 'columns', kind: 'json', label: 'Kolommen (title, links)' },
		{ name: 'tone', kind: 'string', label: 'Ondergrond: surface of plain' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 4 },
	scope: 'public',
}
