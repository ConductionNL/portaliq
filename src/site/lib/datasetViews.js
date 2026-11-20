/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The plain logic behind the dataset views on the publication page
 * (dataset-views-on-the-public-page, REQ-DSV-003): which views a publication
 * carries, the table every view can be read as, and the geometry of the bar
 * and line charts. The shapes are the ones opencatalogi's view data endpoint
 * answers (design D3). No imports, so tests/dataset-views.spec.mjs runs it in
 * node.
 *
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
 */

/** The kinds a view can be; anything else is read as a table. */
export const VIEW_KINDS = ['table', 'bar', 'line', 'map']

/** The chart's drawing box, in SVG units. */
export const CHART_BOX = { width: 640, height: 320, left: 56, right: 16, top: 16, bottom: 48 }

/** Rows per page of a table view. */
export const TABLE_PAGE_SIZE = 20

/**
 * The publication's tables that carry at least one view, each with its views
 * in their saved order. A malformed entry is left out, never an error.
 *
 * @param {object|null} publication The publication answer.
 * @return {Array<{id: string, title: string, rowsUrl: string, views: Array<object>}>} The tables.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
 */
export function tablesWithViews(publication) {
	const tables = Array.isArray(publication?.tables) ? publication.tables : []

	return tables
		.filter((table) => table && typeof table === 'object')
		.map((table) => ({
			id: String(table.id || ''),
			title: String(table.title || ''),
			rowsUrl: typeof table.rowsUrl === 'string' ? table.rowsUrl : '',
			views: (Array.isArray(table.views) ? table.views : [])
				.filter((view) => view && typeof view === 'object' && String(view.id || '') !== '' && typeof view.dataUrl === 'string' && view.dataUrl !== '')
				.map((view, index) => ({
					...view,
					id: String(view.id),
					kind: VIEW_KINDS.includes(view.kind) ? view.kind : 'table',
					title: String(view.title || ''),
					order: Number.isFinite(Number(view.position)) ? Number(view.position) : index,
				}))
				.sort((a, b) => a.order - b.order),
		}))
		.filter((table) => table.views.length > 0)
}

/**
 * A cell value as text: numbers in the page's locale, empty for nothing.
 *
 * @param {unknown} value The value.
 * @param {string} locale The page's language.
 * @return {string} The text.
 */
export function cellText(value, locale = 'nl') {
	if (value === null || value === undefined) {
		return ''
	}
	if (typeof value === 'number' && Number.isFinite(value)) {
		return value.toLocaleString(locale === 'en' ? 'en-GB' : 'nl-NL', { useGrouping: false, maximumFractionDigits: 6 })
	}
	if (typeof value === 'object') {
		return JSON.stringify(value)
	}

	return String(value)
}

/**
 * The points of a bar or line answer, with a numeric value; a point without
 * a number counts as 0 so the chart and its table agree.
 *
 * @param {object|null} data The data answer.
 * @return {Array<{x: string, value: number}>} The points.
 */
export function chartPoints(data) {
	return (Array.isArray(data?.points) ? data.points : [])
		.filter((point) => point && typeof point === 'object')
		.map((point) => {
			const value = Number(point.value)
			return {
				x: point.x === null || point.x === undefined ? '' : String(point.x),
				value: Number.isFinite(value) ? value : 0,
			}
		})
}

/**
 * The table any view can be read as: a header and rows of text cells.
 *
 * - `table`: the view's columns and the rows of the page.
 * - `bar`, `line`: one row per group, with its value ("Show as table").
 * - `map`: one row per point, with its label and coordinates.
 *
 * @param {object} view The view.
 * @param {object|null} data The data answer.
 * @param {{t: (key: string, vars?: object) => string, locale?: string}} options The translator and language.
 * @return {{columns: Array<string>, rows: Array<Array<string>>}} The table.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
 */
export function viewTable(view, data, { t, locale = 'nl' }) {
	const kind = view?.kind
	if (kind === 'bar' || kind === 'line') {
		const label = data?.aggregate === 'count' || !data?.y
			? t('Number')
			: String(data.y)
		return {
			columns: [String(data?.x || view.x || ''), label],
			rows: chartPoints(data).map((point) => [point.x, cellText(point.value, locale)]),
		}
	}

	if (kind === 'map') {
		const features = Array.isArray(data?.features) ? data.features : []
		return {
			columns: [t('Label'), t('Latitude'), t('Longitude')],
			rows: features
				.filter((feature) => Array.isArray(feature?.geometry?.coordinates))
				.map((feature) => {
					const [lon, lat] = feature.geometry.coordinates
					return [cellText(feature.properties?.label, locale), cellText(lat, locale), cellText(lon, locale)]
				}),
		}
	}

	const columns = Array.isArray(data?.columns) && data.columns.length > 0
		? data.columns.map(String)
		: (Array.isArray(view?.columns) ? view.columns.map(String) : [])
	const results = Array.isArray(data?.results) ? data.results : []

	return {
		columns,
		rows: results.map((row) => columns.map((column) => cellText(row?.[column], locale))),
	}
}

