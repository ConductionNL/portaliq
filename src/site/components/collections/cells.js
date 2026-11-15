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
// Imports plain modules only, so tests/site-collections.spec.mjs runs it as node.
//
// @spec openspec/specs/site-portal-parity/spec.md#requirement-a-collection-table-must-follow-the-declared-columns-req-srp-015

import { DAY_AND_MINUTE } from '../../lib/dayAndMinute.js'

/** Keys a row carries for the machine, never shown as a column. */
const ENVELOPE = new Set(['@self', '_files', '_columns', 'id', 'uuid'])

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
	return ownIdOf(row) || undefined
}

/**
 * A row's id, its uuid, else its envelope's id.
 *
 * @param {object} row The row.
 * @return {string|undefined} The id as given.
 */
function ownIdOf(row) {
	return row.id || row.uuid || row['@self']?.id
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
	const filled = objects
		.flatMap((row) => valuesIn(row, field))
		.filter(isFilled)
	return filled.length > 0 && filled.every((item) => isIdentifier(item))
}

/**
 * A row's values of one field: each item of a list, else the one value.
 *
 * @param {object|null} row The row.
 * @param {string} field The field.
 * @return {Array<unknown>} The values.
 */
function valuesIn(row, field) {
	const value = row?.[field]
	return Array.isArray(value) ? value : [value]
}

/**
 * Whether a value is filled: not null, undefined or ''.
 *
 * @param {unknown} item The value.
 * @return {boolean}
 */
function isFilled(item) {
	return item !== null && item !== undefined && item !== ''
}

/**
 * The columns of a table: the app's declared `columns`, else every field the
 * rows carry, minus the envelope and fields that hold only identifiers.
 *
 * A heading is the column's own label, else the field's label in
 * `fieldConfigs` (which the server fills from the schema property's title
 * when the app wrote none), else the field written as words.
 *
 * @param {object} collection The collection.
 * @param {Array<object>} objects The rows.
 * @return {Array<{field: string, label: string, render: string}>}
 * @spec openspec/changes/collection-column-labels/specs/portal-contribution-contract/spec.md#requirement-a-collection-field-must-read-under-its-schema-title-when-the-app-gave-no-label
 */
export function deriveColumns(collection, objects) {
	const declared = declaredColumns(collection)
	if (declared.length > 0) {
		return declared.map((c) => declaredColumn(collection, c))
	}
	const rows = Array.isArray(objects) ? objects : []
	return rowFields(rows)
		.filter((field) => !onlyIdentifiers(rows, field))
		.map((field) => ({
			field,
			label: columnLabel({
				field,
				label: fieldConfigOf(collection, field).label,
			}),
			render: 'text',
		}))
}

/**
 * The columns the app declared that name a field.
 *
 * @param {object} collection The collection.
 * @return {Array<object>} The columns.
 */
function declaredColumns(collection) {
	return Array.isArray(collection?.columns)
		? collection.columns.filter(namesField)
		: []
}

/**
 * Whether a declared column names a field.
 *
 * @param {object|null} column The declared column.
 * @return {boolean}
 */
function namesField(column) {
	return Boolean(column) && typeof column.field === 'string' && column.field !== ''
}

/**
 * A declared column with its heading, render and value labels: its own,
 * else the field config's.
 *
 * @param {object} collection The collection.
 * @param {object} column The declared column.
 * @return {object} The column.
 */
function declaredColumn(collection, column) {
	const config = fieldConfigOf(collection, column.field)
	const valueLabels = column.valueLabels || config.valueLabels
	return {
		...column,
		label: columnLabel({ label: config.label, ...withLabel(column) }),
		render: column.render || 'text',
		...(valueLabels ? { valueLabels } : {}),
	}
}

/**
 * Every field the rows carry, in the order first met, minus the envelope.
 *
 * @param {Array<object>} rows The rows.
 * @return {Array<string>} The fields.
 */
function rowFields(rows) {
	const fields = []
	for (const row of rows) {
		for (const key of ownFields(row)) {
			if (!fields.includes(key)) {
				fields.push(key)
			}
		}
	}
	return fields
}

/**
 * A row's own fields, minus the envelope.
 *
 * @param {object|null} row The row.
 * @return {Array<string>} The fields.
 */
function ownFields(row) {
	return Object.keys(row || {}).filter((key) => !ENVELOPE.has(key))
}

/**
 * A column without a blank label, so the field's config label can stand in.
 *
 * @param {{label?: string}} column The declared column.
 * @return {object} The column, its label dropped when blank.
 */
function withLabel(column) {
	if (typeof column.label === 'string' && column.label.trim() !== '') {
		return column
	}
	const rest = { ...column }
	delete rest.label
	return rest
}

