/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether a page that takes reports is still counted in visitor statistics.
 *
 * A visitor row on a reporting page is a record that somebody opened the form,
 * which is exactly what must not exist. The collector already refuses an event
 * on a path in the portal's `traffic.excludedPaths`; the designer warns the
 * editor before publishing a page that holds a report widget on a path the
 * list does not cover. The match is the collector's own: the path itself, or
 * anything below it (`TrafficIngestService::isExcluded()`).
 *
 * The HTTP call is handed in, so `node --test` exercises this as a plain module.
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-report-pages-stay-out-of-visitor-statistics-req-irp-003
 */

/** The widgets that take or show a report. */
export const REPORT_WIDGETS = ['report', 'reportThread']

/**
 * A path as the collector compares it: one leading slash, no trailing one.
 *
 * @param {unknown} value A configured path or a page route.
 * @return {string} The normalised path, or '' for nothing usable.
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-report-pages-stay-out-of-visitor-statistics-req-irp-003
 */
export function normalisePath(value) {
	if (typeof value !== 'string') {
		return ''
	}
	let path = value.trim()
	if (path === '') {
		return ''
	}
	if (!path.startsWith('/')) {
		path = '/' + path
	}
	path = path.replace(/\/+$/, '')
	return path === '' ? '/' : path
}

/**
 * Whether a list of widgets holds a report widget.
 *
 * @param {Array<object>} widgets The page's widget entries.
 * @return {boolean} True when one of them takes or shows a report.
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-report-pages-stay-out-of-visitor-statistics-req-irp-003
 */
export function holdsReportWidget(widgets) {
	return (Array.isArray(widgets) ? widgets : []).some((widget) =>
		REPORT_WIDGETS.includes(String(widget?.widgetKey || '')),
	)
}

/**
 * Whether a path is covered by the excluded paths, as the collector decides.
 *
 * @param {string} route The page's route.
 * @param {Array<string>} excludedPaths The portal's `traffic.excludedPaths`.
 * @return {boolean} True when no visitor row is written for this route.
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-report-pages-stay-out-of-visitor-statistics-req-irp-003
 */
export function isExcludedPath(route, excludedPaths) {
	const path = normalisePath(route) || '/'
	return (Array.isArray(excludedPaths) ? excludedPaths : [])
		.map(normalisePath)
		.filter((prefix) => prefix !== '')
		.some((prefix) => path === prefix || path.startsWith(prefix + '/'))
}

/**
 * Whether the designer must warn that this page is still counted.
 *
 * Unknown excluded paths (`null`: the portal could not be read) give no
 * warning: the editor cannot act on a list they cannot see, and the collector
 * still refuses what the list covers.
 *
 * @param {Array<object>} widgets The page's widget entries.
 * @param {string} route The page's route.
 * @param {Array<string>|null} excludedPaths The portal's excluded paths, or null when unknown.
 * @return {boolean} True when a report widget sits on a counted page.
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-report-pages-stay-out-of-visitor-statistics-req-irp-003
 */
export function reportPageIsCounted(widgets, route, excludedPaths) {
	if (excludedPaths === null || excludedPaths === undefined) {
		return false
	}
	return holdsReportWidget(widgets) && !isExcludedPath(route, excludedPaths)
}

/**
 * Read a portal's excluded paths by its slug.
 *
 * @param {(url: string, config: object) => Promise<{data: object}>} get Reads the portal collection.
 * @param {string} url The portal collection URL.
 * @param {string} slug The portal's slug.
 * @return {Promise<Array<string>|null>} The paths, or null when the portal cannot be read.
 *
 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-report-pages-stay-out-of-visitor-statistics-req-irp-003
 */
export async function loadExcludedPaths(get, url, slug) {
	const name = String(slug || '').trim()
	if (name === '') {
		return null
	}
	try {
		const { data } = await get(url, { params: { slug: name, _limit: 1 } })
		const portal = (Array.isArray(data?.results) ? data.results : []).find(
			(row) => String(row?.slug || '') === name,
		)
		if (!portal) {
			return null
		}
		const paths = portal?.traffic?.excludedPaths
		return Array.isArray(paths) ? paths.filter((p) => typeof p === 'string') : []
	} catch {
		return null
	}
}
