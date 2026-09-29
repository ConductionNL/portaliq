/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

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
 * @param {(key: string) => boolean} isBand  Whether a widget key is a band.
 * @return {Array} `{band: true, widget}` and `{band: false, widgets, rowOffset}` entries.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
 */
export function runsFor(widgets, isBand) {
	const out = []

	for (const widget of widgets || []) {
		if (isBand(widget.widgetKey) === true) {
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
 */
export function cellStyle(widget, rowOffset = 0) {
	const x = Math.max(0, Math.min(11, Number(widget.gridX) || 0))
	const width = Math.max(1, Math.min(12 - x, Number(widget.gridWidth) || 12))
	const height = Math.max(1, Number(widget.gridHeight) || 1)
	const row = Math.max(0, (Number(widget.gridY) || 0) - (Number(rowOffset) || 0))

	return {
		gridColumn: `${x + 1} / span ${width}`,
		gridRow: `${row + 1} / span ${height}`,
	}
}