/**
 * A column as one row reads it: the portal's setting for the row's case type
 * (`_columns`, stamped by the server) replaces the column's `render` and
 * `linkLabel`.
 *
 * @param {object} column The declared column.
 * @param {object} row The row.
 * @return {object} The column for this row.
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
 */
export function rowColumn(column, row) {
	const own = row?._columns?.[column?.field]
	if (!own || typeof own.render !== 'string') {
		return column
	}
	return { ...column, render: own.render, linkLabel: own.linkLabel }
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
	const byField = columnsByField(collection)
	const declared = declaredDetailFields(collection)
	const fields =
		declared.length > 0
			? declared
			: Object.keys(row || {}).filter((key) => !ENVELOPE.has(key))
	return fields.map((field) => {
		const config = fieldConfigOf(collection, field)
		const own = byField.get(field) || { field, label: config.label }
		const column = rowColumn(own, row)
		return {
			field,
			label: columnLabel(column),
			render: column.render || 'text',
			linkLabel: column.linkLabel,
			valueLabels: column.valueLabels || config.valueLabels,
			declared: declared.length > 0,
		}
	})
}

/**
 * The collection's columns by the field they show.
 *
 * @param {object} collection The collection.
 * @return {Map<string, object>} The columns.
 */
function columnsByField(collection) {
	const byField = new Map()
	for (const column of Array.isArray(collection?.columns)
		? collection.columns
		: []) {
		if (column && typeof column.field === 'string') {
			byField.set(column.field, column)
		}
	}
	return byField
}

/**
 * The fields the app declared for the detail card.
 *
 * @param {object} collection The collection.
 * @return {Array<string>} The fields.
 */
function declaredDetailFields(collection) {
	return Array.isArray(collection?.detail?.fields)
		? collection.detail.fields.filter((f) => typeof f === 'string' && f !== '')
		: []
}

/**
 * The collection's own config for one field (`fieldConfigs.<field>`): a
 * `label` and `valueLabels` for a field the detail card shows that is no
 * column. A column's own label and value labels win.
 *
 * @param {object} collection The collection.
 * @param {string} field The field.
 * @return {{label?: string, valueLabels?: object}}
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-say-how-the-values-of-any-of-its-fields-read
 */
export function fieldConfigOf(collection, field) {
	const configs = collection?.fieldConfigs
	if (!hasOwnConfig(configs, field)) {
		return {}
	}
	const config = configs[field]
	return config && typeof config === 'object' ? config : {}
}

/**
 * Whether the field configs carry one of their own for a field.
 *
 * @param {unknown} configs The collection's `fieldConfigs`.
 * @param {string} field The field.
 * @return {boolean}
 */
function hasOwnConfig(configs, field) {
	return Boolean(configs) && typeof configs === 'object' && Object.hasOwn(configs, field)
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
			? date.toLocaleString(locale, DAY_AND_MINUTE)
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
	if (typeof value !== 'object') {
		return scalarText(value, { locale, t })
	}
	if (depth > 2) {
		return ''
	}
	return Array.isArray(value)
		? listText(value, { locale, t, depth })
		: objectText(value, { locale, t, depth })
}

/**
 * A value that is no list or object, written for a person: yes or no, a
 * number in the language's notation, a date as a date, an identifier as
 * nothing; anything else reads as nothing.
 *
 * @param {unknown} value The value.
 * @param {{locale: string, t: (key: string) => string}} context The language and the translator.
 * @return {string}
 */
function scalarText(value, { locale, t }) {
	if (typeof value === 'boolean') {
		return value ? t('Yes') : t('No')
	}
	if (typeof value === 'number') {
		return numberText(value, locale)
	}
	if (typeof value !== 'string') {
		return ''
	}
	return stringText(value, locale)
}

/**
 * A number in the language's notation; nothing for one that is not finite.
 *
 * @param {number} value The number.
 * @param {string} locale The language.
 * @return {string}
 */
function numberText(value, locale) {
	return Number.isFinite(value) ? value.toLocaleString(locale) : ''
}

/**
 * A string written for a person: a date as a date, an identifier as
 * nothing, else itself.
 *
 * @param {string} value The string.
 * @param {string} locale The language.
 * @return {string}
 */
function stringText(value, locale) {
	const text = value.trim()
	if (isIdentifier(text)) {
		return ''
	}
	if (ISO_DATE.test(text)) {
		return formatMoment(`${text}T00:00:00`, false, locale)
	}
	return ISO_DATETIME.test(text) ? formatMoment(text, true, locale) : value
}

