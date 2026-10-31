/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The subject page's description for the editor (home-and-theme-landing-pages).
 * Placed on the page the subject addresses hang under (`/onderwerp` for
 * `/onderwerp/<slug>`): the slug comes from the route and the content from
 * the publication catalogue.
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlSubjectLanding',
	group: 'content',
	label: 'Pagina van een onderwerp',
	nlds: 'Heading, Image, Paragraph',
	synonyms: ['onderwerp', 'thema', 'landingspagina', 'publicaties'],
	fields: [
		{ name: 'backLabel', kind: 'string', label: 'Tekst van de link terug' },
		{ name: 'backHref', kind: 'string', label: 'Adres van de link terug' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 10 },
	scope: 'public',
}
