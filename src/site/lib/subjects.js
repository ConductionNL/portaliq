// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Subjects (themes) and live counts on the public site
 * (home-and-theme-landing-pages).
 *
 * The routes and the keys of their answers are opencatalogi's
 * (`subjects-as-first-class-records`, REQ-SUB-001 to REQ-SUB-003): a list
 * `{results: [{id, slug, title, summary, description, image, url, isExternal,
 * featuredOrder, publicationCount}], total}` and one subject by slug. Every
 * read is made as an anonymous visitor (`credentials: 'omit'`), so a
 * signed-in officer sees what the public sees. Pure helpers first, so a node
 * test runs them; the fetches take the fetch to use.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */

import { hasValueList, valueLabel } from './facetLabels.js'
import { toBuckets } from './federatedSearch.js'

/** The publications search the counts are read from. */
export const SEARCH_ENDPOINT =
	'/index.php/apps/opencatalogi/api/federation/publications'

/** The subjects routes. */
export const SUBJECTS_ENDPOINT = '/index.php/apps/opencatalogi/api/themes'

/** What a count can be per, and the facet field behind it. */
export const COUNT_FIELDS = {
	category: 'wooCategory',
	subject: 'themes',
	year: 'publicationDate',
}

/** A subject's slug: a letter or digit, then letters, digits, `_` and `-`. */
const SLUG = /^[A-Za-z0-9][A-Za-z0-9_-]*$/

/**
 * A text field of a subject row, trimmed; '' when it is not text.
 *
 * @param {*} value The value.
 * @return {string} The text.
 */
