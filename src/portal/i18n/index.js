// SPDX-License-Identifier: EUPL-1.2
//
// Minimal, framework-agnostic i18n for the PUBLIC portal SPA
// (portal-spa-i18n-locale-support). `@nextcloud/l10n`'s `loadTranslations()`
// targets Nextcloud's own asset pipeline (bundle discovery via the app's
// `l10n/` directory + `OC.getLanguage()`), which this standalone,
// separately-built bundle (webpack.portal.js) does not go through — so this
// app ships its own tiny JSON-bundle loader instead, using the SAME
// English-source-key convention the rest of the company's frontends follow
// (hydra ADR-004): every literal below is an English string, translations
// live in `nl.json`.

import en from './en.json'
import nl from './nl.json'

const BUNDLES = { en, nl }

/**
 * Resolve the translation bundle for a locale, falling back to English for
 * an unknown/unsupported locale — never a runtime error, never a blank UI.
 *
 * @param {string} locale The resolved locale (e.g. 'nl', 'en').
 * @return {Record<string, string>}
 */
function bundleFor(locale) {
	return BUNDLES[locale] || BUNDLES.en
}

/**
 * Build a `t(key, vars?)` translator bound to one locale. `key` is the
 * English source string (also the bundle lookup key); an unknown key falls
 * back to itself (never a blank/undefined string in the UI). `vars` does
 * simple `{placeholder}` interpolation for the two strings that need it
 * (`Logged in as {subjectRef}`, `{field}?`).
 *
 * @param {string} locale The resolved locale.
 * @return {(key: string, vars?: Record<string, string|number>) => string}
 */
export function createTranslator(locale) {
	const bundle = bundleFor(locale)

	return function t(key, vars) {
		let text = bundle[key] || key
		if (vars) {
			for (const [name, value] of Object.entries(vars)) {
				text = text.replace(`{${name}}`, String(value))
			}
		}

		return text
	}
}
