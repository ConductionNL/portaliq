/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The words of the breadcrumb (site-breadcrumb-follows-the-school-boards).
 *
 * Zuiddrecht's board Kop names a page by the header menu's words ("Home ›
 * Afval"). The school boards name the page on screen by its own title
 * ("Home › Nieuws en documenten", where the menu says "Nieuws"), and the
 * sign-in page as "Inloggen". The portal chooses with `breadcrumb`.
 */

/**
 * The words of one crumb.
 *
 * @param {object} words What names the route.
 * @param {string} words.fromRoute The route segment, humanised (or the page title for the last crumb).
 * @param {string} words.fromMenu The header menu's words for the route, or ''.
 * @param {boolean} words.isLast Whether this is the page on screen.
 * @param {string} words.pageTitle The page's own title, or ''.
 * @param {string} words.choice The portal's `breadcrumb`: `menu` or `page`.
 * @return {string}
 * @spec openspec/changes/site-breadcrumb-follows-the-school-boards/specs/site-look/spec.md#requirement-a-portal-chooses-the-words-of-the-last-crumb
 */
export function crumbLabel({ fromRoute, fromMenu, isLast, pageTitle, choice }) {
	if (isLast && choice === 'page' && String(pageTitle || '').trim() !== '') {
		return String(pageTitle).trim()
	}
	return fromMenu || fromRoute
}

/**
 * The trail of the sign-in page: the own area shown to a visitor who is not
 * signed in IS the sign-in page, so its crumb says so.
 *
 * @param {string} route The route on screen.
 * @param {function(string): string} t The translator.
 * @param {function(string): string} hrefFor The address of a route.
 * @return {Array<{route: string, label: string, href: string}>}
 * @spec openspec/changes/site-breadcrumb-follows-the-school-boards/specs/site-look/spec.md#requirement-the-sign-in-page-is-named-sign-in-in-the-breadcrumb
 */
export function signInCrumbs(route, t, hrefFor) {
	return [
		{ route: '/', label: t('Home'), href: hrefFor('/') },
		{ route, label: t('Log in'), href: hrefFor(route) },
	]
}
