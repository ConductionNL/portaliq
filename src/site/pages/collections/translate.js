// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The translator the collection pages use: the site's own `t` first, then
// this slice's strings.js for a key the site's bundle does not know yet. Until
// the site's translator carries these keys, a Dutch resident still reads
// Dutch instead of the English source key.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014

import strings from './strings.js'

/**
 * Fill `{name}` placeholders.
 *
 * @param {string} text The text.
 * @param {object} [vars] The values.
 * @return {string}
 */
function fill(text, vars) {
	let out = String(text)
	for (const [name, value] of Object.entries(vars || {})) {
		out = out.split(`{${name}}`).join(String(value))
	}
	return out
}

/**
 * The language of the page: the one handed in, else the document's, else Dutch.
 *
 * @param {string} [locale] The locale handed in.
 * @return {string} `nl` or `en`, or the locale as given when it is another.
 */
export function pageLocale(locale) {
	const raw =
		locale
		|| (typeof document !== 'undefined' ? document.documentElement?.lang : '')
		|| 'nl'
	return String(raw).toLowerCase().split(/[-_]/)[0] || 'nl'
}

/**
 * A translator that falls back to this slice's strings.
 *
 * @param {((key: string, vars?: object) => string)|null} t The site's translator.
 * @param {string} locale The language.
 * @return {(key: string, vars?: object) => string}
 */
export function collectionsTranslator(t, locale) {
	const own = strings[locale] || strings.en
	return function translate(key, vars) {
		const site = typeof t === 'function' ? t(key) : key
		const text = site !== key || !Object.hasOwn(own, key) ? site : own[key]
		return fill(text, vars)
	}
}
