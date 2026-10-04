/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Meldingsbalk widget's description for the editor (design D1 row 61).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlBanner',
	group: 'feedback',
	label: 'Meldingsbalk',
	nlds: 'Notification Banner',
	synonyms: ['balk', 'storingsmelding', 'bovenaan', 'onderhoud', 'mededeling'],
	fields: [
		{ name: 'kind', kind: 'string', label: 'Soort: info, ok, warning of error' },
		{ name: 'text', kind: 'text', label: 'Tekst' },
		{ name: 'closable', kind: 'boolean', label: 'Bezoeker kan sluiten' },
	],
	defaultSize: { gridWidth: 12, gridHeight: 1 },
	scope: 'public',
}
