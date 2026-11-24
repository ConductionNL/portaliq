// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The download conditions of a listed document (document-download-terms),
// read and accepted over the portal api's one `request` door.

/**
 * @param {string} value A path segment.
 * @return {string} It, encoded.
 */
const seg = (value) => encodeURIComponent(String(value ?? ''))

/**
 * @param {object} collection Manifest collection: `{ register, schema }`.
 * @param {string} id The object id.
 * @param {object} entry The listed entry: `{ id }`.
 * @return {string} The terms path under the api base.
 */
function termsPath(collection, id, entry) {
	return `/collections/${seg(collection?.register)}/${seg(collection?.schema)}/${seg(id)}/documents/${seg(entry?.id)}/terms`
}

/**
 * The terms calls of a documents block over a portal api.
 *
 * @param {{request: (method: string, path: string, body?: object) => Promise<object>}} api The portal api.
 * @return {object} `{ fetchTerms, acceptTerms }`.
 * @spec openspec/changes/document-download-terms/tasks.md#task-3
 */
export function documentTermsApi(api) {
	return {
		/**
		 * The conditions a listed document carries, or null when the app
		 * declares none or cannot tell.
		 *
		 * @param {object} collection Manifest collection.
		 * @param {string} id The object id.
		 * @param {object} entry The listed entry.
		 * @return {Promise<object|null>} `{ mayDownload, message, agreement }` or null.
		 * @spec openspec/changes/document-download-terms/tasks.md#task-3
		 */
		async fetchTerms(collection, id, entry) {
			const answer = await api.request('GET', termsPath(collection, id, entry))
			const terms = answer?.ok ? answer.json?.terms : null
			return terms && typeof terms === 'object' ? terms : null
		},

		/**
		 * Accept the conditions in the version shown.
		 *
		 * @param {object} collection Manifest collection.
		 * @param {string} id The object id.
		 * @param {object} entry The listed entry.
		 * @param {string} version The version shown.
		 * @return {Promise<object>} `{ ok }`, or `{ ok: false, message }`.
		 * @spec openspec/changes/document-download-terms/tasks.md#task-3
		 */
		async acceptTerms(collection, id, entry, version) {
			const answer = await api.request('POST', termsPath(collection, id, entry), { version })
			return answer?.ok && answer.json?.accepted === true
				? { ok: true }
				: { ok: false, message: answer?.json?.message || '' }
		},
	}
}
