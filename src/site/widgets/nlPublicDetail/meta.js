/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The provider item page's description for the editor
 * (public-detail-page-for-a-provider-item). Placed on the page that the
 * item's address hangs under (`/cursusaanbod` for `/cursusaanbod/<slug>`):
 * the slug comes from the route and the content from the app.
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlPublicDetail',
	group: 'content',
	label: 'Pagina van een item uit een app',
	nlds: 'Description List, Heading, Button',
	synonyms: ['cursus', 'opleiding', 'detail', 'item', 'app', 'inschrijven', 'aanbod'],
	fields: [
		{ name: 'app', kind: 'string', label: 'Uit welke app, bijvoorbeeld learniq' },
		{ name: 'kind', kind: 'string', label: 'Welk soort item, bijvoorbeeld course' },
		{ name: 'backLabel', kind: 'string', label: 'Tekst van de link terug' },
		{ name: 'backHref', kind: 'string', label: 'Adres van de link terug' },
		{ name: 'areaLabel', kind: 'string', label: 'Naam van de eigen omgeving, bijvoorbeeld Mijn Academie' },
	],
	defaultSize: { gridWidth: 8, gridHeight: 8 },
	scope: 'public',
}
