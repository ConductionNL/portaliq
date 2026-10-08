/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The translator the inbox, messages, news and tasks screens use. The shell
 * hands every page a `t(key, vars)`. Where that translator knows a key, its
 * answer wins; where it does not (it answers the key back), this folder's own
 * strings.js answers, so a screen never shows an English key on a Dutch page.
 *
 * Imports only strings.js, so node can test it.
 */

import strings from './strings.js'

/**
 * Fill `{name}` placeholders.
 *
 * @param {string} text The text.
 * @param {Record<string, string|number>} [vars] The values.
 * @return {string} The filled text.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function interpolate(text, vars) {
	let out = String(text)
	for (const [name, value] of Object.entries(vars || {})) {
		out = out.split(`{${name}}`).join(String(value))
	}
	return out
}

/**
 * The page language: the given one, else the document's, else Dutch.
 *
 * @param {string} [locale] The locale the shell passed.
 * @return {string} `nl` or `en`.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function pageLocale(locale) {
	const raw =
		locale
		|| (typeof document !== 'undefined' && document.documentElement?.lang)
		|| 'nl'
	return String(raw).toLowerCase().startsWith('en') ? 'en' : 'nl'
}

/**
 * A translator over the shell's `t`, falling back to this folder's strings.
 *
 * @param {((key: string, vars?: object) => string)|null} t The shell's translator, or null.
 * @param {string} [locale] The page language.
 * @return {(key: string, vars?: object) => string} The translator.
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export function withStrings(t, locale) {
	const bundle = strings[pageLocale(locale)] || strings.nl
	return (key, vars) => {
		const own = interpolate(bundle[key] || key, vars)
		if (typeof t !== 'function') {
			return own
		}
		const shell = t(key, vars)
		if (
			typeof shell !== 'string'
			|| shell === ''
			|| shell === key
			|| shell === interpolate(key, vars)
		) {
			return own
		}
		return shell
	}
}
