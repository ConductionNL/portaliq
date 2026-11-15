/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The news article's description for the editor (site-school-blocks).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlNewsArticle',
	group: 'content',
	label: 'Nieuwsbericht',
	nlds: 'Heading, Paragraph, Image',
	synonyms: ['nieuwsbericht', 'artikel', 'bericht', 'nieuws lezen'],
	fields: [
		{ name: 'kindLabel', kind: 'string', label: 'Label boven de titel' },
		{ name: 'backLabel', kind: 'string', label: 'Tekst van de link terug' },
		{ name: 'backHref', kind: 'string', label: 'Adres van de link terug' },
		{
			name: 'areaLabel',
			kind: 'string',
			label: 'Naam van de eigen omgeving, bijvoorbeeld Mijn Vaartveld',
		},
		{
			name: 'signUpLabel',
			kind: 'string',
			label: 'Tekst van de aanmeldknop voor wie is ingelogd',
		},
		{
			name: 'sectionHref',
			kind: 'string',
			label: 'Pagina waar het bericht onder valt, zoals /zoeken',
		},
		{ name: 'sectionLabel', kind: 'string', label: 'Woorden van die kruimel' },
	],
	defaultSize: { gridWidth: 8, gridHeight: 6 },
	scope: 'public',
}
