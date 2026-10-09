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

/** The national model's words per status. */
const STATUS_WORDS = {
	A: 'Status A: this website fully meets the requirements.',
	B: 'Status B: this website partly meets the requirements.',
	C: 'Status C: first measures have been taken to meet the requirements.',
	D: 'Status D: no measures have been taken yet to meet the requirements.',
}

/**
 * The lines of the page, in the visitor's language.
 *
 * @param {object|null} statement The server's statement.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {(iso: string) => string} formatDate Writes a date for the visitor.
 * @return {object} `{status, automated, evidence, audit, issues, notMeasured, registerUrl, contact}`.
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-the-status-never-goes-beyond-the-evidence-req-sas-003
 */
export function statementLines(statement, t, formatDate) {
	const value = statement || {}
	const status = STATUS_WORDS[value.status]
		? t(STATUS_WORDS[value.status])
		: t('This website has not been measured yet, so it has no status.')
	const measurement = value.measurement || null
	const audit = value.audit || null
	return {
		status,
		automated:
			value.automatedOnly !== false
				? t(
						"This status rests on the portal's own automated measurement. An automated check does not prove that the website meets the requirements. Only an independent audit can.",
					)
				: '',
		auditExpired: value.auditExpired
			? t('The last audit is older than three years and no longer counts.')
			: '',
		evidence: measurement
			? t(
					'Measured on {date} with axe-core {version}: {measured} pages measured, {skipped} pages could not be measured.',
					{
						date: formatDate(measurement.measuredAt),
						version: measurement.axeVersion || '',
						measured: measurement.pagesMeasured || 0,
						skipped: measurement.pagesNotMeasured || 0,
					},
				)
			: '',
		audit: audit
			? t('Audited by {party} on {date}.', {
					party: audit.party,
					date: formatDate(audit.date),
				})
			: '',
		auditReport: audit ? String(audit.reportUrl || '') : '',
		issues: (value.issues || []).map((issue) => ({
			rule: String(issue.rule || ''),
			sentence: String(issue.sentence || issue.rule || ''),
			detail: t('{impact}, on {pages} pages', {
				impact: t(impactWord(issue.impact)),
				pages: issue.pages || 0,
			}),
			helpUrl: String(issue.helpUrl || ''),
		})),
		notMeasured: (value.notMeasured || []).map((page) => ({
			url: String(page.url || ''),
			reason: String(page.reason || ''),
		})),
		registerUrl: String(value.registerUrl || ''),
		contact: {
			email: String(value.contact?.email || ''),
			phone: String(value.contact?.phone || ''),
		},
	}
}

/**
 * The word for an impact, as a translation key.
 *
 * @param {string} impact axe-core's impact.
 * @return {string}
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 */
function impactWord(impact) {
	return (
		{
			critical: 'Critical',
			serious: 'Serious',
			moderate: 'Moderate',
			minor: 'Minor',
		}[impact] || 'Unknown impact'
	)
}
