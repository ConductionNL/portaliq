// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The accessibility statement page every portal serves at /toegankelijkheid
// (site-accessibility-statement REQ-SAS-002), without the page.
//
// The statement is generated on the server from the latest measurement and
// the recorded audit (`GET /api/content/accessibility`). This module names the
// route, puts the statement's link in the footer's legal strip, and turns the
// server's answer into the lines the page shows. It never raises a status:
// the status is the server's, and a missing one stays missing.
//
// Imports nothing, so tests/site-accessibility-statement.spec.mjs runs it as node.
// It is in the site entry, so it holds only the route and the footer link; the
// lines of the page itself live in statementLines.js, loaded with the page.
//
// @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002

/** The in-site route of the statement, the same on every portal. */
export const STATEMENT_ROUTE = '/toegankelijkheid'

/**
 * Whether a route is the statement's.
 *
 * @param {string} route The in-site route.
 * @return {boolean}
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 */
export function isStatementRoute(route) {
	return String(route || '').replace(/\/+$/, '') === STATEMENT_ROUTE
}

/**
 * The legal strip's links with the statement's link last, unless the portal
 * already links to it.
 *
 * @param {Array<{label: string, href: string}>} links The portal's legal links.
 * @param {string} label The link's text in the visitor's language.
 * @return {Array<{label: string, href: string}>}
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 */
export function withStatementLink(links, label) {
	const list = Array.isArray(links) ? links : []
	const present = list.some((item) =>
		String(item?.href || '')
			.replace(/\/+$/, '')
			.endsWith(STATEMENT_ROUTE),
	)
	return present ? list : [...list, { label, href: STATEMENT_ROUTE }]
}
