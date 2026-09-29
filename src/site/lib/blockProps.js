/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * What a block may be handed from page data, and what it may not.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-blocks-must-take-their-data-as-props-and-nothing-else-req-ptb-007
 */

/**
 * Keys that would let authored content style itself.
 *
 * Both are Vue fallthrough attributes: no block declares them as a prop, so
 * spreading authored data into a component hands them to its root element.
 * They reached nothing on the reference branch only because the blocks there
 * had fragment roots (a485fad), which stops holding the day a block grows a
 * single root.
 *
 * @type {Array<string>}
 */
export const STYLING_KEYS = ['style', 'class']

/**
 * A block's authored props with every styling key removed.
 *
 * @param {object} props The authored props.
 * @return {object} The props without `style` and `class`.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-blocks-must-take-their-data-as-props-and-nothing-else-req-ptb-007
 */
export function withoutStyling(props) {
	const safe = {}
	for (const [key, value] of Object.entries(props || {})) {
		if (STYLING_KEYS.includes(key) === false) {
			safe[key] = value
		}
	}

	return safe
}
