// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The names of the 17 Woo information categories (Woo art. 3.3).
 *
 * A publication stores its category as the TOOI code (`infocat014`), and the
 * search facet answers with the same code. A visitor reads the name. The list
 * is the statutory one, the same in every municipality, and it is the list
 * opencatalogi files publications under (`OCA\OpenCatalogi\Service\WooCategory`),
 * so the site can name a code without asking the app.
 *
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 */

const CATEGORIES = {
	infocat001: [
		'Wetten en algemeen verbindende voorschriften',
		'Laws and generally binding regulations',
	],
	infocat002: [
		'Overige besluiten van algemene strekking',
		'Other decisions of general scope',
	],
	infocat003: [
		'Ontwerpen van wet- en regelgeving met adviesaanvraag',
		'Draft legislation sent out for advice',
	],
	infocat004: ['Organisatie en werkwijze', 'Organisation and working methods'],
	infocat005: ['Bereikbaarheidsgegevens', 'Contact details'],
	infocat006: [
		'Bij vertegenwoordigende organen ingekomen stukken',
		'Documents received by representative bodies',
	],
	infocat007: [
		'Vergaderstukken Staten-Generaal',
		'Meeting documents of the States General',
	],
	infocat008: [
		'Vergaderstukken decentrale overheden',
		'Meeting documents of local and regional governments',
	],
	infocat009: [
		"Agenda's en besluitenlijsten bestuurscolleges",
		'Agendas and decision lists of executive boards',
	],
	infocat010: ['Adviezen', 'Advice'],
	infocat011: ['Convenanten', 'Covenants'],
	infocat012: ['Jaarplannen en jaarverslagen', 'Annual plans and annual reports'],
	infocat013: [
		'Subsidieverplichtingen anders dan met beschikking',
		'Subsidy obligations other than by decision',
	],
	infocat014: ['Woo-verzoeken en -besluiten', 'Woo requests and decisions'],
	infocat015: ['Onderzoeksrapporten', 'Research reports'],
	infocat016: ['Beschikkingen', 'Individual decisions'],
	infocat017: ['Klachtoordelen', 'Complaint rulings'],
}

/**
 * The name of a Woo category in the site's language: English for `en`,
 * Dutch otherwise. A code outside the statutory list reads as itself.
 *
 * @param {string} code The category code, for example `infocat014`.
 * @param {string} locale The site's language.
 * @return {string} The name.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 */
export function wooCategoryLabel(code, locale) {
	const key = String(code ?? '')
	if (!Object.hasOwn(CATEGORIES, key)) {
		return key
	}
	return CATEGORIES[key][locale === 'en' ? 1 : 0]
}

/**
 * The search facet's buckets with a name on every Woo category. Buckets of
 * any other field keep the label the API gave them.
 *
 * @param {Array<{value: string, label: string, count: number}>} buckets The buckets.
 * @param {string} field The facet field.
 * @param {string} locale The site's language.
 * @return {Array<{value: string, label: string, count: number}>} The buckets.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 */
export function labelBuckets(buckets, field, locale) {
	if (field !== 'wooCategory') {
		return buckets
	}
	return buckets.map((bucket) => ({
		...bucket,
		label: wooCategoryLabel(bucket.value, locale),
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
