/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The featured subjects' description for the editor
 * (home-and-theme-landing-pages). The subjects an administrator features in
 * the publication catalogue, each linking to its own page.
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlFeaturedSubjects',
	group: 'content',
	label: 'Uitgelichte onderwerpen',
	nlds: 'Card as Link, Heading, Image',
	synonyms: ['onderwerpen', 'thema', 'thema\'s', 'uitgelicht', 'publicaties', 'home'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{ name: 'count', kind: 'number', label: 'Hoeveel onderwerpen (standaard zes)' },
		{ name: 'subjectRoute', kind: 'string', label: 'Pagina van een onderwerp (standaard /onderwerp)' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 4 },
	scope: 'public',
}
