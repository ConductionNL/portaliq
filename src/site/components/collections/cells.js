// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// What a resident reads in a cell of a contributed collection: the column's
// label, and the value written for a person rather than for a database.
//
// The React portal printed `String(value)`, so a guardian on learniq's parent
// collections saw field names as headings, bare uuids, ISO timestamps and
// `[object Object]` (site-parity finding F21). Here a column takes the label
// the app declared, a field without one is written as words, an identifier
// alone in a cell is left out, a date reads as a date, and a nested value
// reads as the readable parts it holds. A value the app labelled in the
// column's `valueLabels` reads as that label ("approved" as "Goedgekeurd");
// any other value reads as before (contribution-value-labels).
//
// Imports nothing, so tests/site-collections.spec.mjs runs it as node.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-collection-table-must-follow-the-declared-columns-req-srp-015

/** Keys a row carries for the machine, never shown as a column. */
const ENVELOPE = new Set(['@self', '_files', 'id', 'uuid'])

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/
const ISO_DATETIME =
	/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/

/** Keys that name a nested object, in the order they are preferred. */
const NAME_KEYS = ['label', 'title', 'name', 'displayName']

/**
 * Whether a value is an identifier and nothing else.
 *
 * @param {unknown} value The value.
 * @return {boolean}
 */
export function isIdentifier(value) {
	return typeof value === 'string' && UUID.test(value.trim())
}

/**
 * A field name written as words: `givenName` and `given_name` both become
 * "Given name". Only used when the app declared no label.
 *
 * @param {string} field The field name.
 * @return {string}
 */
export function humanise(field) {
	const words = String(field || '')
		.replace(/^[@_]+/, '')
		.replace(/([a-z0-9])([A-Z])/g, '$1 $2')
		.replace(/[_-]+/g, ' ')
		.trim()
		.toLowerCase()
	return words === '' ? '' : words.charAt(0).toUpperCase() + words.slice(1)
}

/**
 * The heading of a column: the label the app declared, else the field as words.
 *
 * @param {{field: string, label?: string}} column The column.
 * @return {string}
 */
export function columnLabel(column) {
	if (column && typeof column.label === 'string' && column.label.trim() !== '') {
		return column.label
	}
	return humanise(column?.field)
}

/**
 * The id of a row, wherever the envelope keeps it.
 *
 * @param {object} row The row.
 * @return {string|undefined}
 */
export function rowIdOf(row) {
	if (!row || typeof row !== 'object') {
		return undefined
	}
	return row.id || row.uuid || row['@self']?.id || undefined
}

/**
 * Whether every filled value of a field is an identifier, so a column of it
 * would show a resident nothing but codes.
 *
 * @param {Array<object>} objects The rows.
 * @param {string} field The field.
 * @return {boolean}
 */
function onlyIdentifiers(objects, field) {
	let filled = 0
	for (const row of objects) {
		const value = row?.[field]
		const values = Array.isArray(value) ? value : [value]
		for (const item of values) {
			if (item === null || item === undefined || item === '') {
				continue
			}
			filled++
			if (!isIdentifier(item)) {
				return false
			}
		}
	}
	return filled > 0
}

/**
 * The columns of a table: the app's declared `columns`, else every field the
 * rows carry, minus the envelope and fields that hold only identifiers.
 *
 * @param {object} collection The collection.
 * @param {Array<object>} objects The rows.
 * @return {Array<{field: string, label: string, render: string}>}
 */
export function deriveColumns(collection, objects) {
	const declared = Array.isArray(collection?.columns)
		? collection.columns.filter(
				(c) => c && typeof c.field === 'string' && c.field !== '',
			)
		: []
	if (declared.length > 0) {
		return declared.map((c) => {
			const valueLabels =
				c.valueLabels || fieldConfigOf(collection, c.field).valueLabels
			return {
				...c,
				label: columnLabel(c),
				render: c.render || 'text',
				...(valueLabels ? { valueLabels } : {}),
			}
		})
	}
	const rows = Array.isArray(objects) ? objects : []
	const fields = []
	for (const row of rows) {
		for (const key of Object.keys(row || {})) {
			if (!ENVELOPE.has(key) && !fields.includes(key)) {
				fields.push(key)
			}
		}
	}
	return fields
		.filter((field) => !onlyIdentifiers(rows, field))
		.map((field) => ({ field, label: humanise(field), render: 'text' }))
}

