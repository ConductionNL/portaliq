// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The account pages (my cases, access to cases, my details, my account,
// contacts, theme, plans) take the shell's `t`. The shell's bundle does not
// carry every string these pages use, so each page is wrapped in a component
// that hands it a translator falling back to ./strings.js: a Dutch page never
// shows an English source string. Loaded with the page, never in the entry.
//
// @spec openspec/specs/site-portal-parity/spec.md#requirement-the-account-pages-speak-the-pages-language

import { h } from 'vue'
import { withBundles } from '../inbox/translate.js'
import strings from './strings.js'

/**
 * The page's translator: the shell's answer where it knows the key, this
 * folder's strings where it does not.
 *
 * @param {((key: string, vars?: object) => string)|null} t The shell's translator.
 * @param {string} [locale] The page language.
 * @return {(key: string, vars?: object) => string} The translator.
 * @spec openspec/specs/site-portal-parity/spec.md#requirement-the-account-pages-speak-the-pages-language
 */
export function accountPageTranslator(t, locale) {
	return withBundles(strings, t, locale)
}

/**
 * The page wrapped so its `t` falls back to this folder's strings. The
 * wrapper declares the page's own props, so the shell passes it what it
 * passed the page, and forwards every listener.
 *
 * @param {object} page The page component.
 * @return {object} The wrapping component.
 * @spec openspec/specs/site-portal-parity/spec.md#requirement-the-account-pages-speak-the-pages-language
 */
export function withAccountStrings(page) {
	const declared = Array.isArray(page.props)
		? page.props
		: Object.keys(page.props || {})
	const props = Array.isArray(page.props)
		? [...new Set([...page.props, 't', 'locale'])]
		: {
				...(page.props || {}),
				t: { type: Function, default: null },
				locale: { type: String, default: '' },
			}
	return {
		name: `${page.name || 'AccountPage'}Translated`,
		inheritAttrs: false,
		props,
		computed: {
			translator() {
				return accountPageTranslator(this.t, this.locale)
			},
		},
		render() {
			const forwarded = { ...this.$attrs }
			for (const name of declared) {
				if (this.$props[name] !== undefined) {
					forwarded[name] = this.$props[name]
				}
			}
			forwarded.t = this.translator
			return h(page, forwarded)
		},
	}
}
