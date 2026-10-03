// SPDX-License-Identifier: EUPL-1.2
//
// A notification's link opens the record it is about
// (inbox-notifications-and-preferences, REQ-NAP-005).
//
// The e-mail and the inbox link carry `#open=<app>/<collection>/<id>`. The
// shell reads and strips it on load, the way it reads `#token=`, and keeps it
// in sessionStorage so it survives the sign-in round trip. Signed in, it opens
// the page that shows that app's collection and preselects the row. The row is
// still read through the resident's own scoped read, so a forwarded link to
// someone else's record opens nothing of it.
//
// Imports nothing, so tests/open-record.spec.mjs runs it as a plain node script.
//
// @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-notification-leads-to-the-record-req-nap-005

export const OPEN_STORAGE_KEY = 'portaliq.openRecord'

const PREFIX = '#open='

/**
 * The record a fragment names, or null.
 *
 * @param {string} hash The location hash.
 * @return {{app: string, collection: string, id: string}|null}
 */
export function parseOpenFragment(hash) {
	if (typeof hash !== 'string' || !hash.startsWith(PREFIX)) {
		return null
	}
	const parts = hash.slice(PREFIX.length).split('/')
	if (parts.length !== 3 || parts.some((p) => p === '')) {
		return null
	}
	try {
		const [app, collection, id] = parts.map((p) => decodeURIComponent(p))
		return { app, collection, id }
	} catch {
		return null
	}
}

/**
 * Read the target from the address, or from before the sign-in.
 *
 * A fragment in the address wins: it is stripped from the address bar and
 * kept in storage. Without one, a target kept before the sign-in comes back.
 *
 * @param {{hash: string, pathname: string, search: string}} location The location.
 * @param {{replaceState: Function}} history The history.
 * @param {{getItem: Function, setItem: Function, removeItem: Function}|null} storage sessionStorage.
 * @return {{app: string, collection: string, id: string}|null}
 */
export function consumeOpenTarget(location, history, storage) {
	const fromHash = parseOpenFragment(location?.hash || '')
	if (fromHash) {
		try {
			history?.replaceState(
				null,
				'',
				`${location.pathname || ''}${location.search || ''}`,
			)
		} catch {
			// An address bar that cannot be rewritten still opens the record.
		}
		try {
			storage?.setItem(OPEN_STORAGE_KEY, JSON.stringify(fromHash))
		} catch {
			// Without storage the target lives as long as this page.
		}
		return fromHash
	}
	try {
		const kept = JSON.parse(storage?.getItem(OPEN_STORAGE_KEY) || 'null')
		if (
			kept
			&& typeof kept.app === 'string'
			&& typeof kept.collection === 'string'
			&& typeof kept.id === 'string'
		) {
			return kept
		}
	} catch {
		// A mangled entry opens nothing.
	}
	return null
}

/**
 * Forget the kept target once it has been opened.
 *
 * @param {{removeItem: Function}|null} storage sessionStorage.
 * @return {void}
 */
export function forgetOpenTarget(storage) {
	try {
		storage?.removeItem(OPEN_STORAGE_KEY)
	} catch {
		// Nothing to forget.
	}
}

/**
 * The nav key of the page that shows this app's collection, or null.
 *
 * @param {Array<object>} nav The shell's nav entries.
 * @param {{app: string, collection: string}} target The target.
 * @return {string|null}
 */
export function navKeyFor(nav, target) {
	for (const entry of nav || []) {
		if (!entry.page || entry.contribution?.app !== target?.app) {
			continue
		}
		const shows = (entry.page.blocks || []).some(
			(block) =>
				block.collection === target.collection
				&& ['collection', 'citizenCase', 'detail'].includes(block.type),
		)
		if (shows) {
			return entry.key
		}
	}
	return null
}

/**
 * The row with this id in the resident's own loaded list, or null.
 *
 * @param {Array<object>} objects The collection's scoped rows.
 * @param {string} id The record id.
 * @return {object|null}
 */
export function rowFor(objects, id) {
	return (
		(objects || []).find(
			(row) => (row.id || row.uuid || row['@self']?.id) === id,
		) || null
	)
}
