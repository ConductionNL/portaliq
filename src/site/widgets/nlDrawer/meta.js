/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Zijpaneel widget's description for the editor (design D1 row 27).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlDrawer',
	group: 'feedback',
	label: 'Zijpaneel',
	nlds: 'Drawer',
	synonyms: ['zijpaneel', 'lade', 'uitschuiven', 'paneel', 'zijkant'],
	fields: [
		{ name: 'buttonLabel', kind: 'string', label: 'Tekst op de knop' },
		{ name: 'heading', kind: 'string', label: 'Kop in het paneel' },
		{ name: 'text', kind: 'text', label: 'Tekst' },
	],
	defaultSize: { gridWidth: 4, gridHeight: 1 },
	scope: 'public',
}
