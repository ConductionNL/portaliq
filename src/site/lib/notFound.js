// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the not-found page offers (contact-page-question-form-and-not-found):
// the links a lost visitor can take, and whether the portal has a contact page
// to report a broken link to. Pure, so a node test runs it.

/**
 * The route the portal reports broken links to: its own `contactRoute` when
 * that is an in-site route, otherwise `/contact`.
 *
 * @param {object} site The public site record.
 * @return {string} The route.
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
 */
export function contactRouteOf(site) {
	const route = String(site?.contactRoute || '')
	return /^\/(?!\/)/.test(route) ? route : '/contact'
}

/**
 * Whether the portal publishes a page at a route.
 *
 * @param {Array<{route?: string}>} pages The portal's published page summaries.
 * @param {string} route The route.
 * @return {boolean}
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
 */
export function hasPage(pages, route) {
	const wanted = String(route || '').replace(/\/+$/, '') || '/'
	return (Array.isArray(pages) ? pages : []).some(
		(page) => (String(page?.route || '').replace(/\/+$/, '') || '/') === wanted,
	)
}

/**
 * What the page shows besides its heading.
 *
 * The contact link, and the sentence that asks for a report through it, are
 * left out together when the portal has no contact page, so the page never
 * points at a second 404.
 *
 * @param {object} input What the shell knows.
 * @param {string} input.contactRoute The portal's contact route.
 * @param {Array<object>|null} input.pages The published pages, or null while unknown.
 * @param {boolean} input.hasResidentArea Whether the portal has a resident area.
 * @param {string} input.residentLabel The resident area's name ("Mijn Zuiddrecht").
 * @param {boolean} input.searchEnabled Whether the portal has search.
 * @param {(key: string, vars?: object) => string} input.t The translator.
 * @return {{links: Array<{route: string, label: string, kind: string}>, report: string, search: boolean}} The view.
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t04
 */
export function notFoundView({
	contactRoute,
	pages,
	hasResidentArea,
	residentLabel,
	searchEnabled,
	t,
}) {
	const links = [{ route: '/', label: t('The homepage'), kind: 'home' }]
	if (hasResidentArea === true) {
		links.push({
			route: '/mijn',
			label: residentLabel || t('My account'),
			kind: 'resident',
		})
	}
	const contactExists = Array.isArray(pages) && hasPage(pages, contactRoute)
	if (contactExists) {
		links.push({ route: contactRoute, label: t('Contact'), kind: 'contact' })
	}
	return {
		links,
		report: contactExists
			? t('Did you get here through a link on our website? Let us know through Contact, and we will repair the link.')
			: '',
		search: searchEnabled === true,
	}
}
