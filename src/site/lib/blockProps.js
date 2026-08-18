/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * What a block may be handed from page data, and what it may not.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

/**
 * Keys that would let authored content style itself.
 *
 * Both are Vue FALLTHROUGH attributes: neither is a declared prop on any
 * block, so spreading authored data straight into a component hands them to
 * its root element.
 *
 * @type {Array<string>}
 */
const STYLING_KEYS = ['style', 'class']

/**
 * A block's authored props with every styling escape hatch removed.
 *
 * THE GRID IS THE ONLY LAYOUT PRIMITIVE (task 5.3), and it has to be enforced
 * rather than assumed. Without this, a page could absolutely-position a block
 * out of the grid, or paint it a colour no theme chose, from content alone —
 * and a portal's appearance would stop being something its theme decides.
 *
 * MEASURED BEFORE IT WAS WRITTEN: injecting `style` and `class` through a real
 * page's data did NOT reach the DOM, but only because the blocks involved have
 * fragment roots, where Vue drops fallthrough attributes with a dev-only
 * warning. That is an accident of those components' markup rather than a
 * guarantee, and it would stop holding the day a block grew a single root
 * element — silently, in production, where the warning does not print.
 *
 * @param {object} props The authored props.
 * @return {object} The props, without styling keys.
 */
export function withoutStyling(props) {
	const safe = {}
	for (const [key, value] of Object.entries(props || {})) {
		if (STYLING_KEYS.includes(key)) {
			continue
		}

		safe[key] = value
	}

	return safe
}

export { STYLING_KEYS }
