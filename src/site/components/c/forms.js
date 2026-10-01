// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The decisions behind slice c's forms and actions, without Vue and without
 * fetch, so `node --test` asserts them on their own
 * (tests/schema-form.spec.mjs, tests/propose-change.spec.mjs).
 *
 * The server decides all of this again: it re-whitelists every field, checks
 * `required` against the schema, and re-scopes every row. These helpers keep
 * the screen honest, they are not the authority.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */

import { fileFields } from '../../../shared/fileFieldSubmit.js'

/**
 * Interpolate `{name}` placeholders.
 *
 * @param {string} text The text.
 * @param {object} [vars] Placeholder values.
 * @return {string} The text with its placeholders filled.
 */
export function interpolate(text, vars) {
	let out = String(text)
	for (const [name, value] of Object.entries(vars || {})) {
		out = out.split(`{${name}}`).join(String(value))
	}
	return out
}

/**
 * The translator to use: the one handed in, or one that only interpolates the
 * English source string.
 *
 * @param {(key: string, vars?: object) => string} [t] The page's `t(key, vars)`.
 * @return {(key: string, vars?: object) => string} A translator.
 */
export function translatorOr(t) {
	return typeof t === 'function' ? t : interpolate
}

/**
 * The id of a row, wherever the server put it.
 *
 * @param {object|null} row The row.
 * @return {string} The id, or ''.
 */
export function rowIdOf(row) {
	if (!row) {
		return ''
	}
	return String(row.id || (row['@self'] && row['@self'].id) || '')
}

/**
 * The whitelisted fields of an action, strings only.
 *
 * @param {object} action The normalised manifest action.
 * @return {string[]} The fields.
 */
export function formFields(action) {
	return ((action && action.fields) || []).filter(
		(field) => typeof field === 'string',
	)
}

/**
 * The config of one field, never null.
 *
 * @param {object} action The action.
 * @param {string} field The field.
 * @return {object} The field config.
 */
export function fieldConfig(action, field) {
	return ((action && action.fieldConfigs) || {})[field] || {}
}

/**
 * The visible label of one field.
 *
 * @param {object} action The action.
 * @param {string} field The field.
 * @return {string} The label.
 */
export function fieldLabel(action, field) {
	const label = fieldConfig(action, field).label
	return typeof label === 'string' && label !== '' ? label : field
}

/**
 * The input one field renders as. A file field is a file picker, a field with
 * options a select, a large field a textarea; otherwise the schema's `input`
 * hint (date, datetime, number, email) or a text box.
 *
 * @param {object} action The action.
 * @param {string} field The field.
 * @param {Array|undefined} options The field's resolved options, if any.
 * @return {string} One of file, select, textarea, date, datetime-local, number, email, text.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */
export function fieldInput(action, field, options) {
	if (fileFields(action).includes(field)) {
		return 'file'
	}
	const providers = (action && action.optionsProviders) || {}
	if (Array.isArray(options) || providers[field]) {
		return 'select'
	}
	const config = fieldConfig(action, field)
	if (config.size === 'large' || config.size === 'full') {
		return 'textarea'
	}
	switch (config.input) {
		case 'date':
			return 'date'
		case 'datetime':
			return 'datetime-local'
		case 'number':
			return 'number'
		case 'email':
			return 'email'
		default:
			return 'text'
	}
}

/**
 * The options a form knows without asking: every `static` provider.
 *
 * @param {object} action The action.
 * @return {Record<string, Array<{value: string, label: string}>>} Options per field.
 */
export function staticOptions(action) {
	const providers = (action && action.optionsProviders) || {}
	const out = {}
	for (const field of formFields(action)) {
		const provider = providers[field]
		if (provider && provider.type === 'static') {
			out[field] = Array.isArray(provider.options) ? provider.options : []
		}
	}
	return out
}

/**
 * The `collection` providers a form fetches through the subject-scoped api.
 *
 * @param {object} action The action.
 * @return {Array<[string, object]>} `[field, provider]` pairs.
 */
export function collectionProviders(action) {
	const providers = (action && action.optionsProviders) || {}
	return formFields(action)
		.filter(
			(field) => providers[field] && providers[field].type === 'collection',
		)
		.map((field) => [field, providers[field]])
}

