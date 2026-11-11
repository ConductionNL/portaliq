// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of the case cards and process steps: which cases a `cases`
// block shows, where a case stands ("Stap 2 van 4"), its answer date and
// whose turn it is. No Vue, so tests/mijn-cases.spec.mjs runs it as node.
//
// @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002

import { dueValueOf } from '../../../shared/casePage.js'
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
	return isFilledMark(row?.[field])
}

/**
 * Whether a closed field holds a mark: anything but nothing, '' or false.
 *
 * @param {unknown} value The field's value.
 * @return {boolean}
 */
function isFilledMark(value) {
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
 * A day in words: "30 oktober", with the year when it is not this year's or
 * when `withYear` asks for it.
 *
 * @param {string} value An ISO date.
 * @param {Date} today Today.
 * @param {string} locale The page language.
 * @param {boolean} [withYear] Always name the year.
 * @return {string} The words, or '' for no date.
 */
export function dayInWords(value, today, locale, withYear = false) {
	const date = value ? new Date(value) : null
	if (!date || Number.isNaN(date.getTime())) {
		return ''
	}
	const options = { day: 'numeric', month: 'long' }
	if (
		withYear ||
		date.getFullYear() !== (today || new Date()).getFullYear()
	) {
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
	return wordsFor(row?.[field], collection?.fieldConfigs?.[field]?.valueLabels)
}

/**
 * The words the labels give a value, or '' without them.
 *
 * @param {unknown} value The turn field's value.
 * @param {Record<string, string>|undefined} labels The field's value labels.
 * @return {string}
 */
function wordsFor(value, labels) {
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
 * @return {object} `{title, typeName, status, reference, number, numberLabel, due, dueDay, readyBy, turn, yourTurn, position, closed}`.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-case-card-must-show-what-a-resident-needs-to-decide-whether-to-open-it-req-smo-002
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
 */
export function caseCard(
	row,
	collection,
	{ tr, locale, today, steps = null, yourTurn = [] },
) {
	const title = caseTitle(row)
	const reference = referenceOf(row, title)
	const position = stepPosition(steps)
	return {
		title,
		typeName: typeof row?._caseTypeName === 'string' ? row._caseTypeName : '',
		status: caseStatus(row),
		reference: reference ? tr('Case {reference}', { reference }) : '',
		// The bare number for the board card, where "Zaak" is not repeated.
		number: reference || '',
		numberLabel: reference ? tr('Case number') : '',
		...dueWords(row, { collection, tr, locale, today }),
		turn: turnSentence(row, collection),
		yourTurn: isYourTurn(row, { collection, yourTurn }),
		position: position
			? { ...position, text: tr('Step {current} of {total}', position) }
			: null,
		closed: isClosedCase(row, collection),
	}
}

/**
 * A case's reference or identifier, when it is not its title.
 *
 * @param {object} row The case row.
 * @param {string} title The case's title.
 * @return {string|undefined} The reference.
 */
function referenceOf(row, title) {
	return ['reference', 'identifier']
		.map((field) => row?.[field])
		.find(
			(value) =>
				typeof value === 'string' && value.trim() !== '' && value !== title,
		)
}

/**
 * The due day of a case in words, and the sentences that carry it. The legal
 * decision date is the due day when the case has one
 * (case-page-tasks-decision-dates-and-next-step).
 *
 * @param {object} row The case row.
 * @param {{collection: object|null, tr: (key: string, vars?: object) => string, locale: string, today: Date}} context The collection, the translator, the page language and today.
 * @return {{due: string, dueDay: string, readyBy: string}} The words.
 */
function dueWords(row, { collection, tr, locale, today }) {
	const dueDay = dayInWords(dueValueOf(row, collection), today, locale)
	return {
		due: dueDay ? tr('Answer by {date}', { date: dueDay }) : '',
		dueDay,
		readyBy: dueDay ? tr('ready by {date}', { date: dueDay }) : '',
	}
}

/**
 * Whether the case waits on the resident: its turn value is one of the
 * board card's turn values.
 *
 * @param {object} row The case row.
 * @param {{collection: object|null, yourTurn: Array<string>}} context The collection and the turn values at which the resident must act.
 * @return {boolean}
 */
function isYourTurn(row, { collection, yourTurn }) {
	const turnValue = collection?.turnField ? row?.[collection.turnField] : undefined
	return (
		Array.isArray(yourTurn)
		&& turnValue !== undefined
		&& turnValue !== null
		&& yourTurn.map(String).includes(String(turnValue))
	)
}
