/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The portal's pages and menus as OpenRegister objects: list, create, save with
 * the version check, delete. The HTTP calls are handed in.
 *
 * The write goes straight to OpenRegister (ADR-022). Who may write is decided
 * there, by the schema authorization the editor groups write
 * (`PageEditorService::applyToSchema()`), so a refusal here is OpenRegister's.
 *
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */

import { createPageSaver } from './pageSaver.js'

/**
 * A client for the portal's objects.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get (url, config) => Promise<{data}>
 * @param {Function} deps.post (url, payload) => Promise<{data}>
 * @param {Function} deps.put (url, payload, config) => Promise
 * @param {Function} deps.del (url) => Promise
 * @param {Function} deps.url (schema, id?) => the collection or object URL
 * @return {object} The client.
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */
export function createPortalObjects({ get, post, put, del, url }) {
	return {
		/**
		 * The objects of one schema on one portal, each with `id` and `version`.
		 *
		 * @param {string} schema The schema slug.
		 * @param {string} portal The portal slug.
		 * @return {Promise<Array<object>>} The objects.
		 */
		async list(schema, portal) {
			const { data } = await get(url(schema), {
				params: { portal, _limit: 500 },
			})
			const results = Array.isArray(data?.results) ? data.results : []
			return results.map((object) => {
				const plain = { ...object }
				const self = plain['@self'] || {}
				delete plain['@self']
				return {
					...plain,
					id: String(self.id || plain.id || ''),
					version: String(self.updated || ''),
				}
			})
		},

		/**
		 * Create an object.
		 *
		 * @param {string} schema The schema slug.
		 * @param {object} payload The object.
		 * @return {Promise<object>} The stored object.
		 */
		async create(schema, payload) {
			const { data } = await post(url(schema), payload)
			return data
		},

		/**
		 * Save an object, refusing when it changed since `version`.
		 *
		 * @param {string} schema The schema slug.
		 * @param {string} id The object id.
		 * @param {object} payload The whole object.
		 * @param {string} version The `updated` marker read with it.
		 * @return {Promise<void>} Resolves when saved.
		 */
		save(schema, id, payload, version) {
			return createPageSaver({
				get,
				put,
				url: (objectId) => url(schema, objectId),
			}).save(id, payload, version)
		},

		/**
		 * Delete an object.
		 *
		 * @param {string} schema The schema slug.
		 * @param {string} id The object id.
		 * @return {Promise<void>} Resolves when deleted.
		 */
		async remove(schema, id) {
			await del(url(schema, id))
		},
	}
}
