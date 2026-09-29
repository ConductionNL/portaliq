// SPDX-License-Identifier: EUPL-1.2
//
// The notices a portal shows above every page (operate-maintenance-notice).
// Shared by the public site (SiteNotices.vue) and the signed-in portal
// (PortalNotices.jsx), so both decide the same way which notice to show.
//
// The server only sends notices that are active, but the public site answer
// may be cached for up to five minutes: the end time is checked here again,
// so a notice can show late but never outstays its window.
//
// @spec openspec/changes/operate-maintenance-notice/specs/portal-notices/spec.md#requirement-a-visitor-can-close-a-notice-for-the-visit-req-omn-002

export const CLOSED_KEY = 'portaliq.closedNotices'

/**
 * The ids closed during this visit.
 *
 * @param {Storage|null} storage The session storage, or null.
 * @return {string[]}
 */
export function closedNotices(storage) {
	try {
		const raw = storage ? storage.getItem(CLOSED_KEY) : null
		const ids = raw ? JSON.parse(raw) : []
		return Array.isArray(ids) ? ids.filter((id) => typeof id === 'string') : []
	} catch {
		return []
	}
}

/**
 * Remember that a notice was closed. A blocked storage means the notice
 * simply stays; closing still hides it until the next page load.
 *
 * @param {Storage|null} storage The session storage, or null.
 * @param {string} id The notice id.
 * @return {void}
 */
export function closeNotice(storage, id) {
	try {
		const ids = closedNotices(storage)
		if (storage && !ids.includes(id)) {
			storage.setItem(CLOSED_KEY, JSON.stringify([...ids, id]))
		}
	} catch {
		// Storage blocked: nothing to remember it in.
	}
}

/**
 * The notices to render now.
 *
 * @param {Array<object>} notices The notices from the server.
 * @param {string[]} closed The ids closed during this visit.
 * @param {number} now Now, in milliseconds.
 * @return {Array<{id: string, message: string, level: string, linkLabel: string, linkUrl: string}>}
 */
export function visibleNotices(notices, closed, now) {
	return (Array.isArray(notices) ? notices : []).filter((notice) => {
		if (!notice || typeof notice.id !== 'string' || !notice.message) {
			return false
		}
		const ends = Date.parse(notice.endsAt)
		return Number.isFinite(ends) && ends > now && !closed.includes(notice.id)
	})
}

/**
 * The session storage, or null where the browser refuses it.
 *
 * @return {Storage|null}
 */
export function sessionStore() {
	try {
		return typeof window !== 'undefined' ? window.sessionStorage : null
	} catch {
		return null
	}
}