/**
 * The fields of a detail card with their labels: the app's `detail.fields`,
 * else the row's own fields minus the envelope. A label comes from a column
 * that shows the same field, else the field as words.
 *
 * @param {object} collection The collection.
 * @param {object} row The record.
 * @return {Array<{field: string, label: string, render: string, valueLabels?: object, declared: boolean}>}
 */
export function detailFields(collection, row) {
	const byField = new Map()
	for (const column of Array.isArray(collection?.columns)
		? collection.columns
		: []) {
		if (column && typeof column.field === 'string') {
			byField.set(column.field, column)
		}
	}
	const declared = Array.isArray(collection?.detail?.fields)
		? collection.detail.fields.filter((f) => typeof f === 'string' && f !== '')
		: []
	const fields =
		declared.length > 0
			? declared
			: Object.keys(row || {}).filter((key) => !ENVELOPE.has(key))
	return fields.map((field) => {
		const config = fieldConfigOf(collection, field)
		const column = byField.get(field) || { field, label: config.label }
		return {
			field,
			label: columnLabel(column),
			render: column.render || 'text',
			valueLabels: column.valueLabels || config.valueLabels,
			declared: declared.length > 0,
		}
	})
}

/**
 * The collection's own config for one field (`fieldConfigs.<field>`): a
 * `label` and `valueLabels` for a field the detail card shows that is no
 * column. A column's own label and value labels win.
 *
 * @param {object} collection The collection.
 * @param {string} field The field.
 * @return {{label?: string, valueLabels?: object}}
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-say-how-the-values-of-any-of-its-fields-read
 */
export function fieldConfigOf(collection, field) {
	const configs = collection?.fieldConfigs
	if (!configs || typeof configs !== 'object' || !Object.hasOwn(configs, field)) {
		return {}
	}
	const config = configs[field]
	return config && typeof config === 'object' ? config : {}
}

/**
 * Format a date or a moment for the resident's language.
 *
 * @param {string|number} value The value.
 * @param {boolean} withTime Whether the time of day matters.
 * @param {string} locale The language.
 * @return {string}
 */
function formatMoment(value, withTime, locale) {
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return String(value)
	}
	try {
		return withTime
			? date.toLocaleString(locale)
			: date.toLocaleDateString(locale)
	} catch {
		return String(value)
	}
}

/**
 * A value written for a person. An identifier alone reads as nothing, a date
 * as a date, a list as its readable items, and a nested object as its name or
 * else its readable parts.
 *
 * @param {unknown} value The value.
 * @param {object} context How to write it.
 * @param {string} context.locale The language.
 * @param {(key: string) => string} context.t The translator.
 * @param {number} [depth] How deep this value is nested.
 * @return {string}
 */
export function readable(value, { locale, t }, depth = 0) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	if (typeof value === 'boolean') {
		return value ? t('Yes') : t('No')
	}
	if (typeof value === 'number') {
		return Number.isFinite(value) ? value.toLocaleString(locale) : ''
	}
	if (typeof value === 'string') {
		const text = value.trim()
		if (isIdentifier(text)) {
			return ''
		}
		if (ISO_DATE.test(text)) {
			return formatMoment(`${text}T00:00:00`, false, locale)
		}
		if (ISO_DATETIME.test(text)) {
			return formatMoment(text, true, locale)
		}
		return value
	}
	if (depth > 2) {
		return ''
	}
	if (Array.isArray(value)) {
		// A list in a cell reads one value per line; the table keeps the
		// breaks (white-space: pre-line). Joined with commas, "Rekenen: 7,9"
		// and "Taal: 8,3" ran together with their Dutch decimal commas
		// (array-cells-one-line-per-item).
		return value
			.map((item) => readable(item, { locale, t }, depth + 1))
			.filter((text) => text !== '')
			.join(depth === 0 ? '\n' : ', ')
	}
	if (typeof value === 'object') {
		for (const key of NAME_KEYS) {
			if (typeof value[key] === 'string' && value[key].trim() !== '') {
				return value[key]
			}
		}
		return Object.entries(value)
			.filter(([key]) => !ENVELOPE.has(key))
			.map(([, item]) => readable(item, { locale, t }, depth + 1))
			.filter((text) => text !== '')
			.join(', ')
	}
	return ''
}

