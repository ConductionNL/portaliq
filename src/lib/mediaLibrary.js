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
 * The library's address for a portal's published items.
 *
 * @param {string} portal The portal slug.
 * @return {string} The path relative to the Nextcloud root.
 */
function mediaPath(portal) {
	const query = new URLSearchParams({ portal, status: 'published', _limit: '200' })
	return '/apps/openregister/api/objects/portaliq/media?' + query.toString()
}

/**
 * An item as the dialog lists it.
 *
 * @param {object} item The stored item.
 * @return {{id: string, title: string, alt: string, kind: string}} The listed item.
 */
function listedItem(item) {
	return {
		id: String(item.id ?? item['@self']?.id ?? ''),
		title: item.title || '',
		alt: item.alt || '',
		kind: item.kind || 'file',
	}
}

/**
 * The portal's published items with an id, from the library's answer.
 *
 * @param {object} data The answer's data.
 * @param {string} portal The portal slug.
 * @return {Array} The listed items.
 */
function publishedItems(data, portal) {
	return (Array.isArray(data?.results) ? data.results : [])
		.filter((item) => item.portal === portal && item.status === 'published')
		.map(listedItem)
		.filter((item) => item.id !== '')
}

/**
 * The library's address for one item's files.
 *
 * @param {string} id The item id.
 * @return {string} The path relative to the Nextcloud root.
 */
function filesPath(id) {
	return (
		'/apps/openregister/api/objects/portaliq/media/'
		+ encodeURIComponent(id)
		+ '/files'
	)
}

/**
 * The name of the file an item serves: the newest one, as the public media
 * route streams the newest (lib/Service/Cms/MediaFile.php).
 *
 * @param {object} data The files answer's data.
 * @return {string} The file name, or '' when the item has no file.
 * @spec openspec/specs/admin-dialogs-board-look/spec.md#requirement-the-media-picker-draws-the-library-as-cards
 */
export function newestFileName(data) {
	const files = Array.isArray(data?.results) ? data.results : []
	let newest = null
	for (const file of files) {
		if (newest === null || Number(file?.id) > Number(newest?.id)) {
			newest = file
		}
	}
	return String(newest?.title || newest?.name || '')
}

/**
 * The address of an image's thumbnail: the public media route of its portal,
 * which serves a published item only.
 *
 * @param {object} item A listed item.
 * @param {string} portal The portal slug.
 * @return {string} The path relative to the Nextcloud root, or '' for a file.
 * @spec openspec/specs/admin-dialogs-board-look/spec.md#requirement-the-media-picker-draws-the-library-as-cards
 */
export function thumbnailPath(item, portal) {
	if (!item || item.kind !== 'image' || !item.id) {
		return ''
	}
	return (
		'/apps/portaliq/api/content/media/'
		+ encodeURIComponent(item.id)
		+ '?'
		+ new URLSearchParams({ portal }).toString()
	)
}

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
				const { data } = await get(mediaPath(portal))
				const items = publishedItems(data, portal)
				return { state: items.length ? 'ready' : 'empty', items }
			} catch {
				return { state: 'error', items: [] }
			}
		},

		/**
		 * The name of the file an item serves; '' when it has none or the
		 * read fails, so a card still shows its title and alt text.
		 *
		 * @param {string} id The item id.
		 * @return {Promise<string>}
		 * @spec openspec/specs/admin-dialogs-board-look/spec.md#requirement-the-media-picker-draws-the-library-as-cards
		 */
		async fileName(id) {
			try {
				const { data } = await get(filesPath(id))
				return newestFileName(data)
			} catch {
				return ''
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
