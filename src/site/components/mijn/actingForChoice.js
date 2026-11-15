// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// What the acting-for bar says and offers (board huisstijl/NamensBalk,
// site-mijn-omgeving REQ-SMO-008): the sentence for the choice in effect and
// the options of "Wissel naar". No Vue, so the node tests run it directly.
//
// @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008

import { ACTING_FOR_SELF } from '../../../shared/myCases.js'
import { chooseActingFor, forgetChosenParty } from '../e/actingFor.js'

/** The choice that lists the resident's own cases and every mandate's. */
export const EVERYONE = 'everyone'

/**
 * The mandates held that carry an id, as options named by their label.
 *
 * @param {Array<{id: string, label?: string}>} mandates The mandates held.
 * @return {Array<{id: string, label: string, own: boolean}>} One option per party.
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
 */
export function partiesHeld(mandates) {
	return (Array.isArray(mandates) ? mandates : [])
		.filter((mandate) => mandate && mandate.id)
		.map((mandate) => ({
			id: String(mandate.id),
			label: String(mandate.label || mandate.id),
			own: false,
		}))
}

/**
 * The options of "Wissel naar": the resident by name, each party, everyone.
 *
 * @param {{mandates: Array<object>, name: string}} holder The mandates held and the resident's name ('' when unknown).
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @return {Array<{id: string, label: string, own: boolean}>} The options; `own` marks a label of our own words.
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
 */
export function barOptions(holder, tr) {
	const self = holder.name
		? tr('Yourself, {name}', { name: holder.name })
		: tr('Yourself')
	return [
		{ id: ACTING_FOR_SELF, label: self, own: true },
		...partiesHeld(holder.mandates),
		{ id: EVERYONE, label: tr('Everyone you act for'), own: true },
	]
}

/**
 * The choice in effect: everyone until a party is chosen this session.
 *
 * @param {{id: string, chosen: boolean}} store The acting-for store.
 * @return {string} `everyone`, `self` or a mandate id.
 * @spec openspec/changes/resident-pages-live-round-3/specs/portal-my-cases/spec.md#requirement-everyone-is-the-default-and-reads-every-active-mandate-req-rr3-002
 */
export function choiceOf(store) {
	return store.chosen ? store.id || ACTING_FOR_SELF : EVERYONE
}

/**
 * The bar's sentence for a choice, with the party it names ('' for none).
 *
 * @param {{choice: string, party: string}} acting The choice and the chosen party's label.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @return {string} The sentence.
 * @spec openspec/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
 */
export function barSentence(acting, tr) {
	if (acting.choice === EVERYONE) {
		return tr('You now see the cases of everyone you act for.')
	}
	if (acting.choice === ACTING_FOR_SELF || !acting.party) {
		return tr('You are now handling your own affairs.')
	}
	return tr('You are now acting for {party}.', { party: acting.party })
}

/**
 * Keep a choice for the session: everyone forgets the party chosen.
 *
 * @param {string} choice `everyone`, `self` or a mandate id.
 * @return {void}
 * @spec openspec/changes/resident-pages-live-round-3/specs/portal-my-cases/spec.md#requirement-everyone-is-the-default-and-reads-every-active-mandate-req-rr3-002
 */
export function keepChoice(choice) {
	if (choice === EVERYONE) {
		forgetChosenParty()
		return
	}
	chooseActingFor(choice)
}
