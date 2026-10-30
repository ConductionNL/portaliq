/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { cellOf } from '../../editor/geometry.js'

/**
 * Where a page's widgets land: the two pure functions behind `WidgetGrid`.
 *
 * Outside the SFC so a plain node test can reach them. The placement is the
 * part that was wrong: a band pulled out of the grid left a 320px void above
 * the run below it on the reference branch (46d7e9f).
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
 */

/**
 * Split a widget list into alternating full-bleed bands and runs of cells.
 *
 * Order is kept as authored. Each run carries a `rowOffset`, its first
 * authored row: `gridY` is absolute over the whole page, so without it a run
 * below a band opens with empty rows reserved for the band.
 *
 * @param {Array}                    widgets The placements, in order.
 * @param {(key: string, widget: object) => boolean} isBand  Whether a widget is a band.
 * @return {Array} `{band: true, widget}` and `{band: false, widgets, rowOffset}` entries.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
 */
export function runsFor(widgets, isBand) {
	const out = []

	for (const widget of widgets || []) {
		if (isBand(widget.widgetKey, widget) === true) {
			out.push({ band: true, widget })
			continue
		}

		const last = out[out.length - 1]
		if (last && last.band === false) {
			last.widgets.push(widget)
		} else {
			out.push({ band: false, widgets: [widget], rowOffset: 0 })
		}
	}

	for (const run of out) {
		if (run.band === false) {
			run.rowOffset = Math.min(
				...run.widgets.map((w) => Math.max(0, Number(w.gridY) || 0)),
			)
		}
	}

	return out
}

/**
 * Place one widget on the 12-column grid, re-based onto its run.
 *
 * `gridX + gridWidth > 12` is clamped rather than thrown on: on a public page
 * clamping shows the content and a throw shows nothing.
 *
 * @param {object} widget    The placement.
 * @param {number} rowOffset The run's first authored row; 0 for absolute rows.
 * @return {object} `{gridColumn, gridRow}` style bindings.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-the-editor-and-the-public-page-must-place-widgets-identically-req-pie-008
 */
export function cellStyle(widget, rowOffset = 0) {
	// The column, width and height come from the SAME function the editor
	// stores its geometry with (portal-in-place-editing REQ-PIE-008), so the
	// editor and the public page cannot place a widget differently. Only the
	// row is re-based onto the run.
	const cell = cellOf(widget)
	const row = Math.max(0, cell.gridY - (Number(rowOffset) || 0))

	return {
		gridColumn: `${cell.gridX + 1} / span ${cell.gridWidth}`,
		gridRow: `${row + 1} / span ${cell.gridHeight}`,
	}
}

/**
 * This app's own bands, beside the library's: a widget that paints edge to
 * edge and brings its own container. `nlLinkColumns` always; `nlBanner` when
 * its placement asks for it (`band: true`), so every banner placed before
 * this stays in its grid cell (site-matches-the-zuiddrecht-boards).
 *
 * @param {string} key    The widget key.
 * @param {object} widget The placement.
 * @return {boolean} True when it must not be wrapped in a grid cell.
 *
 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-link-columns-draw-a-heading-over-columns-of-links-on-a-band
 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-banner-may-carry-a-lead-and-a-link
 */
export function ownBand(key, widget) {
	if (key === 'nlLinkColumns') {
		return true
	}
	return key === 'nlBanner' && widget?.props?.band === true
}

/**
 * The page's aside column, when its body asks for one (`aside: {width, gap}`,
 * in pixels): the right-hand column of a run is then that wide and the left
 * one takes the rest, as the Zuiddrecht Home board draws its "Mijn
 * Zuiddrecht" card beside the news. A width outside 160 to 640 is ignored.
 *
 * @param {object|null} body The page body.
 * @return {{width: number, gap: number}|null} The aside, or null.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-may-give-its-aside-a-fixed-width
 */
export function asideOf(body) {
	const aside = body && typeof body === 'object' ? body.aside : null
	const width = Number(aside?.width)
	if (!Number.isFinite(width) || width < 160 || width > 640) {
		return null
	}
	const gap = Number(aside.gap)
	return {
		width,
		gap: Number.isFinite(gap) && gap >= 0 && gap <= 160 ? gap : 48,
	}
}

/**
 * Whether a run is a main column beside an aside: something starts at the
 * left edge narrower than the page, something ends at the right edge
 * starting further in, and every other widget is full width.
 *
 * @param {Array<object>} widgets The run's placements.
 * @return {boolean}
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-may-give-its-aside-a-fixed-width
 */
export function isAsideRun(widgets) {
	const cells = (widgets || []).map((widget) => cellOf(widget))
	const main = cells.some((cell) => cell.gridX === 0 && cell.gridWidth < 12)
	const side = cells.some(
		(cell) => cell.gridX > 0 && cell.gridX + cell.gridWidth === 12,
	)
	const rest = cells.every(
		(cell) =>
			cell.gridWidth >= 12
			|| cell.gridX === 0
			|| cell.gridX + cell.gridWidth === 12,
	)
	return main && side && rest
}

/**
 * Place one widget in a run laid out as main column and aside: a full-width
 * widget spans both, one at the left edge takes the main column, the rest
 * the aside. Rows are placed as `cellStyle` places them.
 *
 * @param {object} widget    The placement.
 * @param {number} rowOffset The run's first authored row.
 * @return {object} `{gridColumn, gridRow}` style bindings.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-may-give-its-aside-a-fixed-width
 */
export function asideCellStyle(widget, rowOffset = 0) {
	const cell = cellOf(widget)
	const { gridRow } = cellStyle(widget, rowOffset)
	let gridColumn = '2'
	if (cell.gridWidth >= 12) {
		gridColumn = '1 / -1'
	} else if (cell.gridX === 0) {
		gridColumn = '1'
	}
	return { gridColumn, gridRow }
}
