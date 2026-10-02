/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The props and events every page in this folder shares. See the `SitePageProps`
 * typedef in index.js for what each one carries.
 */

/** Vue props of a signed-in page. */
export const PAGE_PROPS = {
	/** The session as `/portal/api/session` returns it. */
	session: { type: Object, default: null },
	/** The portal record. */
	portal: { type: Object, default: null },
	/** The shared portal API bound to the site's bearer. */
	api: { type: Object, required: true },
	/** The shell's translator. */
	t: { type: Function, default: null },
	/** `navigate(route)` with an in-site route; without it the page emits `navigate`. */
	navigate: { type: Function, default: null },
	/** The page language. */
	locale: { type: String, default: '' },
	/** The navigation entry on screen. */
	entry: { type: Object, default: null },
	/** The contributions aggregate (carries `unreadCount`). */
	contributions: { type: Object, default: null },
	/** Every navigation entry. */
	nav: { type: Array, default: () => [] },
}

/** Events a signed-in page may emit. */
export const PAGE_EMITS = ['navigate', 'unread', 'refresh']
