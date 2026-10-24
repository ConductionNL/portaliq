// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Whom the resident acts for on the site (cases-my-cases-page REQ-CMC-004,
// ported from the React shell's `actingFor` and `mandates` state). One
// reactive store shared by the header switcher, "My cases" and every case
// screen, so none of them needs the shell to pass the choice around. The
// choice is kept in sessionStorage for the rest of the session; the mandates
// held are learned from the "My cases" answer, and a refusal never forgets
// them.

import { reactive } from 'vue'
import {
	ACTING_FOR_KEY,
	ACTING_FOR_SELF,
	actingForHeld,
	keepActingFor,
	readActingFor,
} from '../../../shared/myCases.js'

/**
 * sessionStorage, or null where the browser refuses it or there is none.
 *
 * @return {Storage|null}
 */
function sessionStore() {
	try {
		return typeof window !== 'undefined' ? window.sessionStorage : null
	} catch {
		return null
	}
}

/**
 * The store: `id` is the mandate acted under or `self`, `mandates` the ones held.
 *
 * @type {{id: string, mandates: Array<{id: string, label: string}>}}
 */
export const actingFor = reactive({
	id: readActingFor(sessionStore()),
	mandates: [],
	// Whether a party was chosen this session; without a choice Mijn zaken
	// lists everyone (resident-pages-live-round-3 REQ-RR3-002).
	chosen: hasChosenActingFor(sessionStore()),
})

/**
 * Whether the session keeps a choice of whom to act for.
 *
 * @param {Storage|null} store sessionStorage.
 * @return {boolean}
 * @spec openspec/changes/resident-pages-live-round-3/specs/portal-my-cases/spec.md#requirement-everyone-is-the-default-and-reads-every-active-mandate-req-rr3-002
 */
export function hasChosenActingFor(store) {
	try {
		return Boolean(store?.getItem(ACTING_FOR_KEY))
	} catch {
		return false
	}
}

/**
 * Act for yourself or under a mandate, for the rest of the session.
 *
 * @param {string} id The mandate id, or `self`.
 * @param {object} [store] The store to keep it in (test seam).
 * @return {void}
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-act-for-someone-else-req-srp-041
 */
export function chooseActingFor(id, store = sessionStore()) {
	keepActingFor(store, id)
	actingFor.id = id
	actingFor.chosen = true
}

/**
 * Forget the party chosen on Mijn zaken, so it opens on everyone again; the
 * mandate acted under stays for this page's life.
 *
 * @param {object} [store] The store the choice is kept in (test seam).
 * @return {void}
 * @spec openspec/changes/resident-pages-live-round-3/specs/portal-my-cases/spec.md#requirement-everyone-is-the-default-and-reads-every-active-mandate-req-rr3-002
 */
export function forgetChosenParty(store = sessionStore()) {
	try {
		store?.removeItem(ACTING_FOR_KEY)
	} catch {
		// Without storage nothing was kept.
	}
	actingFor.chosen = false
}

/**
 * Learn the mandates held from a "My cases" answer. A refused answer changes
 * nothing; a kept choice the person no longer holds falls back to yourself.
 *
 * @param {object|null} answer The `fetchMyCases` answer.
 * @return {void}
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-act-for-someone-else-req-srp-041
 */
export function learnMandates(answer) {
	if (!answer || !answer.ok) {
		return
	}
	actingFor.mandates = Array.isArray(answer.mandates) ? answer.mandates : []
	actingFor.id = actingForHeld(actingFor.id, answer.mandates)
}

/**
 * Forget the choice and the mandates held, at sign-out: on a shared device
 * the next resident starts as themselves and sees none of the previous
 * resident's mandate labels.
 *
 * @param {object} [store] The store the choice is kept in (test seam).
 * @return {void}
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-act-for-someone-else-req-srp-041
 */
export function forgetActingFor(store = sessionStore()) {
	try {
		store?.removeItem(ACTING_FOR_KEY)
	} catch {
		// Without storage nothing was kept.
	}
	actingFor.id = ACTING_FOR_SELF
	actingFor.mandates = []
	actingFor.chosen = false
}
