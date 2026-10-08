// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The calculations of a form, for DISPLAY only (form-flow-repeating-groups-
 * calculations-and-decisions REQ-FFL-002). The server works every value out
 * again on submit with the same six operations and stores its own result; this
 * module mirrors them so the resident sees the value while filling in the form.
 * `tests/form-calculations.spec.mjs` runs both on the same fixtures.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t04
 */

export const OPERATIONS = Object.freeze(['sum', 'multiply', 'subtract', 'addDays', 'diffDays', 'count'])

const GROUP_ARGUMENT = /^([A-Za-z][A-Za-z0-9_]*)\[\]\.([A-Za-z][A-Za-z0-9_]*)$/

/**
 * Whether a value is a number the server would accept.
 *
 * @param {*} value The value.
 * @return {boolean} True for a number or a numeric string.
 */
function isNumeric(value) {
	return (
		(typeof value === 'number' && Number.isFinite(value))
		|| (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value)))
	)
}

/**
 * The values an argument stands for.
 *
 * @param {*} arg A number, a field name or `group[].field`.
 * @param {Record<string, any>} answers The answers.
 * @return {Array<any>} The values; empty when nothing is answered.
 */
function valuesOf(arg, answers) {
	if (typeof arg === 'number') {
		return [arg]
	}
	if (typeof arg !== 'string') {
		return []
	}
	const group = GROUP_ARGUMENT.exec(arg)
	if (group) {
		return (Array.isArray(answers[group[1]]) ? answers[group[1]] : [])
			.filter((item) => item && Object.hasOwn(item, group[2]))
			.map((item) => item[group[2]])
	}
	if (isNumeric(arg)) {
		return [Number(arg)]
	}
	const value = answers[arg]
	if (value === undefined || value === null || value === '' || Array.isArray(value) || typeof value === 'object') {
		return []
	}
	return [value]
}

/**
 * A real `yyyy-mm-dd` as a UTC day number, else null.
 *
 * @param {*} value The value.
 * @return {number|null} Days since 1970-01-01, or null.
 */
function dayNumber(value) {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value))
	if (!match) {
		return null
	}
	const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
	const date = new Date(Date.UTC(year, month - 1, day))
	if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
		return null
	}
	return Math.round(date.getTime() / 86400000)
}

/**
 * A day number as `yyyy-mm-dd`.
 *
 * @param {number} days Days since 1970-01-01.
 * @return {string} The date.
 */
function isoOf(days) {
	return new Date(days * 86400000).toISOString().slice(0, 10)
}

/**
 * One date argument as a day number.
 *
 * @param {*} arg The argument.
 * @param {Record<string, any>} answers The answers.
 * @return {number|null} The day number, or null.
 */
function dateArgument(arg, answers) {
	const values = valuesOf(arg, answers)
	return values.length === 1 ? dayNumber(values[0]) : null
}

/**
 * Work one calculation out over the answers.
 *
 * @param {{op: string, args: Array<*>}} calculate The field's `calculate`.
 * @param {Record<string, any>} answers The answers so far.
 * @return {number|string|null} The result, or null when it cannot be worked out.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t04
 */
export function evaluate(calculate, answers) {
	const op = calculate && calculate.op
	const args = Array.isArray(calculate && calculate.args) ? calculate.args : []
	if (!OPERATIONS.includes(op) || args.length === 0) {
		return null
	}
	if (op === 'count') {
		const value = answers[String(args[0])]
		return Array.isArray(value) ? value.length : null
	}
	if (op === 'addDays') {
		const from = dateArgument(args[0], answers)
		const days = valuesOf(args[1], answers)
		if (from === null || days.length !== 1 || !isNumeric(days[0])) {
			return null
		}
		return isoOf(from + Math.trunc(Number(days[0])))
	}
	if (op === 'diffDays') {
		const from = dateArgument(args[0], answers)
		const to = dateArgument(args[1], answers)
		return from === null || to === null ? null : to - from
	}
	const numbers = []
	for (const arg of args) {
		const values = valuesOf(arg, answers)
		if (values.length === 0 && !(typeof arg === 'string' && arg.includes('[].'))) {
			return null
		}
		for (const value of values) {
			if (!isNumeric(value)) {
				return null
			}
			numbers.push(Number(value))
		}
	}
	if (numbers.length === 0) {
		return null
	}
	if (op === 'sum') {
		return numbers.reduce((total, number) => total + number, 0)
	}
	if (op === 'multiply') {
		return numbers.reduce((total, number) => total * number, 1)
	}
	return numbers.slice(1).reduce((total, number) => total - number, numbers[0])
}

/**
 * Every calculated field's value, in the order the form declares them, so a
 * later field can read an earlier one.
 *
 * @param {Array<object>} fields The form's fields.
 * @param {Record<string, any>} values The values the resident typed.
 * @return {Record<string, number|string>} The value per calculated field; a field that cannot be worked out is absent.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t04
 */
export function calculatedValues(fields, values) {
	const answers = { ...(values || {}) }
	const out = {}
	for (const field of Array.isArray(fields) ? fields : []) {
		if (!field || !field.calculate) {
			continue
		}
		delete answers[field.name]
		const result = evaluate(field.calculate, answers)
		if (result !== null) {
			answers[field.name] = result
			out[field.name] = result
		}
	}
	return out
}
