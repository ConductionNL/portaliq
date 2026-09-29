/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a widget sits on the 12-column page grid: ONE function for the public
 * renderer and the editor.
 *
 * `src/site/components/WidgetGrid.vue` places a cell with this, and the editor
 * (`gridModel.storedWidget()`, `normaliseWidgets()`) stores what this answers.
 * So a widget the editor shows in columns 7 to 12 is a widget the visitor sees
 * in columns 7 to 12: the two cannot read the same numbers differently. This
 * file is imported by the site ENTRY, so it stays a few lines with no imports.
 *
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-the-editor-and-the-public-page-must-place-widgets-identically-req-pie-008
 */

/** The number of columns every page grid has. */
export const GRID_COLUMNS = 12

/**
 * The clamped cell of a widget: a column inside the grid, a width that does
 * not run past its right edge, at least one row high.
 *
 * @param {{gridX?: number, gridY?: number, gridWidth?: number, gridHeight?: number}} widget The placement.
 * @return {{gridX: number, gridY: number, gridWidth: number, gridHeight: number}} The cell.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-the-editor-and-the-public-page-must-place-widgets-identically-req-pie-008
 */
export function cellOf(widget) {
	const gridX = Math.max(
		0,
		Math.min(GRID_COLUMNS - 1, Math.trunc(Number(widget?.gridX) || 0)),
	)
	const gridWidth = Math.max(
		1,
		Math.min(
			GRID_COLUMNS - gridX,
			Math.trunc(Number(widget?.gridWidth) || GRID_COLUMNS),
		),
	)
	const gridY = Math.max(0, Math.trunc(Number(widget?.gridY) || 0))
	const gridHeight = Math.max(1, Math.trunc(Number(widget?.gridHeight) || 1))
	return { gridX, gridY, gridWidth, gridHeight }
}

/**
 * The CSS grid placement of a widget, as the public renderer applies it.
 *
 * @param {object} widget The placement.
 * @return {{gridColumn: string, gridRow: string}} The style.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-the-editor-and-the-public-page-must-place-widgets-identically-req-pie-008
 */
export function cellStyleOf(widget) {
	const cell = cellOf(widget)
	return {
		gridColumn: `${cell.gridX + 1} / span ${cell.gridWidth}`,
		gridRow: `${cell.gridY + 1} / span ${cell.gridHeight}`,
	}
}
