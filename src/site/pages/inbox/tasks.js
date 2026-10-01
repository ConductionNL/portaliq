/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The plain logic of "My tasks" (portal-task-delivery): a task's frozen upload
 * rules, the check of the chosen files against them, and the plain-language
 * text for each refusal the proxy names. The server checks everything again;
 * this check only lets the resident see a refusal before the upload. The same
 * rules as the React portal's TasksPage.jsx, without a framework.
 */

/** Bytes in one megabyte, as the rules count it. */
const MB = 1024 * 1024

/**
 * The frozen upload rules of a task, normalised.
 *
 * @param {object} task The task row.
 * @return {{required: boolean, maxFiles: number, maxSizeBytes: number, acceptedTypes: Array<string>}}
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function uploadRules(task) {
	const upload = task?.metadata?.upload || {}
	return {
		required: upload.required === true,
		maxFiles: Number(upload.maxFiles) > 0 ? Number(upload.maxFiles) : 1,
		maxSizeBytes:
			Number(upload.maxSizeBytes) > 0 ? Number(upload.maxSizeBytes) : 0,
		acceptedTypes: Array.isArray(upload.acceptedTypes)
			? upload.acceptedTypes.map(String)
			: [],
	}
}

/**
 * The size limit in whole megabytes.
 *
 * @param {number} bytes The limit in bytes.
 * @return {number} Megabytes.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function sizeInMb(bytes) {
	return Math.round(bytes / MB)
}

/**
 * Whether one file matches an accepted type: an exact media type, a `type/*`
 * wildcard, or an extension (`pdf` or `.pdf`), as the server matches.
 *
 * @param {{name: string, type: string}} file The chosen file.
 * @param {Array<string>} accepted The accepted entries.
 * @return {boolean}
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function typeAccepted(file, accepted) {
	if (!accepted || accepted.length === 0) {
		return true
	}
	const type = String(file?.type || '').toLowerCase()
	const extension = String(file?.name || '')
		.split('.')
		.pop()
		.toLowerCase()
	return accepted.some((entry) => {
		const wanted = String(entry).toLowerCase()
		if (wanted.endsWith('/*')) {
			return type.startsWith(wanted.slice(0, -1))
		}
		if (wanted.includes('/')) {
			return type === wanted
		}
		return extension === wanted.replace(/^\./, '')
	})
}

/**
 * The file input's `accept` value, or undefined when any type goes.
 *
 * @param {Array<string>} accepted The accepted entries.
 * @return {string|undefined} The value.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function acceptAttribute(accepted) {
	if (!accepted || accepted.length === 0) {
		return undefined
	}
	return accepted
		.map((entry) =>
			entry.includes('/') ? entry : `.${entry.replace(/^\./, '')}`,
		)
		.join(',')
}

/**
 * The first rule the chosen files break, as a sentence, or null.
 *
 * @param {object} task The task row.
 * @param {Array<{name: string, type: string, size: number}>} chosen The chosen files.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {string|null} The message.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function validateFiles(task, chosen, t) {
	const rules = uploadRules(task)
	const files = chosen || []
	if (rules.required && files.length === 0) {
		return t('A file is required for this task.')
	}
	if (files.length > rules.maxFiles) {
		return t('You can add at most {count} file(s).', { count: rules.maxFiles })
	}
	for (const file of files) {
		if (rules.maxSizeBytes > 0 && file.size > rules.maxSizeBytes) {
			return t('This file is too large. The limit is {size} MB.', {
				size: sizeInMb(rules.maxSizeBytes),
			})
		}
		if (!typeAccepted(file, rules.acceptedTypes)) {
			return t('This file type is not accepted. Allowed: {types}.', {
				types: rules.acceptedTypes.join(', '),
			})
		}
	}
	return null
}

/**
 * The plain-language key for a refusal code from the task proxy.
 *
 * @param {string} code The refusal code.
 * @return {string} The message key.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-complete-their-tasks-req-srp-035
 */
export function refusalKey(code) {
	if (code === 'no-such-task') {
		return 'This task does not exist or is not yours.'
	}
	if (code === 'task-closed') {
		return 'This task is already closed.'
	}
	if (code === 'upload-constraint') {
		return 'The upload was refused. Check the file rules above.'
	}
	return 'The tasks are not available right now. Please try again later.'
}
