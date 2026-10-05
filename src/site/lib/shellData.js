/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The shell blocks' data, derived from the portal's public record and menus.
 *
 * Pure functions, so the site renderer and any editor canvas derive the same
 * header and footer from the same record, and a test can pin each rule
 * without mounting anything.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
 */

/**
 * The header shapes a portal may choose. The first is the default.
 *
 * @type {Array<string>}
 */
export const HEADER_VARIANTS = ['double', 'single']

/**
 * The header variant to render: the portal's choice, or `double`.
 *
 * @param {object} site The public site record.
 * @return {string} `double` or `single`.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
 */
export function headerVariantOf(site) {
	const chosen = String((site && site.headerVariant) || '')
	return HEADER_VARIANTS.includes(chosen) ? chosen : HEADER_VARIANTS[0]
}

/**
 * The menus shown in the header: position 0.
 *
 * @param {Array} menus The portal's menus.
 * @return {Array} The header menus.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
 */
export function headerMenusOf(menus) {
	return (menus || []).filter((menu) => (menu.position || 0) === 0)
}

/**
 * The legal strip's menu: the highest position of 2 or more, or null.
 *
 * Position is a contract: 0 is the header, 1 a footer column, 2 or higher
 * the legal strip.
 *
 * @param {Array} menus The portal's menus.
 * @return {object|null} The legal strip's menu.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
 */
export function subFooterMenuOf(menus) {
	const strip = (menus || []).filter((menu) => (menu.position || 0) >= 2)
	if (strip.length === 0) {
		return null
	}

	return strip.reduce((highest, menu) =>
		(menu.position || 0) > (highest.position || 0) ? menu : highest,
	)
}

/**
 * The footer's link columns: every menu the header and the strip do not claim.
 *
 * @param {Array} menus The portal's menus.
 * @return {Array} The footer column menus.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
 */
export function footerMenusOf(menus) {
	const strip = subFooterMenuOf(menus)
	return (menus || []).filter(
		(menu) => (menu.position || 0) !== 0 && menu !== strip,
	)
}

/**
 * The footer's authored content, always in the same shape.
 *
 * The content API already dropped entries without a label or a destination;
 * this only guarantees the lists exist, so a portal that never set a footer
 * renders without a guard per list.
 *
 * @param {object} site The public site record.
 * @return {object} `{description, colophon, socials, legalLinks, badges, cta, contact}`;
 *   `cta` is `{label, href}` or null, `contact` is `{title, lines}` or null.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-footer-must-carry-the-motif-the-light-logo-and-the-brand-column-first
 */
export function footerContentOf(site) {
	const footer = (site && site.footer) || {}
	const list = (value) => (Array.isArray(value) ? value : [])

	return {
		description: String(footer.description || ''),
		colophon: String(footer.colophon || ''),
		socials: list(footer.socials),
		legalLinks: list(footer.legalLinks),
		badges: list(footer.badges),
		cta: footer.cta && footer.cta.label && footer.cta.href ? footer.cta : null,
		contact:
			footer.contact && list(footer.contact.lines).length > 0
				? {
						title: String(footer.contact.title || ''),
						lines: footer.contact.lines,
					}
				: null,
	}
}

/**
 * The legal strip's links: the portal's own, else the strip menu's items.
 *
 * A new field must not empty an existing footer, so the strip menu stays the
 * source until a portal names its legal links.
 *
 * @param {object} site  The public site record.
 * @param {Array}  menus The portal's menus.
 * @return {Array} `{label, href}` entries.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
 */
export function legalLinksOf(site, menus) {
	const authored = footerContentOf(site).legalLinks
	if (authored.length > 0) {
		return authored.map((item) => ({
			label: String(item.label || ''),
			href: String(item.href || ''),
		}))
	}

	const strip = subFooterMenuOf(menus)
	return ((strip && strip.items) || []).map((item) => ({
		label: String(item.name || ''),
		href: String(item.link || ''),
	}))
}

/**
 * Where to send a visitor without an account, or null.
 *
 * A destination the portal declares, never derived from the sign-in modes: a
 * register control that leads nowhere is worse than none.
 *
 * @param {object} site The public site record.
 * @return {object|null} `{href, label}` with an empty label meaning the block's default.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
 */
export function registerRouteOf(site) {
	const auth = (site && site.authentication) || {}
	const href = String(auth.register || '').trim()
	if (href === '') {
		return null
	}

	return { href, label: String(auth.registerLabel || '') }
}

/**
 * The header's search box: shown only when the portal switched it on, with
 * its hint and the page that shows the results (`/zoeken` unless the portal
 * names another page of its own).
 *
 * @param {object} site The public site record.
 * @return {{enabled: boolean, label: string, placeholder: string, route: string}} The box.
 *
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
 */
export function headerSearchOf(site) {
	const search = (site && site.headerSearch) || {}
	const route = String(search.route || '')
	return {
		enabled: search.enabled === true,
		label: String(search.label || ''),
		placeholder: String(search.placeholder || ''),
		route: /^\/(?!\/)/.test(route) ? route : '/zoeken',
	}
}
