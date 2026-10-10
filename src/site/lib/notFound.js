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
