/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Scheidingslijn widget's description for the editor (design D1 row 85).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlSeparator',
	group: 'layout',
	label: 'Scheidingslijn',
	nlds: 'Separator',
	synonyms: ['lijn', 'scheiding', 'streep', 'witruimte'],
	fields: [],
	defaultSize: { gridWidth: 12, gridHeight: 1 },
	scope: 'public',
}
