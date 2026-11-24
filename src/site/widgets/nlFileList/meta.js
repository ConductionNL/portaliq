/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The file list's description for the editor (site-nlds-widget-palette T9).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlFileList',
	group: 'content',
	label: 'Bestanden',
	nlds: 'File',
	synonyms: ['downloads', 'documenten', 'bijlagen', 'files'],
	fields: [
		{ name: 'heading', kind: 'string', label: 'Kop' },
		{ name: 'files', kind: 'json', label: 'Bestanden (label, href, line)' },
	],
	defaultSize: { gridWidth: 8, gridHeight: 3 },
	scope: 'public',
}
