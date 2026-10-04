/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Inloggen widget's description for the editor (design D1 row 55).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlSignIn',
	group: 'nav',
	label: 'Inloggen',
	nlds: 'Login Link',
	synonyms: ['inloggen', 'aanmelden', 'digid', 'eherkenning', 'mijn omgeving'],
	fields: [{ name: 'heading', kind: 'string', label: 'Kop boven de knoppen' }],
	defaultSize: { gridWidth: 4, gridHeight: 2 },
	scope: 'public',
}
