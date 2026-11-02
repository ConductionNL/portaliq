// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// "Zaken van" on Mijn zaken (board MijnZakenTabel): whose cases the list
// shows. Everyone the resident acts for, themself, or one party a mandate
// names. No Vue, so tests/my-cases-parties.spec.mjs runs it as node.
//
// @spec openspec/specs/portal-my-cases/spec.md#requirement-mijn-zaken-lets-a-representative-choose-whose-cases-it-lists-req-mzc-001

import { ACTING_FOR_SELF, caseTarget } from '../../../shared/myCases.js'

/** The choice that lists the resident's own cases and every mandate's. */
export const EVERYONE = 'everyone'

/**
 * The choices: everyone, the resident by name, and each party a mandate names.
 *
 * @param {Array<{id: string, label?: string}>} mandates The mandates held.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @param {string} name The resident's name, '' when unknown.
 * @return {Array<{id: string, label: string, own: boolean}>} The options; `own` marks a label of our own words.
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-mijn-zaken-lets-a-representative-choose-whose-cases-it-lists-req-mzc-001
 */
export function partyOptions(mandates, tr, name) {
	const held = (Array.isArray(mandates) ? mandates : [])
		.filter((mandate) => mandate && mandate.id)
		.map((mandate) => ({
			id: String(mandate.id),
			label: String(mandate.label || mandate.id),
			own: false,
		}))
	return [
		{ id: EVERYONE, label: tr('Everyone I act for'), own: true },
		{
			id: ACTING_FOR_SELF,
			label: name ? tr('Myself, {name}', { name }) : tr('Myself'),
			own: true,
		},
		...held,
	]
}

/**
 * The mandate ids to read for a choice: every one for everyone, else the one chosen.
 *
 * @param {string} choice The choice.
 * @param {Array<{id: string}>} mandates The mandates held.
 * @return {Array<string>} The ids, `self` for the resident's own cases.
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-mijn-zaken-lets-a-representative-choose-whose-cases-it-lists-req-mzc-001
 */
export function readsFor(choice, mandates) {
	if (choice !== EVERYONE) {
		return [choice || ACTING_FOR_SELF]
	}
	const held = (Array.isArray(mandates) ? mandates : [])
		.filter((mandate) => mandate && mandate.id)
		.map((mandate) => String(mandate.id))
	return [ACTING_FOR_SELF, ...held]
}

/**
 * Several "My cases" answers as one: a failed first answer stands for the
 * whole, a later failed one is left out, and a case read twice stands once.
 *
 * @param {Array<object|null>} answers The answers, the resident's own first.
 * @return {object|null} The answer.
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-mijn-zaken-lets-a-representative-choose-whose-cases-it-lists-req-mzc-001
 */
export function mergedAnswer(answers) {
	const first = answers[0] ?? null
	if (!first || first.ok === false) {
		return first
	}
	const seen = new Set()
	const cases = []
	for (const answer of answers) {
		if (!answer || answer.ok === false) {
			continue
		}
		for (const row of Array.isArray(answer.cases) ? answer.cases : []) {
			const target = caseTarget(row)
			const key = target
				? `${target.app}:${target.collection}:${target.id}`
				: JSON.stringify(row)
			if (!seen.has(key)) {
				seen.add(key)
				cases.push(row)
			}
		}
	}
	return { ...first, cases }
}
