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

/**
 * Whether a visitor can follow a destination: an in-site route or a web, mail
 * or phone address. The same rule the content API applies to footer links.
 *
 * @param {string} href The destination.
 * @return {boolean} True when it may be rendered as a link.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
 */
export function followable(href) {
	return /^(\/(?!\/)|https?:\/\/|mailto:|tel:)/i.test(String(href || ''))
}

/**
 * The hero's calls to action that render: each with a label and a followable
 * destination, the first two only.
 *
 * @param {Array} actions The authored actions.
 * @return {Array} `{label, href, style?, chevron?}` entries, two at most.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
 * @spec openspec/changes/site-home-follows-the-school-boards/specs/site-look/spec.md#requirement-a-hero-action-may-be-a-text-link-or-carry-a-chevron
 */
export function heroActions(actions) {
	return (Array.isArray(actions) ? actions : [])
		.filter(
			(action) =>
				action
				&& String(action.label || '').trim() !== ''
				&& followable(action.href),
		)
		.map((action) => ({
			label: String(action.label),
			href: String(action.href),
			// An underlined text link instead of a button, and a chevron after
			// a button's label (boards Home, site-home-follows-the-school-boards).
			...(action.style === 'link' ? { style: 'link' } : {}),
			...(action.chevron === true ? { chevron: true } : {}),
		}))
		.slice(0, 2)
}

/**
 * The hero's popular links that render ("Veel gezocht"): each with a label
 * and a followable destination, six at most.
 *
 * @param {Array} links The authored links.
 * @return {Array} `{label, href}` entries, six at most.
 *
 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-the-hero-may-draw-its-search-plain-with-popular-links
 */
export function heroPopularLinks(links) {
	return (Array.isArray(links) ? links : [])
		.filter(
			(link) =>
				link
				&& String(link.label || '').trim() !== ''
				&& followable(link.href),
		)
		.map((link) => ({ label: String(link.label), href: String(link.href) }))
		.slice(0, 6)
}

/**
 * A hero block's props with the portal's hero image as its background when
 * the block sets none of its own (portal-identity-from-the-admin REQ-PIA-002).
 *
 * @param {object} props The authored props, styling already removed.
 * @param {{url: string, alt: string}|null} portalHero The portal's hero image.
 * @return {object} The props to hand the block.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
 */
export function heroPropsOf(props, portalHero) {
	const own = String(props?.backgroundImage || '').trim()
	const url = String(portalHero?.url || '')
	if (own !== '' || url === '') {
		return props
	}

	return { ...props, backgroundImage: url }
}
