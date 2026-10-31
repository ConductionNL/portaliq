// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Whether an action needs the resident's input before it is sent
 * (site-action-forms). Pure, with no imports, so a page of any slice can ask
 * it without loading the forms.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-action-that-needs-input-must-open-its-form-before-it-sends
 */

/**
 * The fields of an action the resident fills in: its whitelisted fields,
 * minus a field the server stamps (`subjectField`, `rowField`), a value the
 * action sets itself (`set`) and a field it hides (`visible: false`).
 *
 * @param {object} action The action.
 * @return {string[]} The fields.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-action-that-needs-input-must-open-its-form-before-it-sends
 */
export function inputFields(action) {
	if (!action || !Array.isArray(action.fields)) {
		return []
	}
	const stamped = new Set(
		[
			action.subjectField,
			action.rowField,
			...Object.keys(action.set || {}),
		].filter((field) => typeof field === 'string'),
	)
	const configs = action.fieldConfigs || {}
	return action.fields.filter(
		(field) =>
			typeof field === 'string'
			&& !stamped.has(field)
			&& (configs[field] || {}).visible !== false,
	)
}

/**
 * Whether an action needs the resident's input before it can be sent.
 *
 * @param {object} action The action.
 * @return {boolean} True when it has a field to fill in.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-action-that-needs-input-must-open-its-form-before-it-sends
 */
export function asksInput(action) {
	return inputFields(action).length > 0
}
