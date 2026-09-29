/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page designer's History dialog, without the dialog
 * (site-page-seo-history-and-media T04, T05).
 *
 * It reads a page's published versions from portaliq's history endpoint and
 * turns a chosen version into the page object the designer saves as a draft.
 * A restore writes `draftBody` only: the live page keeps its `body` until the
 * editor publishes, the same rule as any draft save.
 *
 * The GET and the URL generator are handed in by
 * `src/dialogs/PageHistoryDialog.vue`, so `tests/page-history.spec.mjs` runs
 * this as a plain node script.
 *
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */

/**
 * The history reader over an injected transport.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get url => Promise<{data}>
 * @param {Function} deps.url (path, params) => string, path relative to the app
 * @return {object}
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */
export function createPageHistory({ get, url }) {
	return {
		/**
		 * The page's published versions.
		 *
		 * @param {string} pageId The page object's id.
		 * @return {Promise<{state: string, versions: Array, reason?: string}>}
		 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
		 */
		async load(pageId) {
			try {
				const { data } = await get(url('/api/pages/{id}/history', { id: pageId }))
				const versions = Array.isArray(data?.versions) ? data.versions : []
				return { state: versions.length ? 'ready' : 'empty', versions }
			} catch (error) {
				return {
					state: 'error',
					versions: [],
					reason: error?.response?.data?.error || 'unavailable',
				}
			}
		},
	}
}

/**
 * The page object to save when restoring a version: the version's body as
 * the draft, everything else as loaded.
 *
 * @param {object} page The page as the designer loaded it.
 * @param {object} version A version from the history.
 * @return {object} The page to write.
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */
export function restoredDraft(page, version) {
	if (!version || version.restorable !== true || !version.body) {
		throw new Error('This version cannot be restored.')
	}
	return { ...page, draftBody: version.body }
}
