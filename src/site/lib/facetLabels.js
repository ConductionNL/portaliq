// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Words for the codes a search facet and a record page show.
 *
 * A record stores a code (`infocat014`, `publication`), and the facet answers
 * with the same code. A visitor reads the name. Which fields have a list of
 * names, and the names themselves, are configuration in
 * `src/site/config/valueLists.js`; nothing here knows one list from another.
 *
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 */

import { VALUE_LISTS } from '../config/valueLists.js'

/**
 * Whether a field has a configured list of names.
 *
 * @param {string} field The field's name in the stored record.
 * @return {boolean} True when the site can name the field's codes.
 */
export function hasValueList(field) {
	return Object.hasOwn(VALUE_LISTS, String(field ?? ''))
}

/**
 * The name of a code in the site's language. A language without a name reads
 * in Dutch. A field without a list, and a code outside its list, read as the
 * code itself, so a newly added code still shows something a visitor can
 * search for.
 *
 * @param {string} field The field's name in the stored record.
 * @param {string} code The stored code.
 * @param {string} locale The site's language.
 * @return {string} The name.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 */
export function valueLabel(field, code, locale) {
	const key = String(code ?? '')
	if (!hasValueList(field) || !Object.hasOwn(VALUE_LISTS[field], key)) {
		return key
	}
	const names = VALUE_LISTS[field][key]
	return Object.hasOwn(names, locale) ? names[locale] : names.nl
}

/**
 * The search facet's buckets with a name on every code of a field that has a
 * configured list, or that the host names (`names`). Buckets of any other
 * value keep the label the API gave them.
 *
 * @param {Array<{value: string, label: string, count: number}>} buckets The buckets.
 * @param {string} field The facet field.
 * @param {string} locale The site's language.
 * @param {Record<string, string>} [names] Names the host knows for this field's values (an organisation by its id).
 * @return {Array<{value: string, label: string, count: number}>} The buckets.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 * @spec openspec/changes/woo-search-names-the-organisation/specs/portal-federated-search/spec.md#requirement-the-organisation-filter-reads-the-organisations-name
 */
export function labelBuckets(buckets, field, locale, names = {}) {
	if (field === 'resultType') {
		return kindBuckets(buckets, locale)
	}
	const known = names && typeof names === 'object' ? names : {}
	if (!hasValueList(field) && Object.keys(known).length === 0) {
		return buckets
	}
	return buckets.map((bucket) => ({
		...bucket,
		label: Object.hasOwn(known, String(bucket.value))
			? String(known[bucket.value])
			: hasValueList(field)
				? valueLabel(field, bucket.value, locale)
				: bucket.label,
	}))
}

/**
 * The language of the page a block sits on: the document's, else Dutch.
 *
 * @return {string} A two-letter language code.
 */
export function pageLocale() {
	const lang =
		typeof document !== 'undefined' ? document.documentElement?.lang : ''
	return String(lang || 'nl')
		.slice(0, 2)
		.toLowerCase()
}

const KINDS = {
	publication: ['Publicatie', 'Publication'],
	document: ['Document', 'Document'],
	subject: ['Onderwerp', 'Subject'],
}

/**
 * The name of a kind of record in the site's language. A kind outside the
 * three reads as itself.
 *
 * @param {string} kind `publication`, `document` or `subject`.
 * @param {string} locale The site's language.
 * @return {string} The name.
 * @spec openspec/changes/search-filter-by-kind/specs/portal-federated-search/spec.md#requirement-results-filter-by-kind-req-sfk-001
 */
export function kindLabel(kind, locale) {
	const key = String(kind ?? '').toLowerCase()
	if (!Object.hasOwn(KINDS, key)) {
		return String(kind ?? '')
	}
	return KINDS[key][locale === 'en' ? 1 : 0]
}

/**
 * The `resultType` buckets in the order a visitor reads them (publication,
 * document, subject), each named in the site's language.
 *
 * @param {Array<{value: string, label: string, count: number}>} buckets The buckets.
 * @param {string} locale The site's language.
 * @return {Array<{value: string, label: string, count: number}>} The buckets.
 * @spec openspec/changes/search-filter-by-kind/specs/portal-federated-search/spec.md#requirement-results-filter-by-kind-req-sfk-001
 */
export function kindBuckets(buckets, locale) {
	const order = Object.keys(KINDS)
	const rank = (value) => {
		const index = order.indexOf(String(value).toLowerCase())
		return index === -1 ? order.length : index
	}
	return buckets
		.map((bucket) => ({ ...bucket, label: kindLabel(bucket.value, locale) }))
		.sort((a, b) => rank(a.value) - rank(b.value))
}
