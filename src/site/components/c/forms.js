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
import { dateProblem } from '../forms/fields.js'

export { asksInput, inputFields } from '../../../shared/actionInput.js'

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
 * The values with every required select that offers exactly one option set to
 * that option, when it is still empty. A guardian with one child does not have
 * to pick that child; the select stays visible and can still be changed. A
 * value the resident already chose is never overwritten.
 *
 * @param {object} action The action.
 * @param {Record<string, string>} values The current values.
 * @param {Record<string, Array<{value: string, label: string}>>} options The resolved options per field.
 * @return {Record<string, string>} The values, with the single options filled in.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */
export function withSingleOptions(action, values, options) {
	const out = { ...(values || {}) }
	for (const field of formFields(action)) {
		const list = (options || {})[field]
		if (
			fieldConfig(action, field).required !== true
			|| fieldInput(action, field, list) !== 'select'
			|| !Array.isArray(list)
			|| list.length !== 1
		) {
			continue
		}
		const current = out[field]
		const only = list[0] && list[0].value
		if (
			(current === undefined || current === null || String(current) === '')
			&& only !== undefined
			&& only !== null
			&& String(only) !== ''
		) {
			out[field] = String(only)
		}
	}
	return out
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
 * The JSON type a field's value is sent in: the `valueType` the server read
 * from the schema (integer, number, boolean), else a number for a number input
 * or a count stepper, else a string.
 *
 * @param {object} action The action.
 * @param {string} field The field.
 * @return {'integer'|'number'|'boolean'|'string'} The type.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-form-must-send-each-value-in-the-type-its-field-declares
 */
export function valueTypeOf(action, field) {
	const config = fieldConfig(action, field)
	if (['integer', 'number', 'boolean'].includes(config.valueType)) {
		return config.valueType
	}
	if (config.widget === 'count') {
		return 'integer'
	}
	return config.input === 'number' ? 'number' : 'string'
}

/**
 * A typed answer as a number, with a decimal comma read as a point; null when
 * it is no number.
 *
 * @param {string} text The answer.
 * @return {number|null} The number.
 */
function numberOf(text) {
	const normal = String(text).trim().replace(',', '.')
	if (!/^-?\d+(\.\d+)?$/.test(normal)) {
		return null
	}
	return Number(normal)
}

/**
 * A typed answer as a boolean; null when it is neither yes nor no.
 *
 * @param {string} text The answer.
 * @return {boolean|null} The boolean.
 */
function booleanOf(text) {
	const normal = String(text).trim().toLowerCase()
	if (['true', '1', 'yes', 'ja'].includes(normal)) {
		return true
	}
	if (['false', '0', 'no', 'nee'].includes(normal)) {
		return false
	}
	return null
}

/**
 * One answer in the type its field declares. An answer that does not fit the
 * type is sent as typed, so the server's refusal names the field; the form's
 * own check catches it first.
 *
 * @param {string} type The value type.
 * @param {string} text The answer as typed.
 * @return {string|number|boolean} The value.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-form-must-send-each-value-in-the-type-its-field-declares
 */
export function typedValue(type, text) {
	if (type === 'integer' || type === 'number') {
		const number = numberOf(text)
		return number === null ? text : number
	}
	if (type === 'boolean') {
		const yes = booleanOf(text)
		return yes === null ? text : yes
	}
	return text
}

/**
 * The body a form sends: every whitelisted field that is not a file field,
 * each in the type its field declares. An empty text field sends ''; an empty
 * number or yes/no field is left out, since '' is no number.
 *
 * @param {object} action The action.
 * @param {Record<string, string>} values The typed values.
 * @param {Record<string, Array>} [options] The resolved options per field.
 * @return {Record<string, string|number|boolean>} The body.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-form-must-send-each-value-in-the-type-its-field-declares
 */
export function formBody(action, values, options = {}) {
	const files = fileFields(action)
	const body = {}
	for (const field of formFields(action)) {
		if (files.includes(field)) {
			continue
		}
		const text = sentValue(
			fieldInput(action, field, options[field]),
			(values || {})[field],
		)
		const type = valueTypeOf(action, field)
		if (type === 'string') {
			body[field] = text
		} else if (text.trim() !== '') {
			body[field] = typedValue(type, text)
		}
	}
	return body
}

/**
 * Whether an action is forwarded to its app's own endpoint rather than
 * written as a record.
 *
 * @param {object} action The action.
 * @return {boolean} True for an endpoint action.
 */
export function isEndpointAction(action) {
	return (
		Boolean(action)
		&& action.type !== 'create'
		&& action.type !== 'update'
		&& typeof action.endpoint === 'string'
		&& action.endpoint !== ''
	)
}

/**
 * The inline error per field: a required field left empty, a required file
 * field without a file, or a date whose day, month and year make no real date.
 *
 * @param {object} action The action.
 * @param {Record<string, string>} values The typed values.
 * @param {Record<string, File[]>} files The picked files per field.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {Record<string, string>} The message per field; empty when all is well.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-word-its-own-error-req-smf-006
 */
export function fieldErrors(action, values, files, t) {
	const translate = translatorOr(t)
	const fileNames = fileFields(action)
	const errors = {}
	for (const field of formFields(action)) {
		const config = fieldConfig(action, field)
		const required = config.required === true
		const label = fieldLabel(action, field)
		// The app's own words for an empty required field win (REQ-SMF-006).
		const own =
			typeof config.requiredMessage === 'string'
			&& config.requiredMessage.trim() !== ''
				? config.requiredMessage
				: ''
		if (fileNames.includes(field)) {
			if (required && ((files || {})[field] || []).length === 0) {
				errors[field] =
					own
					|| translate('Please choose a file for {field}.', {
						field: label,
					})
			}
			continue
		}
		const value = (values || {})[field]
		const empty =
			value === undefined || value === null || String(value).trim() === ''
		if (empty) {
			if (required) {
				errors[field] =
					own || translate('{field} is required.', { field: label })
			}
			continue
		}
		if (fieldInput(action, field) === 'date' && dateProblem(value)) {
			errors[field] = translate(
				'{field}: enter a real date, for example 1 3 2026.',
				{ field: label },
			)
			continue
		}
		const wrongType = typeProblem(valueTypeOf(action, field), String(value))
		if (wrongType !== '') {
			errors[field] = translate(wrongType, { field: label })
		}
	}
	return errors
}

/**
 * The message key for an answer that does not fit its type, or ''.
 *
 * @param {string} type The value type.
 * @param {string} text The answer.
 * @return {string} The English source string.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-form-must-send-each-value-in-the-type-its-field-declares
 */
function typeProblem(type, text) {
	if (type === 'number' && numberOf(text) === null) {
		return '{field}: enter a number, for example 8 or 7.5.'
	}
	if (type === 'integer' && !Number.isInteger(numberOf(text))) {
		return '{field}: enter a whole number, for example 8.'
	}
	return ''
}

/**
 * The words for each kind of value the server refused (`invalid`), by kind.
 */
const INVALID_KEYS = Object.freeze({
	number: '{field}: enter a number, for example 8 or 7.5.',
	integer: '{field}: enter a whole number, for example 8.',
	date: '{field}: enter a real date, for example 1 3 2026.',
	required: '{field} is required.',
})

/**
 * The server's refusal of a value per field (`invalid`, `{field: kind}`) as
 * the form's own errors, in plain words, only for fields the form shows.
 *
 * @param {object} action The action.
 * @param {Record<string, string>|undefined} invalid The answer's `invalid`.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {Record<string, string>} The message per field.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
 */
export function invalidFieldErrors(action, invalid, t) {
	const translate = translatorOr(t)
	const out = {}
	for (const field of formFields(action)) {
		const kind = (invalid || {})[field]
		if (typeof kind !== 'string') {
			continue
		}
		out[field] = translate(
			INVALID_KEYS[kind] || '{field} is not filled in correctly. Check it.',
			{ field: fieldLabel(action, field) },
		)
	}
	return out
}

/**
 * The message for a refused form that names no field, by status: a refusal of
 * the answers (400, 422), an item that can no longer take it (403, 404, 409),
 * else a failure on the way.
 *
 * @param {number} status The answer's status; 0 for a network failure.
 * @return {string} The English source string.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-refused-answer-must-say-in-plain-words-which-field-to-change
 */
export function refusalKey(status) {
	if (status === 400 || status === 422) {
		return 'Not everything is filled in correctly. Check your answers.'
	}
	if (status === 403 || status === 404 || status === 409) {
		return 'This can no longer be done for this item.'
	}
	return 'Saving did not work.'
}

/**
 * An endpoint forward's answer (`{ok, status, body}`) in the shape a form's
 * `send` returns: the leaf app's `errors` and `invalid` kept, so a refusal
 * that names a field lands on that field.
 *
 * @param {{ok: boolean, status: number, body: object}} result The forward's answer.
 * @return {{ok: boolean, status: number, object: object, errors: object, invalid: object}} The send result.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-action-that-needs-input-must-open-its-form-before-it-sends
 */
export function forwardResult(result) {
	const body = (result && result.body) || {}
	const map = (value) => (value && typeof value === 'object' ? value : {})
	return {
		ok: Boolean(result && result.ok),
		status: (result && result.status) || 0,
		object: body,
		errors: map(body.errors),
		invalid: map(body.invalid),
	}
}

/**
 * The server's refusal per field as the form's own errors: the server sends
 * the action's `requiredMessage`, or '' for the site to word, and only for
 * fields the form shows (site-multi-step-forms REQ-SMF-024).
 *
 * @param {object} action The action.
 * @param {Record<string, string>|undefined} errors The answer's `errors`.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {Record<string, string>} The message per field.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-the-server-must-refuse-a-submit-that-leaves-a-required-field-empty-req-smf-024
 */
export function serverFieldErrors(action, errors, t) {
	const translate = translatorOr(t)
	const out = {}
	for (const field of formFields(action)) {
		const message = (errors || {})[field]
		if (typeof message !== 'string') {
			continue
		}
		out[field] =
			message.trim() !== ''
				? message
				: translate('{field} is required.', {
						field: fieldLabel(action, field),
					})
	}
	return out
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
