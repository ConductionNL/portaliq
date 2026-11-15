// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Where a widget of a detail page is drawn, in the v2 detail shape.
//
// A detail page no longer lists every widget in `config.layout`. A widget is
// drawn when it is in the layout, in the `sideColumn`, in a tab of a `tabs`
// widget, in a section of a placed `sections` widget (the portal's
// Instellingen tab), or inside a composite app widget that renders it. A spec
// that asks "is this widget placed?" must ask all of these, or it fails on a
// page that is perfectly fine.

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

/**
 * The widget ids a detail page draws, whatever the place.
 *
 * @param {object} config The page's `config`.
 * @return {Set<string>} The ids.
 */
export function placedIds(config) {
	const ids = new Set((config.layout || []).map((cell) => cell.widgetId))
	for (const id of config.sideColumn || []) {
		ids.add(typeof id === 'string' ? id : id?.widgetId)
	}
	for (const widget of config.widgets || []) {
		if (widget.type === 'tabs' && ids.has(widget.id)) {
			for (const tab of widget.content?.tabs || []) {
				ids.add(tab.widgetId)
				// A stack (`widgetIds`): every widget of it is drawn in the tab.
				for (const id of tab.widgetIds || []) {
					ids.add(id)
				}
			}
		}
	}
	// After the tabs: a sections widget is usually a tab's child.
	for (const widget of config.widgets || []) {
		if (widget.type === 'sections' && ids.has(widget.id)) {
			for (const section of widget.content?.sections || []) {
				if (section.widgetId) {
					ids.add(section.widgetId)
				}
			}
		}
	}
	return ids
}

/**
 * Is this widget drawn on the page, in the layout, the side column or a tab?
 *
 * @param {object} config The page's `config`.
 * @param {object} widget The widget definition.
 * @return {boolean} True when it is drawn.
 */
export function isPlaced(config, widget) {
	return placedIds(config).has(widget.id)
}

/**
 * The widget components a composite widget renders, read from its imports.
 *
 * @param {string} type The composite widget's type, an app widget in src/widgets.
 * @return {string[]} The component names it imports from ./ in src/widgets.
 */
export function composedOf(type) {
	let source
	try {
		source = readFileSync(join(ROOT, 'src', 'widgets', `${type}.vue`), 'utf8')
	} catch {
		return []
	}
	return [...source.matchAll(/import (\w+) from '\.\/(\w+)\.vue'/g)].map((m) => m[2])
}

/**
 * Does the page draw a widget of this type, directly or inside a placed composite?
 *
 * @param {object} config The page's `config`.
 * @param {string} type The widget type.
 * @return {boolean} True when a user can see it on this page.
 */
export function pageDraws(config, type) {
	const placed = placedIds(config)
	return (config.widgets || []).some(
		(widget) =>
			placed.has(widget.id) &&
			(widget.type === type || composedOf(widget.type).includes(type)),
	)
}
