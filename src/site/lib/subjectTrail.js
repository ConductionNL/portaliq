/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The breadcrumb and the marked menu item of a page that shows one subject
 * chosen by the route, such as a news article at `/nieuws/<id>`
 * (site-article-page-follows-the-board).
 *
 * The board Artikel reads "Home › Nieuws en documenten › De Kinderboekenweek
 * is begonnen" and marks "Nieuws" in the menu. The route alone gives
 * "Home › Nieuws › Nieuws": the id segment takes the page's own title and
 * the menu marks nothing. The block that reads the subject tells the page
 * its title and, when declared, the section it sits under.
 */

/**
 * A search term from a block, or '' for anything else. A native `search`
 * event bubbles out of every `<input type="search">` when Enter is pressed;
 * a block that does not declare the event lets it fall through to its root,
 * and the page then searched for "[object Event]".
 *
 * @param {string|Event} term What the block emitted.
 * @return {string|null} The term, or null when it was not one.
 * @spec openspec/changes/site-article-page-follows-the-board/specs/site-look/spec.md#requirement-enter-in-a-blocks-own-search-field-searches-that-block
 */
export function searchTermOf(term) {
	return typeof term === 'string' ? term : null
}

/**
 * The subject a block told, made safe: a title, and a section with an
 * in-site route, or null.
 *
 * @param {object|null} told What the block emitted.
 * @return {{title: string, section: {route: string, label: string}|null}|null}
 * @spec openspec/changes/site-article-page-follows-the-board/specs/site-look/spec.md#requirement-a-news-article-reads-like-the-article-board
 */
export function subjectOf(told) {
	const title = String(told?.title ?? '').trim()
	if (title === '') {
		return null
	}
	const route = String(told?.section?.route ?? '')
	return {
		title,
		section: /^\/(?!\/)/.test(route)
			? { route, label: String(told.section.label ?? '').trim() }
			: null,
	}
}

/**
 * The trail with the subject in it: its title as the last crumb, and the
 * section, when told, as the only crumb between home and it.
 *
 * @param {Array<{route: string, label: string, href: string}>} crumbs The route's trail, home first.
 * @param {object|null} subject From `subjectOf()`.
 * @param {object} helpers How to name and address a route.
 * @param {function(string): string} helpers.labelFor The menu's words for a route, or ''.
 * @param {function(string): string} helpers.hrefFor The address of a route.
 * @return {Array<{route: string, label: string, href: string}>}
 * @spec openspec/changes/site-article-page-follows-the-board/specs/site-look/spec.md#requirement-a-news-article-reads-like-the-article-board
 */
export function subjectCrumbs(crumbs, subject, { labelFor, hrefFor }) {
	if (!subject || !Array.isArray(crumbs) || crumbs.length < 2) {
		return crumbs
	}
	const last = { ...crumbs[crumbs.length - 1], label: subject.title }
	if (!subject.section) {
		return [...crumbs.slice(0, -1), last]
	}
	const route = subject.section.route
	const words = route.replace(/\/+$/, '').split('/').pop() || ''
	const label =
		subject.section.label
		|| labelFor(route)
		|| words.charAt(0).toUpperCase() + words.slice(1)
	return [crumbs[0], { route, label, href: hrefFor(route) }, last]
}

/**
 * The route the menu marks: inside the subject's section when it has one,
 * so that section's item carries `aria-current="true"`; else the route.
 *
 * @param {string} route The route on screen.
 * @param {object|null} subject From `subjectOf()`.
 * @return {string}
 * @spec openspec/changes/site-article-page-follows-the-board/specs/site-look/spec.md#requirement-a-news-article-reads-like-the-article-board
 */
export function menuRouteOf(route, subject) {
	if (!subject?.section) {
		return route
	}
	const tail =
		String(route || '')
			.split('/')
			.filter(Boolean)
			.pop() || 'item'
	return `${subject.section.route.replace(/\/+$/, '')}/${tail}`
}
