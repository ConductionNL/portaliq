/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The language a visitor chose, and the links that let them choose one.
 *
 * The portal's own `locales` are the only languages offered: the language
 * switch is handed them by the shell, never by a placement. The choice
 * travels on the address as `?lang=`, so a shared link opens in the same
 * language, and every content request sends it as `locale`. The content API
 * checks it against the portal's set and falls back to the first entry.
 *
 * @spec openspec/changes/language-switch-reaches-the-content/specs/portaliq-cms/spec.md#requirement-the-language-switch-offers-the-portals-locales-and-the-choice-reaches-the-content
 */

/**
 * The address parameter that carries the chosen language.
 *
 * @type {string}
 */
export const LANGUAGE_PARAM = 'lang'

/**
 * The language the address asks for, or '' when it asks for none.
 *
 * @param {string} search The address's query string, such as `?lang=en`.
 * @return {string} A lower-case language tag, or ''.
 *
 * @spec openspec/changes/language-switch-reaches-the-content/specs/portaliq-cms/spec.md#requirement-the-language-switch-offers-the-portals-locales-and-the-choice-reaches-the-content
 */
export function requestedLocale(search) {
	const value = new URLSearchParams(search || '').get(LANGUAGE_PARAM) || ''
	return /^[a-z]{2,3}(-[a-z0-9]{2,8})*$/i.test(value.trim())
		? value.trim().toLowerCase()
		: ''
}

/**
 * A language's own name for itself, such as "Nederlands" or "English".
 *
 * @param {string} locale A language tag.
 * @return {string} The name, or the tag when the runtime does not know it.
 *
 * @spec openspec/changes/language-switch-reaches-the-content/specs/portaliq-cms/spec.md#requirement-the-language-switch-offers-the-portals-locales-and-the-choice-reaches-the-content
 */
export function languageName(locale) {
	try {
		const name = new Intl.DisplayNames([locale], { type: 'language' }).of(locale)
		if (!name || name === locale) {
			return locale
		}

		return name.charAt(0).toLocaleUpperCase(locale) + name.slice(1)
	} catch {
		return locale
	}
}

/**
 * The same address in another language.
 *
 * @param {string} href   The address of the page on screen.
 * @param {string} locale The language to switch to.
 * @return {string} The address with `?lang=` set.
 *
 * @spec openspec/changes/language-switch-reaches-the-content/specs/portaliq-cms/spec.md#requirement-the-language-switch-offers-the-portals-locales-and-the-choice-reaches-the-content
 */
export function hrefForLocale(href, locale) {
	const url = new URL(href)
	url.searchParams.set(LANGUAGE_PARAM, locale)
	url.hash = ''
	return url.toString()
}

/**
 * The language switch's entries: one per portal locale, in the portal's order.
 *
 * @param {Array<string>} locales The portal's `locales`.
 * @param {string}        href    The address of the page on screen.
 * @return {Array<{locale: string, label: string, href: string}>} The entries.
 *
 * @spec openspec/changes/language-switch-reaches-the-content/specs/portaliq-cms/spec.md#requirement-the-language-switch-offers-the-portals-locales-and-the-choice-reaches-the-content
 */
export function languageEntries(locales, href) {
	const seen = new Set()
	const entries = []
	for (const raw of Array.isArray(locales) ? locales : []) {
		const locale = typeof raw === 'string' ? raw.trim() : ''
		if (locale === '' || seen.has(locale) || !href) {
			continue
		}

		seen.add(locale)
		entries.push({
			locale,
			label: languageName(locale),
			href: hrefForLocale(href, locale),
		})
	}

	return entries
}