/**
 * The value one input sends. A `datetime-local` value has no zone, so it is
 * sent as the ISO instant the resident meant in their own time.
 *
 * @param {string} input The input kind.
 * @param {string|number|null|undefined} value The input's value.
 * @return {string} The value to send.
 */
export function sentValue(input, value) {
	const text = value === null || value === undefined ? '' : String(value)
	if (input === 'datetime-local' && text !== '') {
		const date = new Date(text)
		return Number.isNaN(date.getTime()) ? text : date.toISOString()
	}
	return text
}

/**
 * The body a form creates its record with: every whitelisted field that is
 * not a file field, '' when empty.
 *
 * @param {object} action The action.
 * @param {Record<string, string>} values The typed values.
 * @param {Record<string, Array>} [options] The resolved options per field.
 * @return {Record<string, string>} The body.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */
export function formBody(action, values, options = {}) {
	const files = fileFields(action)
	const body = {}
	for (const field of formFields(action)) {
		if (!files.includes(field)) {
			body[field] = sentValue(
				fieldInput(action, field, options[field]),
				(values || {})[field],
			)
		}
	}
	return body
}

/**
 * The inline error per field: a required field left empty, or a required
 * file field without a file.
 *
 * @param {object} action The action.
 * @param {Record<string, string>} values The typed values.
 * @param {Record<string, File[]>} files The picked files per field.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {Record<string, string>} The message per field; empty when all is well.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */
export function fieldErrors(action, values, files, t) {
	const translate = translatorOr(t)
	const fileNames = fileFields(action)
	const errors = {}
	for (const field of formFields(action)) {
		if (fieldConfig(action, field).required !== true) {
			continue
		}
		const label = fieldLabel(action, field)
		if (fileNames.includes(field)) {
			if (((files || {})[field] || []).length === 0) {
				errors[field] = translate('Please choose a file for {field}.', {
					field: label,
				})
			}
			continue
		}
		const value = (values || {})[field]
		if (value === undefined || value === null || String(value).trim() === '') {
			errors[field] = translate('{field} is required.', { field: label })
		}
	}
	return errors
}

/**
 * A row's value as the text a propose-change field starts from.
 *
 * @param {object|null} row The row.
 * @param {string} field The field.
 * @return {string} The text.
 */
function rowText(row, field) {
	const value = row ? row[field] : undefined
	return value === null || value === undefined ? '' : String(value)
}

/**
 * The starting values of a propose-change form: the row's own values.
 *
 * @param {object} action The `propose-change` action.
 * @param {object|null} row The row.
 * @return {Record<string, string>} The values.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-propose-a-change-req-srp-024
 */
export function proposalStart(action, row) {
	const out = {}
	for (const field of (action && action.proposable) || []) {
		out[field] = rowText(row, field)
	}
	return out
}

/**
 * Only the fields whose value changed, as `{property, proposedValue}` pairs.
 *
 * @param {object} action The `propose-change` action.
 * @param {object|null} row The row.
 * @param {Record<string, string>} values The edited values.
 * @return {Array<{property: string, proposedValue: string}>} The changes.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-propose-a-change-req-srp-024
 */
export function proposedChanges(action, row, values) {
	return ((action && action.proposable) || [])
		.filter(
			(field) => rowText(row, field) !== String((values || {})[field] ?? ''),
		)
		.map((field) => ({
			property: field,
			proposedValue: String((values || {})[field] ?? ''),
		}))
}

/**
 * This subject's proposals on one record.
 *
 * @param {Array<object>} mine Every proposal the subject made.
 * @param {object} action The `propose-change` action.
 * @param {string} rowId The record.
 * @return {Array<object>} The proposals on that record.
 */
export function proposalsOn(mine, action, rowId) {
	return (Array.isArray(mine) ? mine : []).filter(
		(p) =>
			p
			&& p.subjectId === rowId
			&& p.subjectRegister === action.register
			&& p.subjectSchema === action.schema,
	)
}

/**
 * The words for a proposal's state, so the state reads as text, not colour.
 *
 * @param {string} state The state.
 * @return {string} The English source string.
 */
export function proposalStateKey(state) {
	return (
		{
			queued: 'Waiting for review',
			accepted: 'Accepted',
			rejected: 'Rejected',
			withdrawn: 'Withdrawn',
		}[state] || String(state || '')
	)
}
