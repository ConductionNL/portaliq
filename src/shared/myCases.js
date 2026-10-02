// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The "My cases" list (cases-my-cases-page): every case from every
// contribution collection declared `kind: cases`, merged by the server. The
// server marks each row `_closed` from the field its collection declares
// (`closedField`), so the portal never guesses what closed means, and tags it
// with `_source` so a row can open on its own app's page.
//
// Imports nothing, so tests/my-cases-page.spec.mjs runs it as a plain node script.
//
// @spec openspec/specs/portal-my-cases/spec.md

/**
 * The cases split into open and closed, each keeping the server's order.
 *
 * @param {Array<object>|null} cases The merged rows.
 * @return {{open: Array<object>, closed: Array<object>}} The two lists.
 *
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-open-and-closed-cases-are-told-apart-by-a-declared-field-req-cmc-002
 */
export function splitCases(cases) {
	const rows = Array.isArray(cases) ? cases : []
	return {
		open: rows.filter((row) => row?._closed !== true),
		closed: rows.filter((row) => row?._closed === true),
	}
}

/**
 * Where a case opens: its app, its collection and its id, or null when the
 * row does not say where it came from.
 *
 * @param {object} row The case row.
 * @return {{app: string, collection: string, id: string}|null} The target.
 *
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-a-case-opens-where-it-lives-req-cmc-005
 */
export function caseTarget(row) {
	const source = row?._source || {}
	const id = row?.id || row?.uuid || row?.['@self']?.id || ''
	if (!source.appId || !source.collection || !id) {
		return null
	}
	return {
		app: String(source.appId),
		collection: String(source.collection),
		id: String(id),
	}
}

/**
 * What a case is called in the list: its title, else its reference, else its id.
 *
 * @param {object} row The case row.
 * @return {string} The name.
 *
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-your-cases-from-every-app-in-one-list-req-cmc-001
 */
export function caseTitle(row) {
	for (const field of ['title', 'name', 'reference', 'identifier']) {
		const value = row?.[field]
		if (typeof value === 'string' && value.trim() !== '') {
			return value
		}
	}
	return String(row?.id || row?.uuid || row?.['@self']?.id || '')
}

/** A uuid: an identifier, never words a resident reads. */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/**
 * A case's status in words: the status's public label when the app projects
 * one (`statusPublicLabel`, else `statusLabel`), else `status` itself, unless
 * that is an identifier. A status type's uuid is how an app tells statuses
 * apart and says nothing to a person, so it reads as no status at all.
 *
 * @param {object} row The case.
 * @return {string} The status, or ''.
 *
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-my-cases/spec.md#requirement-a-case-on-my-cases-shows-its-status-in-words-never-a-code
 */
export function caseStatus(row) {
	for (const field of ['statusPublicLabel', 'statusLabel', 'status']) {
		const value = row?.[field]
		if (
			typeof value === 'string'
			&& value.trim() !== ''
			&& !UUID.test(value.trim())
		) {
			return value.trim()
		}
	}
	return ''
}

// Whom the person acts for (REQ-CMC-004). "Yourself" is sent as `mandate=self`
// so the server lists only the person's own cases even while they hold a
// mandate; without any value it would spend the first mandate held.
export const ACTING_FOR_SELF = 'self'
export const ACTING_FOR_KEY = 'portaliq.actingFor'

/**
 * The choices under "Acting for": yourself, then every mandate held by its label.
 *
 * @param {Array<{id: string, label: string}>} mandates The mandates held.
 * @param {(key: string) => string} t The translator.
 * @return {Array<{id: string, label: string}>} The choices.
 *
 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
 */
export function actingForOptions(mandates, t) {
	const held = (Array.isArray(mandates) ? mandates : [])
		.filter((mandate) => mandate && mandate.id)
		.map((mandate) => ({
			id: String(mandate.id),
			label: String(mandate.label || mandate.id),
		}))
	return [{ id: ACTING_FOR_SELF, label: t('Yourself') }, ...held]
}

/**
 * The choice kept for this session, or yourself.
 *
 * @param {{getItem: (key: string) => string|null}|null} storage sessionStorage.
 * @return {string} The mandate id, or `self`.
 */
export function readActingFor(storage) {
	try {
		return storage?.getItem(ACTING_FOR_KEY) || ACTING_FOR_SELF
	} catch {
		return ACTING_FOR_SELF
	}
}

/**
 * Keep the choice for the rest of the session.
 *
 * @param {{setItem: (key: string, value: string) => void}|null} storage sessionStorage.
 * @param {string} id The mandate id, or `self`.
 * @return {void}
 */
export function keepActingFor(storage, id) {
	try {
		storage?.setItem(ACTING_FOR_KEY, id)
	} catch {
		// Without storage the choice lasts as long as the page.
	}
}

/**
 * The choice when it is still held, else yourself.
 *
 * @param {string} id The kept choice.
 * @param {Array<{id: string}>} mandates The mandates held.
 * @return {string} The choice to act under.
 */
export function actingForHeld(id, mandates) {
	if (id === ACTING_FOR_SELF) {
		return id
	}
	return (Array.isArray(mandates) ? mandates : []).some(
		(mandate) => mandate?.id === id,
	)
		? id
		: ACTING_FOR_SELF
}