/**
 * A list written for a person. A list in a cell reads one value per line;
 * the table keeps the breaks (white-space: pre-line). Joined with commas,
 * "Rekenen: 7,9" and "Taal: 8,3" ran together with their Dutch decimal
 * commas (array-cells-one-line-per-item). A nested list joins with commas.
 *
 * @param {Array<unknown>} list The list.
 * @param {{locale: string, t: (key: string) => string, depth: number}} context The language, the translator and how deep the list is nested.
 * @return {string}
 */
function listText(list, { locale, t, depth }) {
	return list
		.map((item) => readable(item, { locale, t }, depth + 1))
		.filter((text) => text !== '')
		.join(depth === 0 ? '\n' : ', ')
}

/**
 * An object written for a person: its name, else its readable parts.
 *
 * @param {object} value The object.
 * @param {{locale: string, t: (key: string) => string, depth: number}} context The language, the translator and how deep the object is nested.
 * @return {string}
 */
function objectText(value, { locale, t, depth }) {
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

/**
 * The label the app declared for a value, if it declared one.
 *
 * @param {unknown} value The value.
 * @param {Record<string, string>|undefined} valueLabels The column's labels.
 * @return {string|undefined} The label, or undefined for an unlabelled value.
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read
 */
export function valueLabel(value, valueLabels) {
	if (!valueLabels || typeof valueLabels !== 'object') {
		return undefined
	}
	if (!isLabelKey(value)) {
		return undefined
	}
	const key = String(value)
	if (!Object.hasOwn(valueLabels, key)) {
		return undefined
	}
	return filledLabel(valueLabels[key])
}

/**
 * Whether a value can carry a declared label: a string or a number.
 *
 * @param {unknown} value The value.
 * @return {boolean}
 */
function isLabelKey(value) {
	return typeof value === 'string' || typeof value === 'number'
}

/**
 * A declared label that is text and not blank, else undefined.
 *
 * @param {unknown} label The declared label.
 * @return {string|undefined}
 */
function filledLabel(label) {
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

/** How a cell of each render kind is written; any other kind reads as readable() writes it. */
const RENDERED = {
	boolean: (value, { t }) => (value ? t('Yes') : t('No')),
	date: (value, { locale }) => formatMoment(value, false, locale),
	datetime: (value, { locale }) => formatMoment(value, true, locale),
	currency: (value, { locale }) => {
		try {
			return new Intl.NumberFormat(locale, {
				style: 'currency',
				currency: 'EUR',
			}).format(Number(value))
		} catch {
			return String(value)
		}
	},
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
	const format = Object.hasOwn(RENDERED, render) ? RENDERED[render] : readable
	return format(value, { locale, t })
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

/** The schemes a `qr` value may have besides http(s) and a site path: the two EUDI wallet schemes. */
const QR_SCHEMES = /^(openid-credential-offer|openid4vp):\/\//i

/**
 * The address a `qr` cell draws as a code and links to: http(s), a
 * site-relative path made absolute on the site's own origin (a phone camera
 * cannot resolve a path), or an `openid-credential-offer://` or
 * `openid4vp://` value. Anything else, `javascript:` included, is no
 * address: it reads as plain text, with no code and no link.
 *
 * @param {unknown} value The value.
 * @param {string} origin The site's origin, to make a path absolute.
 * @return {string} The address, or '' when it is not one.
 *
 * @spec openspec/changes/link-field-qr-code/tasks.md#t3
 */
export function qrHref(value, origin = '') {
	if (typeof value !== 'string') {
		return ''
	}
	const href = value.trim()
	if (QR_SCHEMES.test(href) && /^[!-~]+$/.test(href)) {
		return href
	}
	return onOrigin(safeHref(href), origin)
}

/**
 * A safe address with a site-relative path made absolute on the site's
 * origin; a path without an origin is no address.
 *
 * @param {string} safe The safe address, or ''.
 * @param {string} origin The site's origin, or ''.
 * @return {string} The address, or ''.
 */
function onOrigin(safe, origin) {
	if (safe.startsWith('/') && origin !== '') {
		return `${String(origin).replace(/\/$/, '')}${safe}`
	}
	return safe.startsWith('/') ? '' : safe
}

/**
 * The words of a link or code: the column's `linkLabel`, else its label, else
 * the field name.
 *
 * @param {{linkLabel?: string, label?: string, field?: string}} column The column or field.
 * @return {string} The words.
 *
 * @spec openspec/changes/link-field-qr-code/tasks.md#t5
 */
export function qrLabel(column) {
	for (const key of ['linkLabel', 'label', 'field']) {
		if (typeof column?.[key] === 'string' && column[key].trim() !== '') {
			return column[key].trim()
		}
	}
	return ''
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
