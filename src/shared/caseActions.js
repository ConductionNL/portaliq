// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The create actions a case app placed on the page of its case (`onCase`):
// the form the case screen opens, without the field naming the case, and the
// address the server reads the case from again. Whether an action is open is
// the server's answer; nothing here computes a date.
//
// @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004

/**
 * The case actions in the server's answer, only the well formed ones.
 *
 * @param {object|null} data The citizen case answer.
 * @return {Array<object>} `[{app, action, label, open, closesOn?, closedText?, crossRef, form}]`.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t06
 */
export function caseActionsOf(data) {
	const list = data && Array.isArray(data.caseActions) ? data.caseActions : []
	return list.filter(
		(entry) =>
			entry
			&& typeof entry.action === 'string'
			&& entry.action !== ''
			&& entry.form
			&& typeof entry.form === 'object',
	)
}

/**
 * The action's form without the field the server fills in with the case.
 *
 * @param {object} entry One case action.
 * @return {object} The action, its `crossRef` field gone from fields, required fields, field configs and steps.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t07
 */
export function caseActionForm(entry) {
	const form = { ...(entry.form || {}) }
	const field = entry.crossRef
	const without = (list) =>
		Array.isArray(list) ? list.filter((name) => name !== field) : list
	form.fields = without(form.fields || [])
	if (Array.isArray(form.requiredFields)) {
		form.requiredFields = without(form.requiredFields)
	}
	if (form.fieldConfigs && typeof form.fieldConfigs === 'object') {
		const configs = { ...form.fieldConfigs }
		delete configs[field]
		form.fieldConfigs = configs
	}
	if (Array.isArray(form.steps)) {
		form.steps = form.steps
			.map((step) => ({ ...step, fields: without(step.fields) }))
			.filter((step) => step.review === true || (step.fields || []).length > 0)
	}
	return form
}

/**
 * The `<register>/<schema>/<id>` the create route reads the case from.
 *
 * @param {object} collection The case collection (`register`, `schema`).
 * @param {string} caseId The case id.
 * @return {string} The reference, or '' when a part is missing.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t07
 */
export function caseReference(collection, caseId) {
	const parts = [
		collection && collection.register,
		collection && collection.schema,
		caseId,
	]
	return parts.every(
		(part) => typeof part === 'string' && part !== '' && !part.includes('/'),
	)
		? parts.join('/')
		: ''
}

/**
 * The reference the created record carries, for the receipt, or ''.
 *
 * @param {object|null} object The created record.
 * @return {string}
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-resident-gets-a-receipt-on-the-case-page-req-coc-005
 */
export function receiptReference(object) {
	if (!object || typeof object !== 'object') {
		return ''
	}
	const value = object.reference || object.identifier || ''
	return typeof value === 'string' ? value : ''
}
