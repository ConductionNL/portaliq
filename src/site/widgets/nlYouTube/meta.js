/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The YouTube-video widget's description for the editor (design D1 row 101).
 *
 * NO IMPORTS: the editor reads every meta to build the palette.
 */

/**
 * @type {import('../index.js').SiteWidgetMeta}
 */
export const metaOf = {
	key: 'nlYouTube',
	group: 'content',
	label: 'YouTube-video',
	nlds: 'YouTube Video',
	synonyms: ['youtube', 'video', 'film', 'insluiten'],
	fields: [
		{ name: 'videoId', kind: 'string', label: 'YouTube-id' },
		{ name: 'caption', kind: 'string', label: 'Onderschrift' },
	],
	defaultSize: { gridWidth: 6, gridHeight: 4 },
	scope: 'public',
}
