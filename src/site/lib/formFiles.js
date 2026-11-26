/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The files a visitor picks in a landing-page form's file fields, checked
 * before the form is sent (anonymous-form-file-upload). The server checks
 * them again on the upload: these checks only spare the visitor a refusal.
 *
 * @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md
 */

/** The size a file field allows without `maxSizeMb`, as the server does. */
export const DEFAULT_MAX_SIZE_MB = 20

/** The Dutch messages. */
export const FILE_TEXT = Object.freeze({
	required: '{field} is verplicht. Kies een bestand.',
	tooLarge: '{field}: {name} is groter dan {size} MB.',
	refusedType: '{field}: {name} is geen bestand van een soort die hier kan ({accept}).',
	notSent: 'Uw formulier is verstuurd, maar niet elk bestand is meegekomen. Stuur het bestand ons apart, en noem uw formulier.',
})

/**
 * Whether a field is a file field.
 *
 * @param {object} field The field.
 * @return {boolean} True for type `file`.
 */
export function isFileField(field) {
	return !!field && field.type === 'file'
}

/**
 * A field's `accept` as a list: the inspector keeps it as `.pdf, image/png`.
 *
 * @param {Array<string>|string|undefined} accept The declared accept.
 * @return {Array<string>} The entries, lowercased.
 */
export function acceptList(accept) {
	const entries = Array.isArray(accept) ? accept : String(accept || '').split(',')
	return entries.map((entry) => String(entry).trim().toLowerCase()).filter((entry) => entry !== '')
}

/**
 * Whether a file fits an accept list, like the browser attribute does.
 *
 * @param {{name: string, type: string}} file The file.
 * @param {Array<string>} accept The accept entries.
 * @return {boolean} True when it fits, or when nothing is declared.
 */
export function fitsAccept(file, accept) {
	if (accept.length === 0) {
		return true
	}
	const name = String(file.name || '').toLowerCase()
	const type = String(file.type || '').toLowerCase()
	return accept.some((entry) => {
		if (entry.startsWith('.')) {
			return name.endsWith(entry)
		}
		if (entry.endsWith('/*')) {
			return type.startsWith(entry.slice(0, -1))
		}
		return type === entry
	})
}

/**
 * Fill one message.
 *
 * @param {string} message The message.
 * @param {object} values The values per placeholder.
 * @return {string} The filled message.
 */
function filled(message, values) {
	return message.replace(/\{(\w+)\}/g, (match, key) => (key in values ? String(values[key]) : match))
}

/**
 * The problem of one file field's files, or ''.
 *
 * @param {object} field The file field.
 * @param {Array<File>} chosen The files picked for it.
 * @param {object} text The messages.
 * @return {string} The message.
 */
function fileProblem(field, chosen, text) {
	const label = field.label || field.id
	if (chosen.length === 0) {
		return field.required ? filled(text.required, { field: label }) : ''
	}
	const size = Number(field.maxSizeMb) > 0 ? Number(field.maxSizeMb) : DEFAULT_MAX_SIZE_MB
	const accept = acceptList(field.accept)
	for (const file of chosen) {
		if (Number(file.size) > size * 1024 * 1024) {
			return filled(text.tooLarge, { field: label, name: file.name, size })
		}
		if (!fitsAccept(file, accept)) {
			return filled(text.refusedType, { field: label, name: file.name, accept: accept.join(', ') })
		}
	}
	return ''
}

/**
 * The message per file field whose files cannot be sent.
 *
 * @param {Array<object>} fields The answering fields.
 * @param {Record<string, Array<File>>} files The files per field id.
 * @param {object} [text] The messages.
 * @return {Record<string, string>} The message per field id.
 */
export function fileErrors(fields, files, text = FILE_TEXT) {
	const errors = {}
	for (const field of (Array.isArray(fields) ? fields : []).filter(isFileField)) {
		const problem = fileProblem(field, (files || {})[field.id] || [], text)
		if (problem) {
			errors[field.id] = problem
		}
	}
	return errors
}

/**
 * The files to send, in field order, one entry per file.
 *
 * @param {Array<object>} fields The answering fields.
 * @param {Record<string, Array<File>>} files The files per field id.
 * @return {Array<{field: string, file: File}>} The uploads.
 */
export function pendingUploads(fields, files) {
	const uploads = []
	for (const field of (Array.isArray(fields) ? fields : []).filter(isFileField)) {
		const chosen = (files || {})[field.id] || []
		for (const file of field.multiple ? chosen : chosen.slice(0, 1)) {
			uploads.push({ field: field.id, file })
		}
	}
	return uploads
}
