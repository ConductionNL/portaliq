// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The words and rules of Mijn acties and the action dialog
// (personal-action-list, board ActieBewerken), in Dutch and English. Imports
// nothing, so the node specs run it as a plain script.

const WORDS = {
	nl: {
		title: 'Mijn acties',
		intro: 'Uw eigen stappen, met wie ze doet en wanneer ze klaar moeten zijn.',
		actions: 'Acties',
		addAction: 'Actie toevoegen',
		progress: '{done} van {total} klaar',
		noActions: 'U heeft nog geen acties.',
		loading: 'Laden…',
		failed: 'Dit lukte niet. Probeer het later opnieuw.',
		saved: 'Uw wijziging is opgeslagen.',
		action: 'Actie',
		status: 'Status',
		due: 'Uiterlijk',
		who: 'Wie',
		edit: 'Bewerken',
		editAction: 'Actie bewerken',
		newAction: 'Actie toevoegen',
		inPlan: 'In het plan {plan}.',
		inPlanSeen: 'In het plan {plan}. Wijzigingen ziet {people} ook.',
		sharedWith: 'Wijzigingen ziet {people} ook.',
		givenBy:
			'Deze actie is aan u gegeven. U kunt de status, de omschrijving en het bestand aanpassen.',
		fieldTitle: 'Titel',
		fieldDescription: 'Omschrijving',
		optional: '(niet verplicht)',
		kind: 'Soort',
		once: 'Eenmalig',
		recurring: 'Terugkerend',
		todo: 'Te doen',
		doing: 'Bezig',
		done: 'Klaar',
		endDate: 'Uiterlijk klaar op',
		assignee: 'Toegewezen aan',
		you: 'u',
		file: 'Bestand',
		addFile: 'Bestand toevoegen',
		replace: 'Vervangen',
		removeFile: 'Verwijderen',
		fileRule: 'Eén bestand per actie. PDF, JPG of PNG, maximaal 10 MB.',
		fileAfterSave: 'Een bestand voegt u toe nadat de actie is opgeslagen.',
		history: 'Historie',
		noHistory: 'Nog geen wijzigingen.',
		created: 'Actie gemaakt',
		createdDue: 'Actie gemaakt, uiterlijk {date}',
		statusChange: 'Status van {from} naar {to}',
		fileAdded: 'Bestand {name} toegevoegd',
		fileRemoved: 'Bestand {name} verwijderd',
		fieldChange: '{field} gewijzigd',
		assigneeChange: 'Toegewezen aan {to}',
		someone: 'Iemand',
		save: 'Opslaan',
		cancel: 'Annuleren',
		close: 'Sluiten',
		deleteAction: 'Actie verwijderen',
		sure: 'Weet u het zeker? De actie is daarna weg.',
		titleNeeded: 'Vul een titel in.',
		daysLeft: 'nog {days} dagen',
		dayLeft: 'nog 1 dag',
		today: 'vandaag',
		overdue: 'verlopen',
		plan: 'Plan: {plan}',
	},
	en: {
		title: 'My actions',
		intro: 'Your own steps, who does them and when they should be done.',
		actions: 'Actions',
		addAction: 'Add action',
		progress: '{done} of {total} done',
		noActions: 'You have no actions yet.',
		loading: 'Loading…',
		failed: 'That did not work. Try again later.',
		saved: 'Your change is saved.',
		action: 'Action',
		status: 'Status',
		due: 'Due',
		who: 'Who',
		edit: 'Edit',
		editAction: 'Edit action',
		newAction: 'Add action',
		inPlan: 'In the plan {plan}.',
		inPlanSeen: 'In the plan {plan}. {people} also sees the changes.',
		sharedWith: '{people} also sees the changes.',
		givenBy:
			'This action was given to you. You can change the status, the description and the file.',
		fieldTitle: 'Title',
		fieldDescription: 'Description',
		optional: '(optional)',
		kind: 'Kind',
		once: 'Once',
		recurring: 'Recurring',
		todo: 'To do',
		doing: 'In progress',
		done: 'Done',
		endDate: 'Done by',
		assignee: 'Assigned to',
		you: 'you',
		file: 'File',
		addFile: 'Add file',
		replace: 'Replace',
		removeFile: 'Remove',
		fileRule: 'One file per action. PDF, JPG or PNG, at most 10 MB.',
		fileAfterSave: 'You can add a file once the action is saved.',
		history: 'History',
		noHistory: 'No changes yet.',
		created: 'Action created',
		createdDue: 'Action created, due {date}',
		statusChange: 'Status from {from} to {to}',
		fileAdded: 'File {name} added',
		fileRemoved: 'File {name} removed',
		fieldChange: '{field} changed',
		assigneeChange: 'Assigned to {to}',
		someone: 'Someone',
		save: 'Save',
		cancel: 'Cancel',
		close: 'Close',
		deleteAction: 'Delete action',
		sure: 'Are you sure? The action will be gone.',
		titleNeeded: 'Fill in a title.',
		daysLeft: '{days} days left',
		dayLeft: '1 day left',
		today: 'today',
		overdue: 'overdue',
		plan: 'Plan: {plan}',
	},
}

/** The largest file an action keeps, in bytes. */
export const MAX_FILE_BYTES = 10 * 1024 * 1024

/** The extensions an action's file may have. */
const FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png']

