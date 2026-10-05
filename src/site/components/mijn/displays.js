// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// What the Mijn omgeving displays draw from a collection's rows
// (site-school-blocks wave 2): rows with a date tile, bars, mark chips,
// cards with a status, and a segmented figure. Plain functions without Vue,
// so node tests them. A field a block names but a row lacks shows nothing,
// never the field's name.
//
// @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards

import { fieldConfigOf, valueLabel } from '../collections/cells.js'

/** The tones a status pill may take. */
const TONES = ['neutral', 'success', 'warning', 'error']

/**
 * A row's value as display text: the collection's word for it when it has
 * one (`fieldConfigs.<field>.valueLabels`), else a string or number, else ''.
 *
 * @param {object} row The row.
 * @param {string} field The field.
 * @param {object} [collection] The collection, for its value labels.
 * @return {string} The text.
 */
export function textOf(row, field, collection) {
	if (!field) {
		return ''
	}
	const value = row?.[field]
	const word = valueLabel(value, fieldConfigOf(collection, field).valueLabels)
	if (word !== undefined) {
		return word
	}
	if (typeof value === 'number' && Number.isFinite(value)) {
		return String(value)
	}
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * Several fields joined: "Sami · Ziek".
 *
 * @param {object} row The row.
 * @param {Array<string>} fields The fields.
 * @param {string} [glue] Between them.
 * @param {object} [collection] The collection, for its value labels.
 * @return {string} The text.
 */
export function joined(row, fields, glue = ' · ', collection = null) {
	return (Array.isArray(fields) ? fields : [])
		.map((field) => textOf(row, field, collection))
		.filter(Boolean)
		.join(glue)
}

/**
 * The id a row is keyed by.
 *
 * @param {object} row The row.
 * @param {number} index Its place.
 * @return {string} The key.
 */
function keyOf(row, index) {
	return String(row?.id || row?.uuid || row?.['@self']?.id || index)
}

/**
 * The tone of a status value, from the block's `statusTones`.
 *
 * @param {object} row The row.
 * @param {object} block The block.
 * @return {string} `neutral`, `success`, `warning` or `error`.
 */
export function toneOf(row, block) {
	const value = row?.[block?.statusField]
	const tone = block?.statusTones?.[String(value ?? '')]
	return TONES.includes(tone) ? tone : 'neutral'
}

/**
 * `display: rows`: a date tile, a title, a sub line, a quote, a status pill
 * with a line under it.
 *
 * @param {Array<object>} rows The rows.
 * @param {object} block The block.
 * @param {object} [collection] The collection, for its value labels.
 * @return {Array<object>} `{key, row, date, title, subtitle, quote, status, tone, statusNote}`.
 */
export function dateRows(rows, block, collection = null) {
	return (Array.isArray(rows) ? rows : []).map((row, index) => ({
		key: keyOf(row, index),
		row,
		date: String(row?.[block?.dateField] ?? '').trim(),
		title: joined(row, block?.titleFields, ' · ', collection),
		subtitle: textOf(row, block?.subtitleField, collection),
		quote: textOf(row, block?.quoteField, collection),
		status: textOf(row, block?.statusField, collection),
		tone: toneOf(row, block),
		statusNote: textOf(row, block?.statusNoteField, collection),
	}))
}

/**
 * The extra parts of a `cards` card: a sub line, the status with its tone
 * and note, and the "coming up" part.
 *
 * @param {object} row The row.
 * @param {object} block The block.
 * @param {object} [collection] The collection, for its value labels.
 * @return {object} `{subtitle, status, tone, note, soon}`.
 */
export function cardParts(row, block, collection = null) {
	return {
		subtitle: joined(row, block?.subtitleFields, ' · ', collection),
		status: textOf(row, block?.statusField, collection),
		tone: toneOf(row, block),
		note: textOf(row, block?.noteField, collection),
		soon: textOf(row, block?.soonField, collection),
	}
}

/**
 * A number from a row, reading a Dutch decimal comma as well.
 *
 * @param {unknown} value The value.
 * @return {number|null} The number.
 */
export function numberOf(value) {
	if (typeof value === 'number') {
		return Number.isFinite(value) ? value : null
	}
	if (typeof value !== 'string' || value.trim() === '') {
		return null
	}
	const number = Number(value.trim().replace(',', '.'))
	return Number.isFinite(number) ? number : null
}

/**
 * A mark in the page language: "7,9" in Dutch, "7.9" in English.
 *
 * @param {number} value The mark.
 * @param {string} [locale] The page language.
 * @return {string} The mark.
 */
export function markText(value, locale) {
	const english = String(locale || 'nl')
		.toLowerCase()
		.startsWith('en')
	return new Intl.NumberFormat(english ? 'en-GB' : 'nl-NL', {
		minimumFractionDigits: 1,
		maximumFractionDigits: 1,
	}).format(value)
}

/**
 * `display: bars`: one bar per row, as a share of `max`.
 *
 * @param {Array<object>} rows The rows.
 * @param {object} block The block.
 * @param {string} [locale] The page language.
 * @param {object} [collection] The collection, for its value labels.
 * @return {Array<object>} `{key, label, value, text, width}`; rows without a number are left out.
 */
export function barRows(rows, block, locale, collection = null) {
	const max = numberOf(block?.max) > 0 ? numberOf(block.max) : 10
	return (Array.isArray(rows) ? rows : [])
		.map((row, index) => {
			const value = numberOf(row?.[block?.valueField])
			return {
				key: keyOf(row, index),
				label: textOf(row, block?.labelField, collection),
				value,
				text: value === null ? '' : markText(value, locale),
				width:
					value === null
						? '0%'
						: `${Math.max(0, Math.min(100, Math.round((value / max) * 100)))}%`,
			}
		})
		.filter((bar) => bar.value !== null && bar.label !== '')
}

/**
 * `display: chips`: per row a label, its marks as chips, and the average.
 * A mark below `lowBelow` is marked low, which the chip says in words too.
 *
 * @param {Array<object>} rows The rows.
 * @param {object} block The block.
 * @param {string} [locale] The page language.
 * @param {object} [collection] The collection, for its value labels.
 * @return {Array<object>} `{key, label, marks: [{text, low}], average, averageLow}`.
 */
export function chipRows(rows, block, locale, collection = null) {
	const low = numberOf(block?.lowBelow)
	const isLow = (value) => low !== null && value !== null && value < low
	return (Array.isArray(rows) ? rows : [])
		.map((row, index) => {
			const values = Array.isArray(row?.[block?.valuesField])
				? row[block.valuesField]
				: []
			const marks = values
				.map((value) => numberOf(value))
				.filter((value) => value !== null)
				.map((value) => ({
					text: markText(value, locale),
					low: isLow(value),
				}))
			const average = numberOf(row?.[block?.averageField])
			return {
				key: keyOf(row, index),
				label: textOf(row, block?.labelField, collection),
				marks,
				average: average === null ? '' : markText(average, locale),
				averageLow: isLow(average),
			}
		})
		.filter((entry) => entry.label !== '')
}

/**
 * `display: segmented` on a kpi block: the share of each segment of one row.
 *
 * @param {object|null} row The row.
 * @param {object} block The block: `segments: [{field, label, tone}]`, `totalField` or `target`.
 * @return {{total: number, segments: Array<object>}|null} Each segment `{field, label, tone, value, width}`.
 */
export function segmentsOf(row, block) {
	if (!row) {
		return null
	}
	const segments = (Array.isArray(block?.segments) ? block.segments : []).map(
		(segment) => ({
			field: segment.field,
			label: String(segment.label || '').trim(),
			tone: ['positive', 'waiting', 'warning'].includes(segment.tone)
				? segment.tone
				: 'positive',
			value: Math.max(0, numberOf(row[segment.field]) ?? 0),
		}),
	)
	const declared = numberOf(row[block?.totalField]) ?? numberOf(block?.target)
	const sum = segments.reduce((total, segment) => total + segment.value, 0)
	const total = declared && declared > 0 ? declared : sum
	if (!total) {
		return {
			total: 0,
			segments: segments.map((segment) => ({ ...segment, width: '0%' })),
		}
	}
	return {
		total,
		segments: segments.map((segment) => ({
			...segment,
			width: `${Math.min(100, (segment.value / total) * 100).toFixed(2)}%`,
		})),
	}
}
