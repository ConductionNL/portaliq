// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Where a card's record stands today (card-status-today): the chip a cards
// block derives from rows of another collection of the same contribution.
// A guardian's child card reads "Ziek gemeld" when one of the guardian's own
// absence reports for that child covers today, "Op school" on any other
// school day, and nothing on a Saturday or Sunday. Nothing is stored; the
// site works it out when it draws the card, against the site's own clock.
//
// Imports only the date reader, so node tests it.
//
// @spec openspec/changes/card-status-today/specs/portal-contribution-contract/spec.md#requirement-a-card-may-say-where-its-record-stands-today

import { toDate } from './dates.js'

/**
 * A row's id, wherever the envelope keeps it.
 *
 * @param {object} row The row.
 * @return {string} The id, or ''.
 */
function idOf(row) {
	return String(row?.id || row?.uuid || row?.['@self']?.id || '')
}

/**
 * A value as a calendar day at midnight, or null.
 *
 * @param {string|null|undefined} value A date, a date-time or empty.
 * @return {Date|null} The day.
 */
function dayOf(value) {
	const text = String(value ?? '').trim()
	const date = toDate(text.length > 10 ? text.slice(0, 10) : text)
	return date
		? new Date(date.getFullYear(), date.getMonth(), date.getDate())
		: null
}

/**
 * Whether a row of the lookup collection covers today for this card.
 *
 * @param {object} row The lookup row.
 * @param {{cardId: string, status: object, today: Date}} check The card's record id, the block's `status` and today at midnight.
 * @return {boolean} True when it does.
 */
function covers(row, check) {
	const { cardId, status, today } = check
	if (String(row?.[status.matchField] ?? '') !== cardId) {
		return false
	}
	if (!passesOnly(row, status.only)) {
		return false
	}
	const from = dayOf(row?.[status.fromField])
	const to = dayOf(row?.[status.toField]) || from
	return from !== null && from <= today && today <= to
}

/**
 * @param {object} row The lookup row.
 * @param {{field: string, in: Array<string>}|undefined} only The block's `only` rule.
 * @return {boolean} Whether the row passes the rule, or there is none.
 */
function passesOnly(row, only) {
	return !only || only.in.includes(String(row?.[only.field] ?? ''))
}

/**
 * No chip until the rows are in: "Op school" for a child who is reported
 * sick, even for a moment, is the one wrong answer this must not give.
 *
 * @param {object|null} status The block's `status`.
 * @param {{loading?: boolean, failed?: boolean}|null} lookup The lookup collection as loaded.
 * @return {boolean} Whether the chip can be worked out.
 */
function isReady(status, lookup) {
	return Boolean(status && lookup && !lookup.loading && !lookup.failed)
}

/**
 * @param {Date} now The site's clock.
 * @return {Date} Today at midnight.
 */
function midnightOf(now) {
	return new Date(now.getFullYear(), now.getMonth(), now.getDate())
}

/**
 * @param {object} status The block's `status`.
 * @param {Date} today Today.
 * @return {boolean} Whether today is a weekend day on a school-days-only block.
 */
function isDayOff(status, today) {
	const day = today.getDay()
	return Boolean(status.schoolDaysOnly) && (day === 0 || day === 6)
}

/**
 * @param {object} status The block's `status`.
 * @param {boolean} covered Whether a row covers today.
 * @return {{text: string, tone: string}|null} The chip for a covered day, or the other chip, or none.
 */
function chipOf(status, covered) {
	if (covered) {
		return { text: status.label, tone: status.tone || 'neutral' }
	}
	return status.otherLabel
		? { text: status.otherLabel, tone: status.otherTone || 'neutral' }
		: null
}

/**
 * @param {{objects?: Array<object>}} lookup The lookup collection as loaded.
 * @return {Array<object>} Its rows, or none.
 */
function rowsOf(lookup) {
	return Array.isArray(lookup.objects) ? lookup.objects : []
}

/**
 * The chip of one card, or null when it shows none.
 *
 * @param {object} card The card's row.
 * @param {object|null} status The block's `status`, from the server.
 * @param {{loading?: boolean, failed?: boolean, objects?: Array<object>}|null} lookup The lookup collection as loaded.
 * @param {Date} [now] The site's clock (a test passes a fixed day).
 * @return {{text: string, tone: string}|null} The chip.
 * @spec openspec/changes/card-status-today/specs/portal-contribution-contract/spec.md#requirement-a-card-may-say-where-its-record-stands-today
 */
export function cardStatus(card, status, lookup, now = new Date()) {
	if (!isReady(status, lookup)) {
		return null
	}
	const today = midnightOf(now)
	const cardId = idOf(card)
	if (isDayOff(status, today) || cardId === '') {
		return null
	}
	const check = { cardId, status, today }
	return chipOf(status, rowsOf(lookup).some((row) => covers(row, check)))
}
