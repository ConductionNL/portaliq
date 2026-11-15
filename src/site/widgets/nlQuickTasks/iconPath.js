// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The icon a task tile draws (site-matches-the-zuiddrecht-boards): a named
// icon from this app's family, or the author's own path on the 24 grid.
// Imports only the icon family, so node tests it (tests/site-pixel-match.spec.mjs).

import icons from './icons.js'

/** What a path on the 24 grid may contain: SVG path commands and numbers, nothing else. */
const PATH_DATA = /^[MmLlHhVvCcSsQqTtAaZz0-9 .,-]+$/

/**
 * The icon an item draws: a named icon from this app's family, or the
 * author's own path on the 24 grid (`iconPath`), held to path data so a
 * placement can draw a shape but never inject markup.
 *
 * @param {object} item The authored item.
 * @return {string} The path's `d`, or '' for no icon.
 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-task-list-may-draw-bare-icons-and-a-phone-list
 */
export function iconPathOf(item) {
	const own = String(item?.iconPath ?? '').trim()
	if (own !== '' && PATH_DATA.test(own) && own.length <= 400) {
		return own
	}
	return Object.hasOwn(icons, item?.icon) ? icons[item.icon] : ''
}

/**
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-task-list-shows-a-portals-most-asked-tasks-as-tiles
 */
