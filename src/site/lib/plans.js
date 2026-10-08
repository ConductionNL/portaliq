// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The words and rules of the plan pages (shared-plans-with-a-caseworker),
// in Dutch and English. Imports nothing, so the node specs run it as a plain
// script, and the words stay out of the site's shared catalogue.

const WORDS = {
	nl: {
		title: 'Samenwerken',
		newPlan: 'Nieuw plan',
		intro: 'Plannen die u samen met uw begeleider of uw contacten maakt. Iedereen in een plan ziet het doel, de acties en de bestanden, en kan acties afvinken.',
		yourPlans: 'Uw plannen',
		running: 'Lopend',
		action: 'Actie vereist',
		done: 'Afgerond',
		all: 'Alle',
		noPlans: 'U heeft nog geen plannen.',
		loading: 'Laden…',
		failed: 'Dit lukte niet. Probeer het later opnieuw.',
		madeByYou: 'Door u gemaakt',
		sharedBy: 'Door {name} met u gedeeld',
		endsIn: 'Loopt over {days} dagen af',
		endsToday: 'Loopt vandaag af',
		endsOne: 'Loopt morgen af',
		ended: 'Einddatum verstreken',
		goal: 'Doel',
		endDate: 'Einddatum',
		openActions: 'Open acties: {count}',
		progress: '{done} van {total} acties klaar',
		open: 'Openen',
		back: 'Alle plannen',
		download: 'Download als PDF',
		alert: 'Dit plan loopt over {days} dagen af, op {date}',
		alertDetail: 'Er staan nog {count} acties open. Verleng het plan met uw begeleider, of rond de acties af.',
		editGoal: 'Doel aanpassen',
		actions: 'Acties',
		addAction: 'Actie toevoegen',
		actionTitle: 'Wat moet er gebeuren',
		actionDate: 'Uiterlijk',
		actionWho: 'Wie',
		status: 'Status',
		todo: 'Te doen',
		doing: 'Bezig',
		doneStatus: 'Klaar',
		notes: 'Notities',
		editNote: 'Notitie aanpassen',
		participants: 'Deelnemers',
		you: 'U',
		maker: 'maker van het plan',
		addParticipant: 'Deelnemer toevoegen',
		remove: 'Verwijderen',
		save: 'Opslaan',
		cancel: 'Annuleren',
		markDone: 'Plan afronden',
		deletePlan: 'Plan verwijderen',
		sure: 'Weet u het zeker?',
		startTitle: 'Een nieuw plan starten',
		startWhere: 'Waar wilt u mee beginnen?',
		emptyPlan: 'Leeg plan',
		planName: 'Naam van het plan',
		withWhom: 'Met wie maakt u dit plan? (niet verplicht)',
		noContacts: 'U heeft nog geen goedgekeurde contacten.',
		start: 'Plan starten',
		saved: 'Uw wijziging is opgeslagen.',
		pdfUnavailable: 'De pdf kan nu niet worden gemaakt.',
	},
	en: {
		title: 'Collaborate',
		newPlan: 'New plan',
		intro: 'Plans you make with your caseworker or your contacts. Everyone in a plan sees the goal, the actions and the files, and can tick off actions.',
		yourPlans: 'Your plans',
		running: 'Running',
		action: 'Action needed',
		done: 'Done',
		all: 'All',
		noPlans: 'You have no plans yet.',
		loading: 'Loading…',
		failed: 'That did not work. Try again later.',
		madeByYou: 'Made by you',
		sharedBy: 'Shared with you by {name}',
		endsIn: 'Ends in {days} days',
		endsToday: 'Ends today',
		endsOne: 'Ends tomorrow',
		ended: 'End date has passed',
		goal: 'Goal',
		endDate: 'End date',
		openActions: 'Open actions: {count}',
		progress: '{done} of {total} actions done',
		open: 'Open',
		back: 'All plans',
		download: 'Download as PDF',
		alert: 'This plan ends in {days} days, on {date}',
		alertDetail: '{count} actions are still open. Extend the plan with your caseworker, or finish the actions.',
		editGoal: 'Change the goal',
		actions: 'Actions',
		addAction: 'Add an action',
		actionTitle: 'What has to be done',
		actionDate: 'Due',
		actionWho: 'Who',
		status: 'Status',
		todo: 'To do',
		doing: 'Doing',
		doneStatus: 'Done',
		notes: 'Notes',
		editNote: 'Change the note',
		participants: 'Participants',
		you: 'You',
		maker: 'maker of the plan',
		addParticipant: 'Add a participant',
		remove: 'Remove',
		save: 'Save',
		cancel: 'Cancel',
		markDone: 'Finish the plan',
		deletePlan: 'Delete the plan',
		sure: 'Are you sure?',
		startTitle: 'Start a new plan',
		startWhere: 'Where do you want to begin?',
		emptyPlan: 'Empty plan',
		planName: 'Name of the plan',
		withWhom: 'Who do you make this plan with? (optional)',
		noContacts: 'You have no approved contacts yet.',
		start: 'Start the plan',
		saved: 'Your change is saved.',
		pdfUnavailable: 'The PDF cannot be made right now.',
	},
}

/**
 * The words in a language; Dutch unless the locale is English.
 *
 * @param {string} locale The locale.
 * @return {Record<string, string>} The words.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */
export function planWords(locale) {
	return String(locale || '').toLowerCase().startsWith('en') ? WORDS.en : WORDS.nl
}

/**
 * Fill `{name}` placeholders.
 *
 * @param {string} text The text.
 * @param {Record<string, string|number>} vars The values.
 * @return {string} The text.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */
export function fill(text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (_, key) => String(vars[key] ?? ''))
}

/**
 * The plans a filter chip shows: `running` counts everything not done (plans
 * that need action still run), `action` and `done` their own.
 *
 * @param {Array<object>} plans The cards.
 * @param {string} chip `all`, `running`, `action` or `done`.
 * @return {Array<object>} The plans.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */
export function plansOfChip(plans, chip) {
	const list = Array.isArray(plans) ? plans : []
	if (chip === 'running') {
		return list.filter((plan) => plan.state !== 'done')
	}
	if (chip === 'action' || chip === 'done') {
		return list.filter((plan) => plan.state === chip)
	}
	return list
}

/**
 * The count on a chip.
 *
 * @param {{running: number, action: number, done: number}} counts The server's counts.
 * @param {string} chip The chip.
 * @return {number} The count.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */
export function chipCount(counts, chip) {
	const c = counts || {}
	const running = Number(c.running || 0)
	const action = Number(c.action || 0)
	const done = Number(c.done || 0)
	return { all: running + action + done, running: running + action, action, done }[chip] ?? 0
}

/**
 * "Loopt over 12 dagen af": how a card says how long the plan has left.
 *
 * @param {number|null} days Whole days left, null without an end date.
 * @param {Record<string, string>} words The words.
 * @return {string} The line, or ''.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
 */
export function daysLine(days, words) {
	if (typeof days !== 'number') {
		return ''
	}
	if (days < 0) {
		return words.ended
	}
	if (days === 0) {
		return words.endsToday
	}
	return days === 1 ? words.endsOne : fill(words.endsIn, { days })
}

/**
 * The words of a participant on the plan page: "U, maker van het plan".
 *
 * @param {{displayName: string, isOwner: boolean, ref: string}} person The participant.
 * @param {string} viewer The viewer's subject reference.
 * @param {Record<string, string>} words The words.
 * @return {string} The line.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
 */
export function personLine(person, viewer, words) {
	const name = person.ref === viewer ? words.you : person.displayName
	return person.isOwner ? `${name}, ${words.maker}` : name
}
