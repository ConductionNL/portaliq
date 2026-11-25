// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The bundle download and the media groups of a documents block
// (documents-grouped-per-record), built on the portal api's one `request`
// door and loaded with the block's extras, so the site entry carries none
// of it.

import { saveBlob } from './areaApi.js'

/**
 * @param {string} value A path segment.
 * @return {string} It, encoded.
 */
const seg = (value) => encodeURIComponent(String(value ?? ''))

/**
 * The documents block extras' calls over a portal api.
 *
 * @param {{request: Function}} api The portal api.
 * @return {object} `{ downloadDocumentsBundle, fetchMediaGroup, downloadMediaGroupFile }`.
 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
 */
export function documentsExtrasApi(api) {
	/**
	 * GET a file and save it under a name.
	 *
	 * @param {string} path The path under the api base.
	 * @param {string} name The name to save it under.
	 * @return {Promise<{ok: boolean, status: number}>} The outcome.
	 */
	async function save(path, name) {
		const answer = await api.request('GET', path, null, true)
		if (!answer.ok) {
			return { ok: false, status: answer.status }
		}
		await saveBlob(answer.res, name || 'download')
		return { ok: true, status: answer.status }
	}

	return {
		/**
		 * Save the bundle an object's app answers through its collection's
		 * `bundle` method, under the button's own words.
		 *
		 * @param {object} collection Manifest collection: `{ register, schema }`.
		 * @param {string} id The object id.
		 * @param {string} name The name to save it under.
		 * @return {Promise<object>} `{ ok, status }`.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-2
		 */
		downloadDocumentsBundle(collection, id, name) {
			return save(
				`/collections/${seg(collection?.register)}/${seg(collection?.schema)}/${seg(id)}/documents-bundle`,
				name,
			)
		},

		/**
		 * The media of an extra group the signed-in person may read;
		 * OpenRegister decides which.
		 *
		 * @param {object} group The block's extra group: `{ register, schema }`.
		 * @return {Promise<Array<object>|null>} The media, or null when the read fails.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
		 */
		async fetchMediaGroup(group) {
			const answer = await api.request('GET', `/media-groups/${seg(group.register)}/${seg(group.schema)}`)
			return answer.ok && Array.isArray(answer.json?.documents) ? answer.json.documents : null
		},

		/**
		 * Open one medium of an extra group. Only its id travels; the server
		 * asks OpenRegister again.
		 *
		 * @param {object} group The block's extra group: `{ register, schema }`.
		 * @param {object} entry The listed medium: `{ id, title }`.
		 * @return {Promise<object>} `{ ok, status }`.
		 * @spec openspec/changes/documents-grouped-per-record/tasks.md#task-3
		 */
		downloadMediaGroupFile(group, entry) {
			return save(`/media-groups/${seg(group.register)}/${seg(group.schema)}/${seg(entry.id)}`, entry.title)
		},
	}
}
