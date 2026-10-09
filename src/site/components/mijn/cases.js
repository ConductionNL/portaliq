// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of the case cards and process steps: which cases a `cases`
// block shows, where a case stands ("Stap 2 van 4"), its answer date and
// whose turn it is. No Vue, so tests/mijn-cases.spec.mjs runs it as node.
//
// @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002

import { caseStatus, caseTitle } from '../../../shared/myCases.js'

/** How many cases a `cases` block shows when it declares no limit (design D4). */
export const DEFAULT_CASE_LIMIT = 4

/**
 * Whether a collection row is a closed case: its `_closed` mark when the
 * server stamped one, else a value in the collection's `closedField`.
 *
 * @param {object} row The case row.
 * @param {object} collection The `cases` collection.
 * @return {boolean}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
 */
export function isClosedCase(row, collection) {
	if (typeof row?._closed === 'boolean') {
		return row._closed
	}
	const field = collection?.closedField
	if (!field) {
		return false
	}
	const value = row?.[field]
	return value !== undefined && value !== null && value !== '' && value !== false
}

/**
 * The cases a `cases` block shows: open ones only when it says `open`, at
 * most its limit, and whether there are more.
 *
 * @param {Array<object>} rows The collection's rows.
 * @param {object} block The block (`open`, `limit`).
 * @param {object} collection The collection.
 * @return {{rows: Array<object>, more: boolean}}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
 */
export function casesOnScreen(rows, block, collection) {
	const limit =
		Number.isInteger(block?.limit) && block.limit >= 1
			? block.limit
			: DEFAULT_CASE_LIMIT
	const wanted = (Array.isArray(rows) ? rows : [])
		.filter(Boolean)
		.filter((row) => block?.open !== true || !isClosedCase(row, collection))
	return { rows: wanted.slice(0, limit), more: wanted.length > limit }
}

/**
 * Where a case stands, from its steps: the current step's position, else
 * the step after the last done one, out of all steps. Null without steps.
 *
 * @param {Array<{state: string}>|null} steps The steps provider's answer.
 * @return {{current: number, total: number}|null}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
 */
export function stepPosition(steps) {
	const list = Array.isArray(steps) ? steps : []
	if (list.length === 0) {
		return null
	}
	const current = list.findIndex((step) => step?.state === 'current')
	if (current >= 0) {
		return { current: current + 1, total: list.length }
	}
	const done = list.filter((step) => step?.state === 'done').length
	return { current: Math.min(done + 1, list.length), total: list.length }
}

/**
 * A day in words: "30 oktober", with the year when it is not this year's.
 *
 * @param {string} value An ISO date.
 * @param {Date} today Today.
 * @param {string} locale The page language.
 * @return {string} The words, or '' for no date.
 */
export function dayInWords(value, today, locale) {
	const date = value ? new Date(value) : null
	if (!date || Number.isNaN(date.getTime())) {
		return ''
	}
	const options = { day: 'numeric', month: 'long' }
	if (date.getFullYear() !== (today || new Date()).getFullYear()) {
		options.year = 'numeric'
	}
	return date.toLocaleDateString(
		String(locale || 'nl').startsWith('en') ? 'en-GB' : 'nl-NL',
		options,
	)
}

/**
 * Whose turn it is, in the words the collection gives the turn field's
 * value (`fieldConfigs.<turnField>.valueLabels`). '' when there are none:
 * a raw value is a code, not a sentence.
 *
 * @param {object} row The case row.
 * @param {object} collection The `cases` collection.
 * @return {string}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
 */
export function turnSentence(row, collection) {
	const field = collection?.turnField
	if (!field) {
		return ''
	}
	const value = row?.[field]
	const labels = collection?.fieldConfigs?.[field]?.valueLabels
	if (value === undefined || value === null || !labels) {
		return ''
	}
	const words = labels[String(value)]
	return typeof words === 'string' ? words : ''
}

/**
 * What a case card shows for one row.
 *
 * @param {object} row The case row.
 * @param {object} collection The `cases` collection, or null on "Mijn zaken".
 * @param {object} context The context.
 * @param {(key: string, vars?: object) => string} context.tr The translator.
 * @param {string} context.locale The page language.
 * @param {Date} context.today Today.
 * @param {Array<object>|null} [context.steps] The case's steps, when read.
 * @param {Array<string>} [context.yourTurn] The turn values at which the resident must act (the board card's tag).
 * @return {object} `{title, typeName, status, reference, number, due, dueDay, readyBy, turn, yourTurn, position, closed}`.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
 */
export function caseCard(
	row,
	collection,
	{ tr, locale, today, steps = null, yourTurn = [] },
) {
	const title = caseTitle(row)
	const reference = ['reference', 'identifier']
		.map((field) => row?.[field])
		.find(
			(value) =>
				typeof value === 'string' && value.trim() !== '' && value !== title,
		)
	const dueField = collection?.dueField
	const dueDay = dueField ? dayInWords(row?.[dueField], today, locale) : ''
	const position = stepPosition(steps)
	const turnValue = collection?.turnField ? row?.[collection.turnField] : undefined
	return {
		// Never an empty or raw-id title (mijn-messages-follow-the-boards).
		title: title || tr('Case'),
		typeName: typeof row?._caseTypeName === 'string' ? row._caseTypeName : '',
		status: caseStatus(row),
		reference: reference ? tr('Case {reference}', { reference }) : '',
		// The bare number for the board card, where "Zaak" is not repeated.
		number: reference || '',
		due: dueDay ? tr('Answer by {date}', { date: dueDay }) : '',
		dueDay,
		readyBy: dueDay ? tr('ready by {date}', { date: dueDay }) : '',
		turn: turnSentence(row, collection),
		yourTurn:
			Array.isArray(yourTurn)
			&& turnValue !== undefined
			&& turnValue !== null
			&& yourTurn.map(String).includes(String(turnValue)),
		position: position
			? { ...position, text: tr('Step {current} of {total}', position) }
			: null,
		closed: isClosedCase(row, collection),
	}
}
