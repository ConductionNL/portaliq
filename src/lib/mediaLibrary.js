/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page designer's Media dialog, without the dialog
 * (site-page-seo-history-and-media T08).
 *
 * It lists the published library items of the page's portal and turns a pick
 * into a media:<id> reference. A page stores the reference, never a copy, so
 * replacing an item's file updates every page that uses it. The content API
 * resolves the reference to the item's public address (CmsReader).
 *
 * The GET is handed in by `src/dialogs/MediaPickerDialog.vue`, so
 * `tests/media-library.spec.mjs` runs this as a plain node script.
 *
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */

const PREFIX = 'media:'

/**
 * The library reader over an injected transport.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get path => Promise<{data}>, the path relative to the Nextcloud root
 * @return {object}
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */
export function createMediaLibrary({ get }) {
	return {
		/**
		 * The portal's published items.
		 *
		 * @param {string} portal The portal slug.
		 * @return {Promise<{state: string, items: Array}>}
		 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
		 */
		async load(portal) {
			if (!portal) {
				return { state: 'empty', items: [] }
			}
			try {
				const query = new URLSearchParams({
					portal,
					status: 'published',
					_limit: '200',
				})
				const { data } = await get(
					'/apps/openregister/api/objects/portaliq/media?'
						+ query.toString(),
				)
				const items = (Array.isArray(data?.results) ? data.results : [])
					.filter(
						(item) =>
							item.portal === portal && item.status === 'published',
					)
					.map((item) => ({
						id: String(item.id ?? item['@self']?.id ?? ''),
						title: item.title || '',
						alt: item.alt || '',
						kind: item.kind || 'file',
					}))
					.filter((item) => item.id !== '')
				return { state: items.length ? 'ready' : 'empty', items }
			} catch {
				return { state: 'error', items: [] }
			}
		},
	}
}

/**
 * The page with an item as its hero image or its share image.
 *
 * @param {object} page The page as the designer loaded it.
 * @param {object} item A library item.
 * @param {'hero'|'share'} target Which image.
 * @return {object} The page to write.
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */
export function withMedia(page, item, target) {
	if (!item || item.kind !== 'image') {
		throw new Error('Only an image can be a page image.')
	}
	const field = target === 'share' ? 'seoImage' : 'heroImage'
	return { ...page, [field]: PREFIX + item.id }
}

/**
 * A markdown reference to an item: an image with its alternative text, a
 * file as a link with its title.
 *
 * @param {object} item A library item.
 * @return {string}
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */
export function markdownReference(item) {
	if (item.kind === 'image') {
		return '![' + (item.alt || '') + '](' + PREFIX + item.id + ')'
	}
	return '[' + (item.title || '') + '](' + PREFIX + item.id + ')'
}