/**
 * The words in a language; Dutch unless the locale is English.
 *
 * @param {string} locale The locale.
 * @return {Record<string, string>} The words.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */
export function actionWords(locale) {
	return String(locale || '')
		.toLowerCase()
		.startsWith('en')
		? WORDS.en
		: WORDS.nl
}

/**
 * Fill `{name}` placeholders.
 *
 * @param {string} text The text.
 * @param {Record<string, string|number>} vars The values.
 * @return {string} The text.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */
export function fill(text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (_, key) => String(vars[key] ?? ''))
}

/**
 * Whether a chosen file may be kept with an action: PDF, JPG or PNG up to
 * 10 MB. The server checks the bytes again.
 *
 * @param {{name: string, size: number}|null} file The chosen file.
 * @return {boolean} True when it may.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
 */
export function fileAllowed(file) {
	if (!file) {
		return false
	}
	const extension = String(file.name || '')
		.split('.')
		.pop()
		.toLowerCase()
	return FILE_TYPES.includes(extension) && Number(file.size) <= MAX_FILE_BYTES
}

/**
 * A day as "14 oktober 2026" in the page's language.
 *
 * @param {string} day `YYYY-MM-DD`, or ''.
 * @param {string} locale The locale.
 * @return {string} The day in words, or ''.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */
export function dayText(day, locale) {
	if (!/^\d{4}-\d{2}-\d{2}/.test(String(day || ''))) {
		return ''
	}
	const [year, month, date] = day.slice(0, 10).split('-').map(Number)
	return new Date(Date.UTC(year, month - 1, date)).toLocaleDateString(
		String(locale || '')
			.toLowerCase()
			.startsWith('en')
			? 'en-GB'
			: 'nl-NL',
		{ day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' },
	)
}

/**
 * How long an open action has left: "nog 6 dagen", "vandaag", "verlopen".
 *
 * @param {{endDate: string, status: string}} action The action.
 * @param {string} today Today, `YYYY-MM-DD`.
 * @param {Record<string, string>} words The words.
 * @return {string} The line, or '' for a done action or one without a date.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */
export function daysLeftLine(action, today, words) {
	if (action.status === 'done' || !action.endDate || !today) {
		return ''
	}
	const days = Math.round(
		(Date.parse(`${action.endDate.slice(0, 10)}T00:00:00Z`)
			- Date.parse(`${today}T00:00:00Z`))
			/ 86400000,
	)
	if (Number.isNaN(days)) {
		return ''
	}
	if (days < 0) {
		return words.overdue
	}
	if (days === 0) {
		return words.today
	}
	return days === 1 ? words.dayLeft : fill(words.daysLeft, { days })
}

/**
 * One line of an action's history in words: "Status van Te doen naar Bezig".
 *
 * @param {{kind: string, field: string, from: string, to: string}} line The server's line.
 * @param {Record<string, string>} words The words.
 * @param {string} locale The locale, for dates.
 * @return {string} The line.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
 */
export function historyText(line, words, locale) {
	const label = (value) => words[value] || value
	switch (line.kind) {
		case 'created':
			return line.to
				? fill(words.createdDue, { date: dayText(line.to, locale) })
				: words.created
		case 'status':
			return fill(words.statusChange, {
				from: label(line.from),
				to: label(line.to),
			})
		case 'file':
			return fill(words.fileAdded, { name: line.to })
		case 'fileRemoved':
			return fill(words.fileRemoved, { name: line.from })
		default:
			if (line.field === 'assignee') {
				return fill(words.assigneeChange, { to: line.to })
			}
			return fill(words.fieldChange, { field: fieldLabel(line.field, words) })
	}
}

/**
 * The label of a field, as the dialog names it.
 *
 * @param {string} field The field.
 * @param {Record<string, string>} words The words.
 * @return {string} The label.
 */
function fieldLabel(field, words) {
	return (
		{
			title: words.fieldTitle,
			description: words.fieldDescription,
			kind: words.kind,
			endDate: words.endDate,
		}[field] || field
	)
}

/**
 * When a history line happened: "6 oktober 2026, 14:02".
 *
 * @param {string} at The audit row's time, `YYYY-MM-DD HH:MM:SS` or ISO.
 * @param {string} locale The locale.
 * @return {string} The moment in words.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */
export function momentText(at, locale) {
	const text = String(at || '')
	const day = dayText(text.slice(0, 10), locale)
	const time = text.slice(11, 16)
	return time ? `${day}, ${time}` : day
}

/**
 * The lead of the dialog: in which plan, and who else sees the changes.
 *
 * @param {{planTitle: string, role: string}|null} action The action, or null for a new one.
 * @param {Array<{ref: string, displayName: string}>} people Who may be assigned.
 * @param {string} viewer The viewer's subject reference.
 * @param {Record<string, string>} words The words.
 * @return {string} The lead, or ''.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */
export function leadText(action, people, viewer, words) {
	if (action?.role === 'assignee') {
		return words.givenBy
	}
	const others = (people || [])
		.filter((person) => person.ref !== viewer && person.displayName)
		.map((person) => person.displayName)
		.join(', ')
	if (action?.planTitle) {
		return others
			? fill(words.inPlanSeen, { plan: action.planTitle, people: others })
			: fill(words.inPlan, { plan: action.planTitle })
	}
	return ''
}
