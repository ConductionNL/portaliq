// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the case page says beside the case itself (case-page-tasks-decision-
// dates-and-next-step): the open tasks of the case with the sentence that
// names their due day, the two decision dates, and the button on the current
// status step. Pure functions; CitizenCase.vue renders what they return.

/** The fields a task's title is read from when its collection names none. */
const TASK_TITLE_FIELDS = ['title', 'name', 'subject', 'onderwerp']

/**
 * @param {unknown} value A value that should be a list.
 * @return {Array} The value, or an empty list when it is not one.
 */
function listOf(value) {
	return Array.isArray(value) ? value : []
}

/**
 * @param {Array<string>} caseKeys The values that name a case.
 * @return {Array<string>} The values trimmed, without the empty ones.
 */
function trimmedKeys(caseKeys) {
	return listOf(caseKeys)
		.map((key) => String(key ?? '').trim())
		.filter((key) => key !== '')
}

/**
 * @param {string|undefined} closedField The field that marks a row closed.
 * @param {object|null} row A row.
 * @return {boolean} Whether the collection marks the row closed.
 */
function isClosed(closedField, row) {
	return closedField && row?.[closedField] ? true : false
}

/**
 * @param {{field: string, closedField: string|undefined, keys: Array<string>}} match The case field, the closed field and the case's keys.
 * @param {object|null} row A row.
 * @return {boolean} Whether the row is an open task of the case.
 */
function isOpenCaseRow(match, row) {
	const value = row?.[match.field]
	if (isClosed(match.closedField, row) || value === undefined || value === null) {
		return false
	}
	return match.keys.includes(String(value).trim())
}

/**
 * @param {unknown} text A field value.
 * @return {boolean} Whether it is a text with more than white space.
 */
function isFilledText(text) {
	return typeof text === 'string' && text.trim() !== ''
}

/**
 * @param {object} collection The task collection.
 * @param {object} row The task row.
 * @return {string} The first filled title field, the collection's label, or ''.
 */
function taskTitle(collection, row) {
	const titleFields = collection.titleFields || TASK_TITLE_FIELDS
	return (
		titleFields.map((name) => row[name]).find(isFilledText)
		|| collection.label
		|| ''
	)
}

/**
 * @param {object} row The task row.
 * @return {string} The row's id, or ''.
 */
function taskId(row) {
	return String(row.id || row['@self']?.id || '')
}

/**
 * @param {object} row The task row.
 * @return {string} The row's task type, type or kind, or ''.
 */
function taskType(row) {
	return String(row.taskType ?? row.type ?? row.kind ?? '')
}

/**
 * @param {object} collection The task collection.
 * @param {object} row The task row.
 * @return {{id: string, title: string, due: string, type: string, collection: object, row: object}} The task.
 */
function taskOf(collection, row) {
	return {
		id: taskId(row),
		title: taskTitle(collection, row),
		due: typeof row.due === 'string' ? row.due : '',
		type: taskType(row),
		collection,
		row,
	}
}

/**
 * The open tasks of the case in one read, in the read's order.
 *
 * @param {{collection: object, rows: Array<object>}} read A task collection and its rows.
 * @param {Array<string>} keys The trimmed values that name the case.
 * @return {Array<object>} The tasks.
 */
function tasksOfRead(read, keys) {
	const field = read?.collection?.caseField
	if (typeof field !== 'string' || field === '' || keys.length === 0) {
		return []
	}
	const match = { field, closedField: read.collection.closedField, keys }
	return listOf(read.rows)
		.filter((row) => isOpenCaseRow(match, row))
		.map((row) => taskOf(read.collection, row))
}

/**
 * @param {{due: string}} task A task.
 * @return {number} Its due moment, or infinity for none or an unreadable one.
 */
function dueTime(task) {
	const ms = task.due ? new Date(task.due).getTime() : Number.NaN
	return Number.isNaN(ms) ? Number.POSITIVE_INFINITY : ms
}

/**
 * @param {Array<object>} tasks The tasks in read order.
 * @return {Array<object>} The tasks soonest due first, ties in read order.
 */
function soonestDueFirst(tasks) {
	return tasks
		.map((task, index) => ({ task, index }))
		.sort((a, b) => dueTime(a.task) - dueTime(b.task) || a.index - b.index)
		.map(({ task }) => task)
}

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
	const keys = trimmedKeys(caseKeys)
	return soonestDueFirst(listOf(reads).flatMap((read) => tasksOfRead(read, keys)))
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
	return date.toLocaleDateString(dateLocale(locale), dayFormat(withYear))
}

/**
 * @param {string} locale The page language.
 * @return {string} The date locale: English for an English page, else Dutch.
 */
function dateLocale(locale) {
	return String(locale || 'nl').startsWith('en') ? 'en-GB' : 'nl-NL'
}

/**
 * @param {boolean} withYear Add the year.
 * @return {object} The day-and-month format, with the year when asked.
 */
function dayFormat(withYear) {
	return withYear
		? { day: 'numeric', month: 'long', year: 'numeric' }
		: { day: 'numeric', month: 'long' }
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
	return documentsSentence(
		{
			due: dayWords(tasks.find((task) => task.due)?.due, locale),
			legal: dayWords(legalDecisionDate, locale),
		},
		t,
	)
}

/**
 * The banner sentence with the halves whose day is known.
 *
 * @param {{due: string, legal: string}} days The earliest due day and the legal decision day, in words.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {string} The sentence.
 */
function documentsSentence(days, t) {
	const { due, legal } = days
	if (due && legal) {
		return t(
			'We still need documents from you. Send them before {due}, and we will decide by {legal}.',
			{ due, legal },
		)
	}
	if (due) {
		return t('We still need documents from you. Send them before {due}.', {
			due,
		})
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
	const button =
		action && typeof action.label === 'string'
			? stepButton(action, { tasks, offered })
			: null
	return { button, next: status?.next?.label || '' }
}

/**
 * A button to the first open task of the action's target type.
 *
 * @param {{label: string, target: string}} action The step's action.
 * @param {Array<object>|null} tasks The open tasks of the case.
 * @return {object|null} The button, or null when no such task is open.
 */
function taskButton(action, tasks) {
	const task = (tasks || []).find((entry) => entry.type === action.target)
	return task ? { label: action.label, kind: 'task', task } : null
}

/**
 * The button a status step's action draws, or null when it has nothing to
 * lead to.
 *
 * @param {{label: string, kind: string, target: string}} action The step's action.
 * @param {{tasks: Array<object>, offered: (id: string) => boolean}} screen The open tasks and the case actions the screen offers.
 * @return {object|null} The button.
 */
function stepButton(action, screen) {
	if (action.kind === 'task') {
		return taskButton(action, screen.tasks)
	}
	if (action.kind === 'page') {
		return { label: action.label, kind: 'page', route: action.target }
	}
	if (action.kind === 'action' && screen.offered(action.target) === true) {
		return { label: action.label, kind: 'action', id: action.target }
	}
	return null
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
