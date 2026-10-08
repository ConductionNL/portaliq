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

import { toBuckets } from './federatedSearch.js'
import { wooCategoryLabel } from './wooCategories.js'

/** The publications search the counts are read from. */
export const SEARCH_ENDPOINT = '/index.php/apps/opencatalogi/api/federation/publications'

/** The subjects routes. */
export const SUBJECTS_ENDPOINT = '/index.php/apps/opencatalogi/api/themes'

/** What a count can be per, and the facet field behind it. */
export const COUNT_FIELDS = { category: 'wooCategory', subject: 'themes', year: 'publicationDate' }

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
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#21
 */
export function imageOf(image, title = '') {
	const url = text(typeof image === 'object' && image !== null ? image.url ?? image.downloadUrl : image)
	if (url === '') {
		return null
	}
	const alt = typeof image === 'object' && image !== null ? text(image.alt ?? image.title) : ''
	return { url, alt: alt !== '' ? alt : title }
}

/**
 * One subject row as the page and the widgets show it; null for a row with no
 * title, which cannot be shown.
 *
 * @param {object} row One row of the answer.
 * @return {object|null} `{id, slug, title, summary, description, image, publicationCount, featuredOrder, featured}`.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#21
 */
export function subjectFrom(row) {
	if (!row || typeof row !== 'object') {
		return null
	}
	const title = text(row.title ?? row.name)
	if (title === '') {
		return null
	}
	const count = Number(row.publicationCount)
	return {
		id: String(row.id ?? row.uuid ?? ''),
		slug: /^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(text(row.slug)) ? text(row.slug) : '',
		title,
		summary: text(row.summary),
		description: text(row.description),
		image: imageOf(row.image, title),
		publicationCount: Number.isFinite(count) && String(row.publicationCount ?? '') !== '' ? count : null,
		featuredOrder: Number.isFinite(Number(row.featuredOrder)) && row.featuredOrder !== null && row.featuredOrder !== undefined ? Number(row.featuredOrder) : Infinity,
		featured: row.featured === true,
	}
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
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#31
 */
export function featuredFrom(body, count = 6) {
	const rows = Array.isArray(body?.results) ? body.results : []
	return rows
		.map(subjectFrom)
		.filter((subject) => subject !== null && subject.featured === true && subject.slug !== '')
		.sort((a, b) => (a.featuredOrder - b.featuredOrder) || a.title.localeCompare(b.title, 'nl'))
		.slice(0, Math.max(0, Number(count) || 0))
}

/**
 * The address of a subject's landing page.
 *
 * @param {string} slug The slug.
 * @param {string} route The route the page lives under.
 * @return {string} The address.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#31
 */
export function subjectHref(slug, route = '/onderwerp') {
	return `${route.replace(/\/$/, '')}/${encodeURIComponent(slug)}`
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
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#31
 */
export async function fetchFeatured({ count = 6, fetchImpl = null, endpoint = SUBJECTS_ENDPOINT } = {}) {
	const url = new URL(endpoint, globalThis.window?.location?.origin || 'http://localhost')
	url.searchParams.set('featured', 'true')
	const read = fetchImpl || ((...args) => globalThis.window.fetch(...args))
	try {
		const response = await read(url.toString(), { headers: { Accept: 'application/json' }, credentials: 'omit' })
		if (response.status === 404) {
			return { state: 'absent', subjects: [] }
		}
		if (!response.ok) {
			return { state: 'failed', subjects: [] }
		}
		return { state: 'ok', subjects: featuredFrom(await response.json(), count) }
	} catch {
		return { state: 'failed', subjects: [] }
	}
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
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#21
 */
export async function fetchSubject(slug, { fetchImpl = null, endpoint = SUBJECTS_ENDPOINT } = {}) {
	if (!/^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(String(slug || ''))) {
		return { state: 'notFound', subject: null }
	}
	const read = fetchImpl || ((...args) => globalThis.window.fetch(...args))
	try {
		const response = await read(`${endpoint}/${encodeURIComponent(slug)}`, { headers: { Accept: 'application/json' }, credentials: 'omit' })
		if (response.status === 404 || response.status === 403) {
			return { state: 'notFound', subject: null }
		}
		if (!response.ok) {
			return { state: 'failed', subject: null }
		}
		const subject = subjectFrom(await response.json())
		return subject === null ? { state: 'notFound', subject: null } : { state: 'ok', subject }
	} catch {
		return { state: 'failed', subject: null }
	}
}

/**
 * The one request behind a count widget: no results, only the facet.
 *
 * @param {string} by `category`, `subject` or `year`.
 * @param {string} [endpoint] The publications search.
 * @param {string} [origin] The origin to resolve a relative endpoint against.
 * @return {string} The request address.
 *
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#41
 */
export function countsUrl(by, endpoint = SEARCH_ENDPOINT, origin = 'http://localhost') {
	const field = COUNT_FIELDS[by] || COUNT_FIELDS.category
	const url = new URL(endpoint, origin)
	url.searchParams.set('_limit', '0')
	url.searchParams.set(`_facets[${field}][type]`, field === 'publicationDate' ? 'date_histogram' : 'terms')
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
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#41
 */
export function countsFrom(body, by, searchRoute = '/zoeken', locale = 'nl') {
	const field = COUNT_FIELDS[by] || COUNT_FIELDS.category
	return toBuckets(body?.facets, field)
		.filter((bucket) => Number.isFinite(bucket.count) && bucket.count > 0)
		.map((bucket) => {
			const value = field === 'publicationDate' ? bucket.value.slice(0, 4) : bucket.value
			const query = field === 'publicationDate'
				? `periodFrom=${value}-01-01&periodTo=${value}-12-31`
				: `f.${field}=${encodeURIComponent(value)}`
			return { value, label: labelOf(field, bucket, value, locale), count: bucket.count, href: `${searchRoute}?${query}` }
		})
		.sort((a, b) => (field === 'publicationDate' ? b.value.localeCompare(a.value) : b.count - a.count))
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
	if (field === 'wooCategory') {
		return wooCategoryLabel(bucket.value, locale)
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
 * @spec openspec/changes/home-and-theme-landing-pages/tasks.md#41
 */
export async function fetchCounts(by, { searchRoute = '/zoeken', fetchImpl = null, endpoint = SEARCH_ENDPOINT, locale = 'nl' } = {}) {
	const read = fetchImpl || ((...args) => globalThis.window.fetch(...args))
	try {
		const origin = globalThis.window?.location?.origin || 'http://localhost'
		const response = await read(countsUrl(by, endpoint, origin), { headers: { Accept: 'application/json' }, credentials: 'omit' })
		if (response.status === 404) {
			return { state: 'absent', counts: [] }
		}
		if (!response.ok) {
			return { state: 'failed', counts: [] }
		}
		return { state: 'ok', counts: countsFrom(await response.json(), by, searchRoute, locale) }
	} catch {
		return { state: 'failed', counts: [] }
	}
}
