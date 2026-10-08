/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Inloggen widget's description for the editor (design D1 row 55,
 * site-school-blocks for the card).
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
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop boven de knoppen' },
		{ name: 'display', kind: 'string', label: 'Weergave: buttons of card' },
		{ name: 'intro', kind: 'text', label: 'Tekst op de kaart' },
		{ name: 'points', kind: 'json', label: 'Punten op de kaart' },
		{ name: 'buttonLabel', kind: 'string', label: 'Tekst op de knop' },
		{ name: 'note', kind: 'string', label: 'Regel onder de knop' },
		{ name: 'tone', kind: 'string', label: 'Kleur: inverse, light of outline' },
	],
	defaultSize: { gridWidth: 4, gridHeight: 2 },
	scope: 'public',
}