function text(value) {
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * A subject's image as `{url, alt}`; null without one. The image arrives as a
 * bare address or as an object carrying its alternative text.
 *
 * @param {*} image The row's `image`.
 * @param {string} title The subject's title, the alt text when none is given.
 * @return {{url: string, alt: string}|null} The image.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export function imageOf(image, title = '') {
	const url = imageUrl(image)
	if (url === '') {
		return null
	}
	const alt = imageAlt(image)
	return { url, alt: alt !== '' ? alt : title }
}

/**
 * Whether an image arrives as an object that describes it.
 *
 * @param {unknown} image The row's `image`.
 * @return {boolean}
 */
function isDescribed(image) {
	return typeof image === 'object' && image !== null
}

/**
 * An image's address: the bare address, or the object's `url` (else its
 * `downloadUrl`); '' without one.
 *
 * @param {unknown} image The row's `image`.
 * @return {string} The address.
 */
function imageUrl(image) {
	return text(isDescribed(image) ? (image.url ?? image.downloadUrl) : image)
}

/**
 * The alternative text an image object gives (its `alt`, else its
 * `title`); '' for a bare address.
 *
 * @param {unknown} image The row's `image`.
 * @return {string} The alternative text.
 */
function imageAlt(image) {
	return isDescribed(image) ? text(image.alt ?? image.title) : ''
}

/**
 * One subject row as the page and the widgets show it; null for a row with no
 * title, which cannot be shown.
 *
 * @param {object} row One row of the answer.
 * @return {object|null} `{id, slug, title, summary, description, image, publicationCount, featuredOrder, featured}`.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export function subjectFrom(row) {
	if (!row || typeof row !== 'object') {
		return null
	}
	const title = text(row.title ?? row.name)
	if (title === '') {
		return null
	}
	return shownSubject(row, title)
}

/**
 * The fields of a subject row with a title.
 *
 * @param {object} row One row of the answer.
 * @param {string} title Its title.
 * @return {object} `{id, slug, title, summary, description, image, publicationCount, featuredOrder, featured}`.
 */
function shownSubject(row, title) {
	return {
		id: String(row.id ?? row.uuid ?? ''),
		slug: SLUG.test(text(row.slug)) ? text(row.slug) : '',
		title,
		summary: text(row.summary),
		description: text(row.description),
		image: imageOf(row.image, title),
		publicationCount: publicationCountOf(row),
		featuredOrder: featuredOrderOf(row),
		featured: row.featured === true,
	}
}

/**
 * @param {object} row One row of the answer.
 * @return {number|null} The number of publications it holds, or null when it does not say.
 */
function publicationCountOf(row) {
	const count = Number(row.publicationCount)
	return Number.isFinite(count) && String(row.publicationCount ?? '') !== ''
		? count
		: null
}

/**
 * @param {object} row One row of the answer.
 * @return {number} Its place among the featured subjects, or Infinity when it has none.
 */
function featuredOrderOf(row) {
	return Number.isFinite(Number(row.featuredOrder))
		&& row.featuredOrder !== null
		&& row.featuredOrder !== undefined
		? Number(row.featuredOrder)
		: Infinity
}

/**
 * The subjects to feature: only rows whose `featured` is true, whatever the
 * server was asked, so an opencatalogi older than the `featured` filter shows
 * nothing rather than every subject; in `featuredOrder`, then title; at most
 * `count`; with a slug to link to.
 *
 * @param {object} body The list answer.
 * @param {number} count The most to show.
 * @return {Array<object>} The subjects.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export function featuredFrom(body, count = 6) {
	const rows = Array.isArray(body?.results) ? body.results : []
	return rows
		.map(subjectFrom)
		.filter(isFeatured)
		.sort(byFeaturedOrder)
		.slice(0, featuredLimit(count))
}

/**
 * Whether a subject is featured and has a slug to link to.
 *
 * @param {object|null} subject The subject.
 * @return {boolean}
 */
function isFeatured(subject) {
	return subject !== null && subject.featured === true && subject.slug !== ''
}

/**
 * The featured order: `featuredOrder`, then title.
 *
 * @param {object} a A subject.
 * @param {object} b Another subject.
 * @return {number} The order.
 */
function byFeaturedOrder(a, b) {
	return (
		a.featuredOrder - b.featuredOrder
		|| a.title.localeCompare(b.title, 'nl')
	)
}

/**
 * The most featured subjects to show: a count, none when it is not one.
 *
 * @param {unknown} count The most to show.
 * @return {number} The limit.
 */
function featuredLimit(count) {
	return Math.max(0, Number(count) || 0)
}

/**
 * The address of a subject's landing page.
 *
 * @param {string} slug The slug.
 * @param {string} route The route the page lives under.
 * @return {string} The address.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export function subjectHref(slug, route = '/onderwerp') {
	return `${route.replace(/\/$/, '')}/${encodeURIComponent(slug)}`
}

/**
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl The fetch to use, or null for the page's.
 * @return {(url: string, init?: object) => Promise<object>} The fetch.
 */
function readerOf(fetchImpl) {
	return fetchImpl || ((...args) => globalThis.window.fetch(...args))
}

/**
 * The request of an anonymous read: JSON, without the visitor's cookies.
 *
 * @return {object} The request options.
 */
function anonymousJson() {
	return { headers: { Accept: 'application/json' }, credentials: 'omit' }
}

/**
 * @return {string} The page's origin, or a stand-in outside a browser.
 */
function pageOrigin() {
	return globalThis.window?.location?.origin || 'http://localhost'
}

/**
 * How an answer reads: `ok`, the given state for a 404, or `failed`.
 *
 * @param {{status: number, ok: boolean}} response The answer.
 * @param {string} missing The state a 404 stands for.
 * @return {string} The state.
 */
function answerState(response, missing) {
	if (response.status === 404) {
		return missing
	}
	return response.ok ? 'ok' : 'failed'
}

/**
 * Read one anonymous JSON answer and take what the caller needs from it. A
 * failure anywhere, the address and the reading included, is `failed`.
 *
 * @param {(url: string, init?: object) => Promise<object>} read The fetch.
 * @param {{address: () => string, stateOf: (response: object) => string, take: (body: object) => unknown}} request Where to ask, how an answer reads and what to take from a good one.
 * @return {Promise<{state: string, value: unknown}>} The state, and the value taken when it is `ok`.
 */
async function readAnswer(read, request) {
	try {
		const response = await read(request.address(), anonymousJson())
		const state = request.stateOf(response)
		if (state !== 'ok') {
			return { state, value: null }
		}
		return { state, value: request.take(await response.json()) }
	} catch {
		return { state: 'failed', value: null }
	}
}

/**
 * Read the featured subjects.
 *
 * @param {object} [options] The options.
 * @param {number} [options.count] The most to show.
 * @param {((url: string, init?: object) => Promise<object>)|null} [options.fetchImpl] The fetch to use.
 * @param {string} [options.endpoint] The subjects route.
 * @return {Promise<{state: string, subjects: Array<object>}>} `state` is `ok`, `absent` (opencatalogi is not there) or `failed`.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export async function fetchFeatured({
	count = 6,
	fetchImpl = null,
	endpoint = SUBJECTS_ENDPOINT,
} = {}) {
	const url = new URL(endpoint, pageOrigin())
	url.searchParams.set('featured', 'true')
	const answer = await readAnswer(readerOf(fetchImpl), {
		address: () => url.toString(),
		stateOf: (response) => answerState(response, 'absent'),
		take: (body) => featuredFrom(body, count),
	})
	return { state: answer.state, subjects: answer.value || [] }
}

/**
 * Read one subject by its slug.
 *
 * @param {string} slug The slug from the address.
 * @param {object} [options] The options.
 * @param {((url: string, init?: object) => Promise<object>)|null} [options.fetchImpl] The fetch to use.
 * @param {string} [options.endpoint] The subjects route.
 * @return {Promise<{state: string, subject: object|null}>} `state` is `ok`, `notFound` (unknown, not public, or opencatalogi absent) or `failed`.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export async function fetchSubject(
	slug,
	{ fetchImpl = null, endpoint = SUBJECTS_ENDPOINT } = {},
) {
	if (!SLUG.test(String(slug || ''))) {
		return { state: 'notFound', subject: null }
	}
	const answer = await readAnswer(readerOf(fetchImpl), {
		address: () => `${endpoint}/${encodeURIComponent(slug)}`,
		stateOf: subjectState,
		take: subjectFrom,
	})
	return answer.state === 'ok'
		? subjectAnswer(answer.value)
		: { state: answer.state, subject: null }
}

/**
 * How a subject answer reads: one that is not there or not public is
 * `notFound`.
 *
 * @param {{status: number, ok: boolean}} response The answer.
 * @return {string} The state.
 */
function subjectState(response) {
	return response.status === 403 ? 'notFound' : answerState(response, 'notFound')
}

/**
 * @param {object|null} subject The subject read, or null for a row that cannot be shown.
 * @return {{state: string, subject: object|null}} The answer.
 */
function subjectAnswer(subject) {
	return subject === null
		? { state: 'notFound', subject: null }
		: { state: 'ok', subject }
}

/**
 * The one request behind a count widget: no results, only the facet.
 *
 * @param {string} by `category`, `subject` or `year`.
 * @param {string} [endpoint] The publications search.
 * @param {string} [origin] The origin to resolve a relative endpoint against.
 * @return {string} The request address.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export function countsUrl(
	by,
	endpoint = SEARCH_ENDPOINT,
	origin = 'http://localhost',
) {
	const field = COUNT_FIELDS[by] || COUNT_FIELDS.category
	const url = new URL(endpoint, origin)
	url.searchParams.set('_limit', '0')
	url.searchParams.set(
		`_facets[${field}][type]`,
		field === 'publicationDate' ? 'date_histogram' : 'terms',
	)
	if (field === 'publicationDate') {
		url.searchParams.set(`_facets[${field}][interval]`, 'year')
	}
	return url.toString()
}

/**
 * The counts of an answer, each with the address of the search that holds
 * them. A bucket the endpoint did not answer is not here: no count is shown as
 * zero because it is missing.
 *
 * @param {object} body The search answer.
 * @param {string} by `category`, `subject` or `year`.
 * @param {string} [searchRoute] The search page.
 * @param {string} [locale] The language category names are given in.
 * @return {Array<{value: string, label: string, count: number, href: string}>} The counts, most first.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export function countsFrom(body, by, searchRoute = '/zoeken', locale = 'nl') {
	const field = COUNT_FIELDS[by] || COUNT_FIELDS.category
	return toBuckets(body?.facets, field)
		.filter((bucket) => Number.isFinite(bucket.count) && bucket.count > 0)
		.map((bucket) => {
			const value =
				field === 'publicationDate' ? bucket.value.slice(0, 4) : bucket.value
			const query =
				field === 'publicationDate'
					? `periodFrom=${value}-01-01&periodTo=${value}-12-31`
					: `f.${field}=${encodeURIComponent(value)}`
			return {
				value,
				label: labelOf(field, bucket, value, locale),
				count: bucket.count,
				href: `${searchRoute}?${query}`,
			}
		})
		.sort((a, b) =>
			field === 'publicationDate'
				? b.value.localeCompare(a.value)
				: b.count - a.count,
		)
}

/**
 * The words on a count: a Woo category by its name, any other bucket by the
 * label the API gave it, a year as itself.
 *
 * @param {string} field The facet field.
 * @param {{value: string, label: string}} bucket The bucket.
 * @param {string} value The value shown for a year.
 * @param {string} locale The language.
 * @return {string} The label.
 */
function labelOf(field, bucket, value, locale) {
	if (hasValueList(field)) {
		return valueLabel(field, bucket.value, locale)
	}
	return field === 'publicationDate' || bucket.label === '' ? value : bucket.label
}

/**
 * Read the counts as an anonymous visitor would.
 *
 * @param {string} by `category`, `subject` or `year`.
 * @param {object} [options] The options.
 * @param {string} [options.searchRoute] The search page.
 * @param {((url: string, init?: object) => Promise<object>)|null} [options.fetchImpl] The fetch to use.
 * @param {string} [options.endpoint] The publications search.
 * @param {string} [options.locale] The language category names are given in.
 * @return {Promise<{state: string, counts: Array<object>}>} `state` is `ok`, `absent` or `failed`.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md
 */
export async function fetchCounts(
	by,
	{
		searchRoute = '/zoeken',
		fetchImpl = null,
		endpoint = SEARCH_ENDPOINT,
		locale = 'nl',
	} = {},
) {
	const answer = await readAnswer(readerOf(fetchImpl), {
		address: () => countsUrl(by, endpoint, pageOrigin()),
		stateOf: (response) => answerState(response, 'absent'),
		take: (body) => countsFrom(body, by, searchRoute, locale),
	})
	return { state: answer.state, counts: answer.value || [] }
}
