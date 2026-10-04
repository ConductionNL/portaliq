/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Taalkeuze widget's description for the editor (design D1 row 52).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlLanguageNav',
	group: 'nav',
	label: 'Taalkeuze',
	nlds: 'Language Navigation',
	synonyms: ['taal', 'talen', 'language', 'nederlands', 'engels', 'taalwissel'],
	fields: [],
	defaultSize: { gridWidth: 3, gridHeight: 1 },
	scope: 'public',
}
