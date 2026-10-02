// SPDX-License-Identifier: EUPL-1.2
//
// The submit flow of a portal form with a file field
// (assignment-portal-file-upload). A file field holds references to files in
// the record's own folder, so the record has to exist before a file can go in:
// create first, with every file field left out, then upload the picked files
// one at a time into the field. The server writes each reference itself.
//
// Pure and React-free, so `node --test` can drive it with a fake api while the
// live attach is blocked on a fresh instance (portaliq#29).

/** The one field type the manifest knows. */
export const FILE_TYPE = 'file'

/** The size limit when a file field declares none, in megabytes. */
export const DEFAULT_MAX_SIZE_MB = 20

/**
 * The whitelisted fields of an action that are declared file fields.
 *
 * @param {object} action The normalised manifest action.
 * @return {string[]} The file field names.
 */
export function fileFields(action) {
	const configs = action.fieldConfigs || {}
	return (action.fields || []).filter(
		(field) => configs[field]?.type === FILE_TYPE,
	)
}

/**
 * The picked files that are larger than their field allows.
 *
 * @param {object} action The normalised manifest action.
 * @param {Record<string, File[]>} filesByField The picked files per field.
 * @return {string[]} The names of the files that are too large.
 */
export function oversizedFiles(action, filesByField) {
	const configs = action.fieldConfigs || {}
	const tooLarge = []
	for (const field of fileFields(action)) {
		const limit = (configs[field].maxSizeMb || DEFAULT_MAX_SIZE_MB) * 1024 * 1024
		for (const file of filesByField[field] || []) {
			if (file.size > limit) {
				tooLarge.push(file.name)
			}
		}
	}
	return tooLarge
}

/**
 * The id of a saved object, wherever the server put it.
 *
 * @param {object|null} object The saved object.
 * @return {string} The id, or '' when there is none.
 */
export function objectIdOf(object) {
	if (!object) {
		return ''
	}
	return String(object.id || object.uuid || object['@self']?.id || '')
}

/**
 * Upload the picked files into their fields, one at a time.
 *
 * @param {object} api The portal api adapter (`uploadFieldFile`).
 * @param {object} action The normalised manifest action.
 * @param {string} id The record the files go into.
 * @param {Record<string, File[]>} filesByField The picked files per field.
 * @return {Promise<{failed: Array<{field: string, file: File}>}>} The files that did not attach.
 */
export async function uploadFiles(api, action, id, filesByField) {
	const failed = []
	for (const field of fileFields(action)) {
		for (const file of filesByField[field] || []) {
			const result = await api.uploadFieldFile(action, id, field, file)
			if (!result || !result.ok) {
				failed.push({ field, file })
			}
		}
	}
	return { failed }
}

/**
 * Create the record without its file fields, then upload the picked files.
 *
 * @param {object} api The portal api adapter (`createObject`, `uploadFieldFile`).
 * @param {object} action The normalised manifest action.
 * @param {Record<string, string>} values The form values.
 * @param {Record<string, File[]>} filesByField The picked files per field.
 * @return {Promise<{ok: boolean, object: object|null, id: string, failed: Array<{field: string, file: File}>}>}
 */
export async function submitWithFiles(api, action, values, filesByField) {
	const skip = new Set(fileFields(action))
	const body = {}
	for (const [field, value] of Object.entries(values)) {
		if (!skip.has(field)) {
			body[field] = value
		}
	}

	const created = await api.createObject(action, body)
	if (!created || !created.ok) {
		return { ok: false, object: null, id: '', failed: [] }
	}

	const id = objectIdOf(created.object)
	const hasFiles = [...skip].some(
		(field) => (filesByField[field] || []).length > 0,
	)
	if (!hasFiles) {
		return { ok: true, object: created.object, id, failed: [] }
	}
	if (id === '') {
		// A record the server did not identify cannot take a file.
		const failed = [...skip].flatMap((field) =>
			(filesByField[field] || []).map((file) => ({ field, file })),
		)
		return { ok: true, object: created.object, id, failed }
	}

	const { failed } = await uploadFiles(api, action, id, filesByField)
	return { ok: true, object: created.object, id, failed }
}
