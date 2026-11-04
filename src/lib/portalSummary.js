// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// What the side summary cards of a portal's page show (PortalSummaryCard),
// as plain data: the board PtMededelingenActies draws the domain card as a
// list of terms, the theme as a swatch with its name, the status as a pill
// with the lines under it, and the administrators as people with their role.

import { certificateEnd, initials } from './portalDomains.js'

const SHORT_DATE = { day: 'numeric', month: 'short', year: 'numeric' }

/**
 * A date in the reader's language ("14 jan 2027").
 *
 * @param {Date} date The date.
 * @param {string} locale BCP 47 tag.
 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
 * @return {string} The text.
 */
function shortDate(date, locale) {
	return date.toLocaleDateString(locale, SHORT_DATE).replace('.', '')
}

/**
 * A language code in words in the reader's language: "nl" reads "Nederlands".
 *
 * @param {string} code The language code.
 * @param {string} locale BCP 47 tag of the reader.
 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
 * @return {string} The name, or the code when the engine cannot name it.
 */
export function languageName(code, locale) {
	try {
		const name = new Intl.DisplayNames([locale], { type: 'language' }).of(code)
		return name ? name.charAt(0).toLocaleUpperCase(locale) + name.slice(1) : code
	} catch {
		return code
	}
}

/**
 * The domain card's terms: each domain (the first in bold), the certificate
 * of the verified domain, and the portal's languages in words.
 *
 * @param {object} portal The portal.
 * @param {(key: string, vars?: object) => string} t The translate function.
 * @param {string} locale BCP 47 tag of the reader.
 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
 * @return {Array<{label: string, value: string, strong: boolean}>} The terms.
 */
export function domainTerms(portal, t, locale) {
	const domains = (Array.isArray(portal.domains) ? portal.domains : [])
		.map((d) => (typeof d === 'string' ? { hostname: d } : d))
		.filter((d) => d?.hostname)
	const out = domains.map((d, i) => ({ label: t('Domain'), value: d.hostname, strong: i === 0 }))
	const first = domains.find((d) => d.verified === true) || domains[0]
	const end = first ? certificateEnd(first, (d) => shortDate(d, locale)) : null
	if (end) {
		out.push({ label: t('Certificate'), value: t('valid until {date}', { date: end }), strong: false })
	}
	const locales = Array.isArray(portal.locales) ? portal.locales : []
	if (locales.length > 0) {
		out.push({ label: t('Language'), value: locales.map((code) => languageName(code, locale)).join(', '), strong: false })
	}
	return out
}

/**
 * The status card: the state as a pill and the line naming the last change.
 *
 * @param {object} portal The portal.
 * @param {(key: string, vars?: object) => string} t The translate function.
 * @param {string} locale BCP 47 tag of the reader.
 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
 * @return {{pill: ({label: string, tone: string}|null), lines: string[]}} The card.
 */
export function statusSummary(portal, t, locale) {
	const pill = portal.status
		? { label: portal.status === 'published' ? t('Published') : t('Draft'), tone: portal.status === 'published' ? 'success' : 'warning' }
		: null
	const updated = portal['@self']?.updated
	const lines = updated ? [t('Last changed {date}', { date: shortDate(new Date(updated), locale) })] : []
	return { pill, lines }
}

/**
 * The administrators as people: initials, name and role in words.
 *
 * @param {object} portal The portal.
 * @param {(key: string) => string} t The translate function.
 * @spec openspec/changes/portal-notices-as-drawn/specs/portaliq-cms/spec.md#requirement-a-portals-side-cards-read-as-drawn
 * @return {Array<{key: string, initials: string, name: string, role: string}>} The people.
 */
export function editorPeople(portal, t) {
	return (Array.isArray(portal.editors) ? portal.editors : [])
		.filter((e) => e?.userId)
		.map((e) => {
			const name = e.displayName || e.userId
			return { key: e.userId, initials: initials(name), name, role: e.role === 'chief-editor' ? t('Chief editor') : t('Editor') }
		})
}
