/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Afbeelding widget's description for the editor (design D1 row 50, 29).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlImage',
	group: 'content',
	label: 'Afbeelding',
	nlds: 'Image',
	synonyms: ['afbeelding', 'foto', 'plaatje', 'beeld', 'illustratie'],
	fields: [
		{ name: 'src', kind: 'string', label: 'Adres van de afbeelding' },
		{ name: 'alt', kind: 'string', label: 'Wat er te zien is' },
		{ name: 'caption', kind: 'string', label: 'Onderschrift' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 4 },
	scope: 'public',
}
