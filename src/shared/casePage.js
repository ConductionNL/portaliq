// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the case page says beside the case itself (case-page-tasks-decision-
// dates-and-next-step): the open tasks of the case with the sentence that
// names their due day, the two decision dates, and the button on the current
// status step. Pure functions; CitizenCase.vue renders what they return.

/**
 * The open tasks that belong to one case, soonest due first.
 *
 * A collection without `caseField` adds nothing, so no task shows on a case
 * it does not belong to. A row the collection marks closed is not open.
 *
 * @param {Array<{collection: object, rows: Array<object>}>} reads The task collections and their rows.
 * @param {Array<string>} caseKeys The values that name this case (its reference and its id).
 * @return {Array<{id: string, title: string, due: string, type: string, collection: object, row: object}>} The tasks.
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t03
 */
export function tasksOfCase(reads, caseKeys) {
	const keys = (Array.isArray(caseKeys) ? caseKeys : [])
		.map((key) => String(key ?? '').trim())
		.filter((key) => key !== '')
	const tasks = []
	for (const read of Array.isArray(reads) ? reads : []) {
		const field = read?.collection?.caseField
		if (typeof field !== 'string' || field === '' || keys.length === 0) {
			continue
		}
		const closedField = read.collection.closedField
		for (const row of Array.isArray(read.rows) ? read.rows : []) {
			const value = row?.[field]
			const closed = closedField && row?.[closedField] ? true : false
			if (closed || value === undefined || value === null) {
				continue
			}
			if (!keys.includes(String(value).trim())) {
				continue
			}
			const titleFields = read.collection.titleFields || ['title', 'name', 'subject', 'onderwerp']
			tasks.push({
				id: String(row.id || row['@self']?.id || ''),
				title:
					titleFields
						.map((name) => row[name])
						.find((text) => typeof text === 'string' && text.trim() !== '')
					|| read.collection.label
					|| '',
				due: typeof row.due === 'string' ? row.due : '',
				type: String(row.taskType ?? row.type ?? row.kind ?? ''),
				collection: read.collection,
				row,
			})
		}
	}
	const time = (task) => {
		const ms = task.due ? new Date(task.due).getTime() : Number.NaN
		return Number.isNaN(ms) ? Number.POSITIVE_INFINITY : ms
	}
	return tasks
		.map((task, index) => ({ task, index }))
		.sort((a, b) => time(a.task) - time(b.task) || a.index - b.index)
		.map(({ task }) => task)
}

/**
 * A day in words ("18 oktober", or "18 oktober 2026" with the year).
 *
 * @param {string} value An ISO date.
 * @param {string} locale The page language.
 * @param {boolean} [withYear] Add the year.
 * @return {string} The day, or '' when the value is no date.
 */
export function dayWords(value, locale, withYear = false) {
	const date = value ? new Date(value) : null
	if (!date || Number.isNaN(date.getTime())) {
		return ''
	}
	return date.toLocaleDateString(
		String(locale || 'nl').startsWith('en') ? 'en-GB' : 'nl-NL',
		withYear
			? { day: 'numeric', month: 'long', year: 'numeric' }
			: { day: 'numeric', month: 'long' },
	)
}

/**
 * The banner sentence above the status steps.
 *
 * The earliest due day closes the first half and the legal decision date the
 * second; a half without its date is left out rather than guessed.
 *
 * @param {Array<{due: string}>} tasks The open tasks of the case.
 * @param {string} legalDecisionDate The case's latest decision day, or ''.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {string} locale The page language.
 * @return {string} The sentence, or '' when there is no open task.
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t03
 */
export function bannerSentence(tasks, legalDecisionDate, t, locale) {
	if (!Array.isArray(tasks) || tasks.length === 0) {
		return ''
	}
	const due = dayWords(tasks.find((task) => task.due)?.due, locale)
	const legal = dayWords(legalDecisionDate, locale)
	if (due && legal) {
		return t(
			'We still need documents from you. Send them before {due}, and we will decide by {legal}.',
			{ due, legal },
		)
	}
	if (due) {
		return t('We still need documents from you. Send them before {due}.', { due })
	}
	return t('We still need documents from you.')
}

/**
 * The Gegevens rows for the two decision dates, each only when set.
 *
 * @param {object} caseRow The case.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {string} locale The page language.
 * @return {Array<{key: string, label: string, value: string}>} The rows.
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t05
 */
export function decisionDateRows(caseRow, t, locale) {
	const rows = []
	for (const [key, label] of [
		['plannedDecisionDate', 'Expected decision'],
		['legalDecisionDate', 'Ready by'],
	]) {
		const value = dayWords(caseRow?.[key], locale, true)
		if (value) {
			rows.push({ key, label: t(label), value })
		}
	}
	return rows
}

/**
 * The button on the current status step and the greyed next step.
 *
 * A `task` button leads to the first open task of the target type on this
 * case and is absent when there is none. A `page` button always leads to its
 * route. An `action` button shows only when the screen offers that action.
 *
 * @param {object|null} status The writable set's status (`action`, `next`).
 * @param {Array<object>} tasks The open tasks of the case.
 * @param {(id: string) => boolean} offered Whether the screen offers a case action.
 * @return {{button: object|null, next: string}} The button and the next step's label.
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t04
 */
export function nextStepView(status, tasks, offered) {
	const action = status?.action
	let button = null
	if (action && typeof action.label === 'string') {
		if (action.kind === 'task') {
			const task = (tasks || []).find((entry) => entry.type === action.target)
			button = task ? { label: action.label, kind: 'task', task } : null
		} else if (action.kind === 'page') {
			button = { label: action.label, kind: 'page', route: action.target }
		} else if (action.kind === 'action' && offered(action.target) === true) {
			button = { label: action.label, kind: 'action', id: action.target }
		}
	}
	return { button, next: status?.next?.label || '' }
}

/**
 * The value a case card counts as its due day: the legal decision date when
 * the case has one, otherwise the collection's own due field.
 *
 * @param {object} row The case row.
 * @param {object|null} collection The cases collection.
 * @return {string|undefined} The date value.
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t05
 */
export function dueValueOf(row, collection) {
	if (typeof row?.legalDecisionDate === 'string' && row.legalDecisionDate !== '') {
		return row.legalDecisionDate
	}
	return collection?.dueField ? row?.[collection.dueField] : undefined
}
