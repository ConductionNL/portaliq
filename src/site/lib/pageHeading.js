/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether a page's own blocks already carry its h1 (site-page-layout).
 *
 * The renderer prints the page title as the h1 unless the page states it
 * itself. A `hero` and a `publicationDetail` did; a page that opens with an
 * `nlHeading` at level 1 did too, and still got the renderer's title above
 * it: the proof of 06 Oct showed "Uw kind afwezig melden" twice.
 */

/**
 * Blocks that state the page's subject as its heading. A news article
 * prints its own title as the h1, so the page's "Nieuws" is not printed above
 * it (site-article-page-follows-the-board).
 */
const HEADING_BLOCKS = ['hero', 'publicationDetail', 'nlNewsArticle']

/**
 * The level an `nlHeading` renders at, as `NlHeading.vue` clamps it.
 *
 * @param {object} props The block's props.
 * @return {number} 1 to 6.
 */
function headingLevel(props) {
	const level = Math.round(Number(props?.level) || 2)
	return Math.min(Math.max(level, 1), 6)
}

/**
 * Whether one of these blocks is the page's h1.
 *
 * @param {Array<object>} widgets The blocks of the hero and main regions.
 * @return {boolean} True when the renderer must not add a title.
 * @spec openspec/changes/site-page-layout/specs/site-look/spec.md#requirement-a-page-must-have-one-title-heading
 */
export function blocksOwnHeading(widgets) {
	return (Array.isArray(widgets) ? widgets : []).some(
		(widget) =>
			HEADING_BLOCKS.includes(widget?.widgetKey)
			|| (widget?.widgetKey === 'nlHeading'
				&& headingLevel(widget.props) === 1),
	)
}