/**
 * The bars of a bar chart, in the drawing box.
 *
 * @param {Array<{x: string, value: number}>} points The points.
 * @param {object} box The drawing box.
 * @return {{bars: Array<{x: number, y: number, width: number, height: number, label: string, value: number}>, max: number, min: number, baseline: number}} The geometry.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
 */
export function barGeometry(points, box = CHART_BOX) {
	const { max, min, scale, baseline } = scaleOf(points, box)
	const plotWidth = box.width - box.left - box.right
	const step = points.length > 0 ? plotWidth / points.length : 0
	const gap = Math.min(8, step * 0.2)

	return {
		max,
		min,
		baseline,
		bars: points.map((point, index) => {
			const top = scale(Math.max(point.value, 0))
			const bottom = scale(Math.min(point.value, 0))
			return {
				x: round(box.left + index * step + gap / 2),
				y: round(top),
				width: round(Math.max(step - gap, 1)),
				height: round(Math.max(bottom - top, 0)),
				label: point.x,
				value: point.value,
			}
		}),
	}
}

/**
 * The line of a line chart, in the drawing box: an SVG path and its points.
 *
 * @param {Array<{x: string, value: number}>} points The points.
 * @param {object} box The drawing box.
 * @return {{path: string, dots: Array<{x: number, y: number, label: string, value: number}>, max: number, min: number, baseline: number}} The geometry.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
 */
export function lineGeometry(points, box = CHART_BOX) {
	const { max, min, scale, baseline } = scaleOf(points, box)
	const plotWidth = box.width - box.left - box.right
	const step = points.length > 1 ? plotWidth / (points.length - 1) : 0
	const dots = points.map((point, index) => ({
		x: round(points.length > 1 ? box.left + index * step : box.left + plotWidth / 2),
		y: round(scale(point.value)),
		label: point.x,
		value: point.value,
	}))

	return {
		max,
		min,
		baseline,
		dots,
		path: dots.map((dot, index) => `${index === 0 ? 'M' : 'L'}${dot.x} ${dot.y}`).join(' '),
	}
}

/**
 * The places of a map answer, as the portal's location map reads them.
 *
 * @param {object|null} data The map answer.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {Array<{name: string, lat: number, lon: number}>} The places.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
 */
export function mapPlaces(data, t) {
	const features = Array.isArray(data?.features) ? data.features : []

	return features
		.filter((feature) => Array.isArray(feature?.geometry?.coordinates))
		.map((feature, index) => {
			const [lon, lat] = feature.geometry.coordinates
			const label = feature.properties?.label
			return {
				name: label === null || label === undefined || String(label).trim() === ''
					? t('Point {number}', { number: index + 1 })
					: String(label),
				lat: Number(lat),
				lon: Number(lon),
			}
		})
		.filter((place) => Number.isFinite(place.lat) && Number.isFinite(place.lon))
}

/**
 * The address of one page of a table view: the table's rows endpoint with
 * the page and its size. Page 1 is the view's own data answer.
 *
 * @param {string} rowsUrl The table's rows endpoint.
 * @param {number} page The page, from 1.
 * @param {string} origin The page's origin, for a relative endpoint.
 * @return {string} The address, or '' without an endpoint.
 * @spec openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#requirement-the-public-page-draws-each-view-and-offers-its-data-as-a-table-req-dsv-003
 */
export function pageUrl(rowsUrl, page, origin = 'http://localhost') {
	if (!rowsUrl) {
		return ''
	}
	const url = new URL(rowsUrl, origin)
	url.searchParams.set('_page', String(Math.max(1, Math.floor(Number(page) || 1))))
	url.searchParams.set('_limit', String(TABLE_PAGE_SIZE))

	return url.toString()
}

/**
 * The number of pages of a table answer, at least 1.
 *
 * @param {object|null} data The data answer.
 * @return {number} The pages.
 */
export function pagesOf(data) {
	const pages = Math.floor(Number(data?.pages))
	return Number.isFinite(pages) && pages > 1 ? pages : 1
}

/**
 * The value scale of a chart: from the lowest of 0 and the values to the
 * highest of 0 and the values, onto the box's plot height.
 *
 * @param {Array<{value: number}>} points The points.
 * @param {object} box The drawing box.
 * @return {{max: number, min: number, scale: Function, baseline: number}} The scale.
 */
function scaleOf(points, box) {
	const values = points.map((point) => point.value)
	const max = Math.max(0, ...values)
	const min = Math.min(0, ...values)
	const span = max - min || 1
	const plotHeight = box.height - box.top - box.bottom
	const scale = (value) => box.top + ((max - value) / span) * plotHeight

	return { max, min, scale, baseline: round(scale(0)) }
}

/**
 * A coordinate rounded to two decimals, so the SVG stays short.
 *
 * @param {number} value The value.
 * @return {number} The rounded value.
 */
function round(value) {
	return Math.round(value * 100) / 100
}
