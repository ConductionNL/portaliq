/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Load and save a page object, without overwriting someone else's save.
 *
 * THE MARKER IS `@self.updated`, the timestamp OpenRegister moves on every
 * write. A save does two things with it:
 *
 * 1. It re-reads the page first. When the marker moved, the save is refused
 *    before anything is written, and the editor can say who saved and when.
 * 2. It sends the marker as `If-Match`. OpenRegister's
 *    `ObjectsController::versionConflictResponse()` answers 409 when the
 *    object changed between the re-read and the write, which closes the gap
 *    the re-read leaves open.
 *
 * The HTTP calls are handed in (`@nextcloud/axios` in the app, a fake in
 * tests/page-editor.spec.mjs), so this module runs anywhere.
 *
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-a-save-must-not-overwrite-a-newer-save-by-someone-else-req-pie-005
 */

import { versionOf, withoutEnvelope } from './pageBody.js'

/**
 * The error a refused save throws: someone else saved in between.
 */
export class PageConflictError extends Error {
	/**
	 * @param {string} changedAt When the other save happened, when known.
	 */
	constructor(changedAt = '') {
		super('The page changed since it was loaded.')
		this.name = 'PageConflictError'
		this.changedAt = changedAt
	}
}

/**
 * Whether an error is a version conflict.
 *
 * @param {*} error The error.
 * @return {boolean} True for a conflict.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-a-save-must-not-overwrite-a-newer-save-by-someone-else-req-pie-005
 */
export function isConflict(error) {
	return error instanceof PageConflictError
}

/**
 * The headers of a checked write: refuses when the stored page moved on.
 *
 * @param {() => Promise<{data: object}>} read Reads the stored page.
 * @param {string} version The marker read at load.
 * @return {Promise<object>} `If-Match` on the version.
 * @throws {PageConflictError} When someone else saved in between.
 */
async function checkedHeaders(read, version) {
	const { data } = await read()
	const current = versionOf(data)
	if (current && current !== version) {
		throw new PageConflictError(current)
	}
	return { 'If-Match': version }
}

/**
 * A failed write as the designer reads it: a 409 is a version conflict.
 *
 * @param {object} error The write's failure.
 * @return {Error|object} A `PageConflictError` for a 409, else the failure itself.
 */
function asConflict(error) {
	if (error?.response?.status === 409) {
		return new PageConflictError(String(error.response.data?.currentUpdated || ''))
	}
	return error
}

/**
 * A page loader and saver over injected HTTP calls.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get (url) => Promise<{data}>
 * @param {Function} deps.put (url, payload, config) => Promise
 * @param {Function} deps.url (pageId) => the object URL
 * @return {object} The saver.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-a-save-must-not-overwrite-a-newer-save-by-someone-else-req-pie-005
 */
export function createPageSaver({ get, put, url }) {
	return {
		/**
		 * Read a page and its version marker.
		 *
		 * @param {string} pageId The page id.
		 * @return {Promise<{page: object, version: string}>} The page.
		 */
		async load(pageId) {
			const { data } = await get(url(pageId))
			return { page: withoutEnvelope(data), version: versionOf(data) }
		},

		/**
		 * Write a page, refusing when it changed since `version`.
		 *
		 * @param {string} pageId The page id.
		 * @param {object} payload The whole page to store.
		 * @param {string} version The marker read at load; '' skips the check.
		 * @return {Promise<void>} Resolves when written.
		 * @throws {PageConflictError} When someone else saved in between.
		 */
		async save(pageId, payload, version) {
			const headers = version
				? await checkedHeaders(() => get(url(pageId)), version)
				: {}
			try {
				await put(url(pageId), payload, { headers })
			} catch (error) {
				throw asConflict(error)
			}
		},
	}
}
