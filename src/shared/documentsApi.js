// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The documents of one object of any collection, its bundle, and the media
// groups a documents block adds (site-member-voting-record-and-confidential-papers,
// documents-grouped-per-record). Bound into the portal adapter by
// createPortalApi(), which hands in its own request helpers.

/**
 * The documents part of the portal adapter.
 *
 * @param {object} helpers The adapter's own helpers.
 * @param {string} helpers.base The api base.
 * @param {(register: string, schema: string) => string} helpers.col A collection's path.
 * @param {(path: string) => Promise<object|null>} helpers.get A GET with the bearer.
 * @param {(url: string, entry: object) => Promise<object>} helpers.saveDocument Save a listed entry.
 * @param {(url: string, name: string) => Promise<object>} helpers.saveDownload Save a file under a name.
 * @return {object} The documents methods.
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
 */
export function documentsApi({ base, col, get, saveDocument, saveDownload }) {
	return {
	/**
	 * The documents one object of any collection lists, for a collection
	 * that declares `documents` and is not a case collection. The server
	 * finds the collection in the resident's own aggregate and reads the
	 * object within its scope, so a collection above the session's trust,
	 * an object outside the scope and an unknown id are all null here.
	 *
	 * @param {object} collection Manifest collection: `{ register, schema }`.
	 * @param {string} id The object id.
	 * @return {Promise<object|null>} `{ documents, documentsLabel }` or null.
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
	 */
	async fetchCollectionDocuments(collection, id) {
		const answer = await get(
			`${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/documents`,
		)
		return answer && Array.isArray(answer.documents)
			? { documents: answer.documents, documentsLabel: answer.label || '' }
			: null
	},

	/**
	 * Open one document `fetchCollectionDocuments` listed. Only the entry
	 * id travels; the server looks it up again, runs the collection's
	 * `opened` hook when it has one, and answers 503 when that says no.
	 *
	 * @param {object} collection Manifest collection: `{ register, schema }`.
	 * @param {string} id The object id.
	 * @param {object} entry The listed entry: `{ id, title }`.
	 * @return {Promise<object>} `{ ok }`, or `{ ok: false, status }`.
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t4
	 */
	async downloadCollectionDocument(collection, id, entry) {
		return saveDocument(
			`${base}${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/documents/${encodeURIComponent(entry.id)}`,
			entry,
		)
	},

	/**
	 * Save the bundle an object's app answers through its collection's
	 * `bundle` method (documents-grouped-per-record), under the button's
	 * own words.
	 *
	 * @param {object} collection Manifest collection: `{ register, schema }`.
	 * @param {string} id The object id.
	 * @param {string} name The name to save it under.
	 * @return {Promise<object>} `{ ok }`, or `{ ok: false, status }`.
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
	 */
	async downloadDocumentsBundle(collection, id, name) {
		return saveDownload(
			`${base}${col(collection.register, collection.schema)}/${encodeURIComponent(id)}/documents-bundle`,
			name || 'download',
		)
	},

	/**
	 * The media of a documents block's extra group the signed-in person
	 * may read; OpenRegister decides which (documents-grouped-per-record).
	 *
	 * @param {object} group The block's extra group: `{ register, schema }`.
	 * @return {Promise<Array<object>|null>} The media, or null when the read fails.
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	async fetchMediaGroup(group) {
		const answer = await get(
			`/media-groups/${encodeURIComponent(group.register)}/${encodeURIComponent(group.schema)}`,
		)
		return answer && Array.isArray(answer.documents) ? answer.documents : null
	},

	/**
	 * Open one medium of an extra group. Only its id travels; the server
	 * asks OpenRegister again.
	 *
	 * @param {object} group The block's extra group: `{ register, schema }`.
	 * @param {object} entry The listed medium: `{ id, title }`.
	 * @return {Promise<object>} `{ ok }`, or `{ ok: false, status }`.
	 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
	 */
	async downloadMediaGroupFile(group, entry) {
		return saveDocument(
			`${base}/media-groups/${encodeURIComponent(group.register)}/${encodeURIComponent(group.schema)}/${encodeURIComponent(entry.id)}`,
			entry,
		)
	},
	}
}
