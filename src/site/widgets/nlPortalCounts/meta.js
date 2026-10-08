/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The live counts' description for the editor (home-and-theme-landing-pages).
 * Totals of what the portal publishes, each linking into the search.
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlPortalCounts',
	group: 'content',
	label: 'Wat we publiceren, in aantallen',
	nlds: 'Link List, Heading',
	synonyms: ['aantallen', 'tellers', 'cijfers', 'publicaties', 'statistiek', 'home'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{ name: 'by', kind: 'string', label: 'Per wat: category, subject of year' },
		{ name: 'searchRoute', kind: 'string', label: 'Zoekpagina (standaard /zoeken)' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 3 },
	scope: 'public',
}