/**
 * The label the app declared for a value, if it declared one.
 *
 * @param {unknown} value The value.
 * @param {Record<string, string>|undefined} valueLabels The column's labels.
 * @return {string|undefined} The label, or undefined for an unlabelled value.
 * @spec openspec/changes/contribution-value-labels/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read
 */
export function valueLabel(value, valueLabels) {
	if (!valueLabels || typeof valueLabels !== 'object') {
		return undefined
	}
	if (typeof value !== 'string' && typeof value !== 'number') {
		return undefined
	}
	const key = String(value)
	if (!Object.hasOwn(valueLabels, key)) {
		return undefined
	}
	const label = valueLabels[key]
	return typeof label === 'string' && label.trim() !== '' ? label : undefined
}

/**
 * A value read through the column's labels: the label when the app declared
 * one, each item of a list on its own line, else undefined.
 *
 * @param {unknown} value The value.
 * @param {Record<string, string>|undefined} valueLabels The column's labels.
 * @param {object} context How to write an unlabelled list item.
 * @return {string|undefined}
 */
function labelled(value, valueLabels, context) {
	if (Array.isArray(value)) {
		if (!value.some((item) => valueLabel(item, valueLabels) !== undefined)) {
			return undefined
		}
		return value
			.map(
				(item) =>
					valueLabel(item, valueLabels) ?? readable(item, context, 1),
			)
			.filter((text) => text !== '')
			.join('\n')
	}
	return valueLabel(value, valueLabels)
}

/**
 * The text of one cell, following the column's `render`. A value the column
 * labels reads as its label, whatever the render kind.
 *
 * @param {unknown} value The value.
 * @param {string} render The column's render kind.
 * @param {object} context How to write it.
 * @param {string} context.locale The language.
 * @param {(key: string) => string} context.t The translator.
 * @param {Record<string, string>} [context.valueLabels] The column's labels.
 * @return {string}
 */
export function formatCell(value, render, { locale, t, valueLabels }) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	const label = labelled(value, valueLabels, { locale, t })
	if (label !== undefined) {
		return label
	}
	switch (render) {
		case 'boolean':
			return value ? t('Yes') : t('No')
		case 'date':
			return formatMoment(value, false, locale)
		case 'datetime':
			return formatMoment(value, true, locale)
		case 'currency':
			try {
				return new Intl.NumberFormat(locale, {
					style: 'currency',
					currency: 'EUR',
				}).format(Number(value))
			} catch {
				return String(value)
			}
		default:
			return readable(value, { locale, t })
	}
}

/**
 * The address a `link` cell may point at: an http(s) or site-relative address,
 * never a `javascript:` or other scheme.
 *
 * @param {unknown} value The value.
 * @return {string} The address, or '' when it is not one.
 */
export function safeHref(value) {
	if (typeof value !== 'string') {
		return ''
	}
	const href = value.trim()
	return /^https?:\/\//i.test(href)
		|| (href.startsWith('/') && !href.startsWith('//'))
		? href
		: ''
}

/**
 * The modifier of a `badge` cell, from its value: lower case, letters, digits
 * and dashes only.
 *
 * @param {unknown} value The value.
 * @return {string}
 */
export function badgeModifier(value) {
	return String(value ?? '')
		.toLowerCase()
		.replace(/[^a-z0-9-]+/g, '-')
		.replace(/^-+|-+$/g, '')
}
