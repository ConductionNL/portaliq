// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The logic behind the Availability report (operate-availability-report):
// where it reads, and how its numbers and causes read in words.

/**
 * The admin route for one portal's last twelve months.
 *
 * @param {string} slug The portal slug.
 * @param {(path: string) => string} generateUrl Nextcloud's URL generator.
 * @return {string}
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
export function availabilityUrl(slug, generateUrl) {
	return generateUrl(
		`/apps/portaliq/api/availability/${encodeURIComponent(slug)}?months=12`,
	)
}

/**
 * The CSV download of the same report.
 *
 * @param {string} slug The portal slug.
 * @param {(path: string) => string} generateUrl Nextcloud's URL generator.
 * @return {string}
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
export function exportUrl(slug, generateUrl) {
	return generateUrl(
		`/apps/portaliq/api/availability/${encodeURIComponent(slug)}/export?months=12`,
	)
}

/**
 * A month's availability as text.
 *
 * @param {number|null} percentage The percentage, or null when not measured.
 * @param {(app: string, text: string, vars?: object) => string} t The translator.
 * @return {string}
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
export function percentLabel(percentage, t) {
	if (percentage === null || percentage === undefined) {
		return t('portaliq', 'Not measured')
	}
	return `${Number(percentage).toFixed(2)}%`
}

/**
 * Why an outage began, in words.
 *
 * @param {string} cause The stored cause.
 * @param {(app: string, text: string, vars?: object) => string} t The translator.
 * @return {string}
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
export function causeLabel(cause, t) {
	switch (cause) {
		case 'site-error':
			return t('portaliq', 'The portal answered with an error')
		case 'timeout':
			return t('portaliq', 'The portal did not answer within five seconds')
		case 'health-degraded':
			return t(
				'portaliq',
				'The portal answered, but its health check did not say ok',
			)
		case 'no-check':
			return t('portaliq', 'No check ran')
		default:
			return cause
	}
}

/**
 * An outage's length in words.
 *
 * @param {number} minutes The duration.
 * @param {(app: string, text: string, vars?: object) => string} t The translator.
 * @return {string}
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
export function durationLabel(minutes, t) {
	if (!minutes) {
		return t('portaliq', 'Less than a minute')
	}
	return t('portaliq', '{minutes} minutes', { minutes })
}

/**
 * The published portals as picker options, by title.
 *
 * @param {object|Array<object>} body OpenRegister's list answer.
 * @return {Array<{id: string, label: string}>}
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
export function portalsFrom(body) {
	const rows = Array.isArray(body) ? body : (body?.results ?? [])
	return rows
		.filter((row) => row && row.slug)
		.map((row) => ({
			id: String(row.slug),
			label: String(row.title || row.slug),
		}))
		.sort((first, second) => first.label.localeCompare(second.label))
}
