/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The steps' description for the editor (site-nlds-widget-palette T9).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlSteps',
	group: 'content',
	label: 'Stappen',
	nlds: 'Progress List',
	synonyms: ['zo werkt het', 'stappenplan', 'voortgang', 'steps'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{
			name: 'steps',
			kind: 'json',
			label: 'Stappen (label, state: done, current of upcoming, text)',
		},
		{ name: 'display', kind: 'string', label: 'Weergave: list of bars' },
	],
	defaultSize: { gridWidth: 8, gridHeight: 4 },
	scope: 'public',
}
