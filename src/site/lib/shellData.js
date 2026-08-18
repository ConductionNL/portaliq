/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Deriving the shell blocks' data from a portal's public record.
 *
 * ONE IMPLEMENTATION, TWO RENDERERS. The public site derives this, and so does
 * the editor's canvas — and the canvas has to produce byte-identical markup or
 * the author is tuning something that is not what ships.
 *
 * It was two implementations for exactly one afternoon, and the DOM parity test
 * (task 4.7) found three differences in that time: the canvas showed no sign-in
 * controls, gave the footer the header's menus, and omitted the page-title
 * `h1`. None of them were visible by looking; all three were obvious the moment
 * the two DOMs were compared.
 *
 * Pure functions over the record, so both callers get the same answer and
 * neither can drift from the other by editing its own copy.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

/**
 * The menus shown in the header bar.
 *
 * Placement comes from `position`, which is what that field is for: position 0
 * is the header, everything else is a footer column. Read off the existing
 * contract rather than added to it.
 *
 * @param {Array} menus The portal's menus.
 * @return {Array} The header menus.
 */
export function headerMenusOf(menus) {
	return (menus || []).filter((menu) => (menu.position || 0) === 0)
}

/**
 * The legal strip's menu at the very bottom, if the portal declares one.
 *
 * CONVENTION, read off `position`: the HIGHEST position is the sub-footer. A
 * portal with only one footer menu has no sub-footer — that menu is a column.
 *
 * @param {Array} menus The portal's menus.
 * @return {object|null} The sub-footer menu.
 */
export function subFooterMenuOf(menus) {
	const footers = (menus || []).filter((menu) => (menu.position || 0) !== 0)
	if (footers.length < 2) {
		return null
	}

	return footers.reduce((highest, menu) =>
		(menu.position || 0) > (highest.position || 0) ? menu : highest,
	)
}

/**
 * The footer's link columns.
 *
 * @param {Array} menus The portal's menus.
 * @return {Array} The footer menus.
 */
export function footerMenusOf(menus) {
	const sub = subFooterMenuOf(menus)
	return (menus || []).filter((menu) => (menu.position || 0) !== 0 && menu !== sub)
}

/**
 * The footer's authored content, always the same SHAPE.
 *
 * The contract's `footer` object is optional and every list inside it is
 * optional, so a template would otherwise guard each one separately — and the
 * first guard anybody forgets is the one that throws on a portal that has never
 * set a footer.
 *
 * @param {object} site The portal record.
 * @return {object} `{description, colophon, decoration, socials, badges}`.
 */
export function footerContentOf(site) {
	const footer = (site && site.footer) || {}
	const list = (value) => (Array.isArray(value) ? value : [])

	return {
		description: String(footer.description || ''),
		colophon: String(footer.colophon || ''),
		decoration: String(footer.decoration || ''),
		socials: list(footer.socials),
		badges: list(footer.badges),
	}
}

/**
 * The legal links, from the portal's footer or from its last menu.
 *
 * PREFERS THE PORTAL RECORD but falls back to the sub-footer menu, because that
 * menu is where every portal's legal links live today. A new field that
 * silently empties an existing footer is a regression dressed as a feature.
 *
 * @param {object} site  The portal record.
 * @param {Array}  menus The portal's menus.
 * @return {Array} `{label, href}` entries.
 */
export function legalLinksOf(site, menus) {
	const authored = (site && site.footer && site.footer.legalLinks) || []
	if (Array.isArray(authored) && authored.length) {
		return authored.map((item) => ({
			label: String(item.label || ''),
			href: String(item.href || ''),
		}))
	}

	const menu = subFooterMenuOf(menus)
	if (!menu) {
		return []
	}

	return (menu.items || []).map((item) => ({
		label: String(item.name || ''),
		href: String(item.link || ''),
	}))
}

/**
 * Where to send somebody without an account, or null.
 *
 * A DESTINATION THE PORTAL DECLARES, never derived from the sign-in modes:
 * `nextcloud` accounts are created by an administrator and DigiD accounts by
 * the state, so there is nothing to infer. A "Registreren" button that leads
 * nowhere is worse than no button.
 *
 * @param {object} site The portal record.
 * @return {object|null} `{href, label}`.
 */
export function registerRouteOf(site) {
	const auth = (site && site.authentication) || {}
	const href = String(auth.register || '').trim()
	if (href === '') {
		return null
	}

	return { href, label: String(auth.registerLabel || 'Registreren') }
}

/**
 * The portal's initial, for the fallback logo mark.
 *
 * One character, uppercased, and empty when there is no title yet — a mark
 * showing a stray letter before the record loads is worse than an empty slot.
 *
 * @param {string} title The portal title.
 * @return {string} The initial.
 */
export function logoInitialOf(title) {
	return String(title || '').trim().charAt(0).toUpperCase()
}
