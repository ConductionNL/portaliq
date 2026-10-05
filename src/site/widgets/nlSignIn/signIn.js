// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// What the sign-in widget offers, decided without Vue so node tests it
// (site-nlds-widget-palette design D1 row 55, site-school-blocks).
//
// THE WAYS IN ARE THE PORTAL'S, NOT THE PLACEMENT'S. The host hands down the
// sign-in routes the portal declares; a placement only words the card. A way
// whose address is not a path inside this site is refused: a sign-in link to
// another origin is the one link on a government page that must never be
// authorable.
//
// @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-the-sign-in-card-offers-the-portals-own-ways-in

/**
 * The ways in that name a route inside this site.
 *
 * @param {Array<object>} ways `{id, label, href}` from the host.
 * @return {Array<{id: string, label: string, href: string}>} The safe ways.
 */
export function safeWays(ways) {
	return (Array.isArray(ways) ? ways : [])
		.map((way) => ({
			id: String(way?.id ?? '').trim(),
			label: String(way?.label ?? '').trim(),
			href: String(way?.href ?? '').trim(),
		}))
		.filter(
			(way) =>
				way.id !== ''
				&& way.label !== ''
				&& way.href.startsWith('/')
				&& !way.href.startsWith('//'),
		)
}

/**
 * The card's one button.
 *
 * Signed in: to the resident's own area. One way in: straight to it, in its
 * own words unless the page words it. Several, or none known: to the sign-in
 * page, where the visitor chooses.
 *
 * @param {object} options What the card knows.
 * @param {Array<object>} options.ways The safe ways.
 * @param {boolean} options.signedIn Whether a session is held.
 * @param {string} options.heading The card's heading.
 * @param {string} options.buttonLabel The authored label.
 * @param {string} options.signInHref The sign-in page route.
 * @param {(key: string, vars?: object) => string} options.say The widget's words.
 * @return {{label: string, href: string, route: string, id: string}} The button.
 */
export function cardButton({
	ways,
	signedIn,
	heading,
	buttonLabel,
	signInHref,
	say,
}) {
	const page = /^\/(?!\/)/.test(signInHref || '') ? signInHref : '/mijn'
	if (signedIn) {
		return {
			id: 'own-area',
			label: heading ? say('goTo', { name: heading }) : say('ownArea'),
			href: '/mijn',
			route: '/mijn',
		}
	}
	if (ways.length === 1) {
		return {
			id: ways[0].id,
			label: buttonLabel || ways[0].label,
			href: ways[0].href,
			route: '',
		}
	}
	return {
		id: 'choose',
		label: buttonLabel || say('signIn'),
		href: page,
		route: page,
	}
}
