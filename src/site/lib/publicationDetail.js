// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Turning one publication object into the rows the detail page renders.
 *
 * Extracted from the component so a plain node script can assert it. A
 * visitor reads the title, the summary, the date, the category and theme
 * names, and the documents (resident-sees-words-not-codes); the archive's own
 * bookkeeping stays off the page.
 *
 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
 */

import { valueLabel } from './facetLabels.js'
import { formatDutchDate } from './federatedSearch.js'

/**
 * File types the browser's own viewer shows: PDF and raster images. SVG is
 * left out on purpose, it can carry script.
 */
const VIEWED_IN_BROWSER = ['PDF', 'PNG', 'JPG', 'JPEG', 'GIF', 'WEBP']

/**
 * Data file types nextcloud-vue's public file preview shows as a table or text.
 */
const PREVIEWED_DATA = ['CSV', 'TSV', 'JSON', 'XML']

/**
 * The comment period states opencatalogi reports (REQ-PCP-004), each with its line.
 */
const PERIOD_LINES = {
	upcoming: { text: 'You can react from {date}', date: 'startDate' },
	open: { text: 'You can react until {date}', date: 'endDate' },
	closed: { text: 'The reaction period ended on {date}', date: 'endDate' },
}

/**
 * Whether a value counts as empty for display.
 *
 * @param {unknown} value The value.
 * @return {boolean} True for null, '', an empty list or an empty object.
 */
function isEmpty(value) {
	if (value === null || value === undefined || value === '') {
		return true
	}

	if (Array.isArray(value) === true) {
		return value.length === 0
	}

	return typeof value === 'object' && Object.keys(value).length === 0
}

/**
 * A date the way the site's reader writes it, or '' when unreadable.
 *
 * @param {string} value An ISO-8601 date or moment.
 * @param {string} locale The site's language.
 * @return {string} For example `2 september 2026`, as the site writes dates elsewhere.
 */
function readableDate(value, locale) {
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return ''
	}

	try {
		return date.toLocaleDateString(locale === 'en' ? 'en-GB' : 'nl-NL', {
			day: 'numeric',
			month: 'long',
			year: 'numeric',
			timeZone: 'Europe/Amsterdam',
		})
	} catch {
		return ''
	}
}

/**
 * The summary a visitor reads under the title: `summary`, else `description`.
 *
 * @param {object} publication One publication object.
 * @return {string} The summary, or ''.
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 */
export function publicationSummary(publication) {
	const row = publication || {}
	for (const key of ['summary', 'description']) {
		if (typeof row[key] === 'string' && row[key].trim() !== '') {
			return row[key].trim()
		}
	}

	return ''
}

/**
 * The ids of a publication's themes, to look their names up.
 *
 * @param {object} publication One publication object.
 * @return {Array<string>} The theme ids.
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 */
export function themeIdsOf(publication) {
	const themes = (publication || {}).themes
	if (!Array.isArray(themes)) {
		return []
	}

	return themes
		.map((theme) => (theme && typeof theme === 'object' ? theme.id : theme))
		.filter((id) => typeof id === 'string' && id !== '')
}

/**
 * The rows a visitor reads below the summary: the publication date, the
 * information category by name and the themes by name. Nothing else: the
 * Plooi and retention bookkeeping, the organisation id, the status and the
 * kind are the archive's business, not the visitor's. A row without a value
 * is left out, and so is a theme whose name is unknown.
 *
 * @param {object} publication One publication object.
 * @param {object} context The reader.
 * @param {(key: string) => string} context.t The translator.
 * @param {string} context.locale The site's language.
 * @param {Record<string, string>} [context.themeNames] Theme id to name.
 * @return {Array<{name: string, label: string, value: string|Array<string>, kind: string}>} The rows.
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 */
export function visitorRows(publication, { t, locale, themeNames = {} }) {
	if (!publication || typeof publication !== 'object') {
		return []
	}

	const reader = { t, locale, themeNames }
	return [dateRow, categoryRow, themesRow]
		.map((row) => row(publication, reader))
		.filter((row) => row !== null)
}

/**
 * @param {object} publication One publication object.
 * @param {{t: (key: string) => string, locale: string}} reader The translator and the site's language.
 * @return {object|null} The publication date's row, or null without a readable date.
 */
function dateRow(publication, reader) {
	const date = isEmpty(publication.publicationDate)
		? ''
		: readableDate(publication.publicationDate, reader.locale)
	if (date === '') {
		return null
	}
	return {
		name: 'publicationDate',
		label: reader.t('Publication date'),
		value: date,
		kind: 'text',
	}
}

/**
 * @param {object} publication One publication object.
 * @param {{t: (key: string) => string, locale: string}} reader The translator and the site's language.
 * @return {object|null} The information category's row, or null without one.
 */
function categoryRow(publication, reader) {
	if (isEmpty(publication.wooCategory)) {
		return null
	}
	return {
		name: 'wooCategory',
		label: reader.t('Information category'),
		value: valueLabel('wooCategory', publication.wooCategory, reader.locale),
		kind: 'text',
	}
}

/**
 * @param {object} publication One publication object.
 * @param {{t: (key: string) => string, themeNames: Record<string, string>}} reader The translator and the theme names.
 * @return {object|null} The themes' row with the known names, or null without one.
 */
function themesRow(publication, reader) {
	const themes = themeIdsOf(publication)
		.map((id) => reader.themeNames[id])
		.filter((name) => typeof name === 'string' && name.trim() !== '')
	if (themes.length === 0) {
		return null
	}
	return { name: 'themes', label: reader.t('Themes'), value: themes, kind: 'list' }
}

/**
 * A link a visitor can follow: http(s), or a path on this instance.
 *
 * A protocol-relative `//host` is refused with the rest: it leaves the
 * instance while looking like a path.
 *
 * @param {string} value The candidate link.
 * @return {string} The link, or '' when it is not safe to render.
 */
function safeLink(value) {
	const text = typeof value === 'string' ? value.trim() : ''
	if (/^https?:\/\//i.test(text) === true) {
		return text
	}

	return /^\/(?!\/)/.test(text) === true ? text : ''
}

/**
 * The path of a safe link: its own text when it is a path, the URL's path
 * when it is absolute, '' when that URL cannot be read.
 *
 * @param {string} link A link `safeLink` accepted.
 * @return {string} The path.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-a-document-opens-in-the-browser-req-pdc-001
 */
function linkPath(link) {
	if (/^https?:\/\//i.test(link) === false) {
		return link
	}
	try {
		return new URL(link).pathname
	} catch {
		return ''
	}
}

/**
 * A link to one of opencatalogi's public files: a Nextcloud public share
 * (`/s/<token>`) or opencatalogi's own api, on this instance or a federated
 * one. Anything else, a foreign host's file or another app's path, is not
 * opened from the page.
 *
 * @param {string} value The candidate link.
 * @return {string} The link, or ''.
 *
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-a-document-opens-in-the-browser-req-pdc-001
 */
function publicFileLink(value) {
	const link = safeLink(value)
	if (link === '') {
		return ''
	}

	return /^(\/index\.php)?\/(s\/[A-Za-z0-9]+(\/|$)|apps\/opencatalogi\/api\/)/.test(
		linkPath(link),
	) === true
		? link
		: ''
}

/**
 * Where a document opens in the browser, or '' when it only downloads.
 *
 * PDF and images open in the browser's own viewer. A data file opens only
 * when the site has a public preview for it; until then it stays a download.
 *
 * @param {object} file One file from the envelope.
 * @param {string} type Its type in capitals.
 * @param {boolean} preview Whether a public data-file preview is available.
 * @return {string} The link to open, or ''.
 *
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-a-document-opens-in-the-browser-req-pdc-001
 */
function viewLink(file, type, preview) {
	const viewable =
		VIEWED_IN_BROWSER.includes(type) === true
		|| (preview === true && PREVIEWED_DATA.includes(type) === true)
	if (viewable === false) {
		return ''
	}

	return publicFileLink(file.accessUrl)
}

/**
 * Whether a value is a non-empty id.
 *
 * @param {unknown} id The candidate.
 * @return {boolean} True for a non-empty string.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-the-publication-downloads-as-one-archive-req-pdc-003
 */
function isId(id) {
	return typeof id === 'string' && id !== ''
}

/**
 * Whether a number counts at least one document.
 *
 * @param {number} total The count.
 * @return {boolean} True for a whole number of 1 or more.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-the-publication-downloads-as-one-archive-req-pdc-003
 */
function isDocumentCount(total) {
	return Number.isInteger(total) === true && total >= 1
}

/**
 * The base the ZIP route sits under: the catalogue's route with a valid slug,
 * else the publication read's own base; '' when neither is usable.
 *
 * @param {string} [slug] The catalogue's slug, empty without one.
 * @param {string} [endpoint] The publication read's base.
 * @return {string} The base, without a trailing slash.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-the-publication-downloads-as-one-archive-req-pdc-003
 */
function downloadBase(slug, endpoint) {
	const name = String(slug || '')
	if (name === '') {
		return safeLink(String(endpoint || '')).replace(/\/+$/, '')
	}
	return /^[A-Za-z0-9-]+$/.test(name) === true
		? `/index.php/apps/opencatalogi/api/${name}`
		: ''
}

/**
 * The "download everything" link of a publication, or null without documents.
 *
 * With the catalogue's slug it is opencatalogi's catalogue ZIP route;
 * without one, the ZIP route beside the read the page used.
 *
 * @param {object} publication The publication.
 * @param {string} publication.id Its id.
 * @param {number} publication.count The number of documents.
 * @param {string} [publication.catalogSlug] The catalogue's slug.
 * @param {string} [publication.endpoint] The publication read's base.
 * @return {{href: string, count: number}|null} The link and the count.
 *
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-the-publication-downloads-as-one-archive-req-pdc-003
 */
export function downloadAllLink({ id, count, catalogSlug, endpoint }) {
	const total = Number(count)
	const base = isDocumentCount(total) === true ? downloadBase(catalogSlug, endpoint) : ''
	if (isId(id) === false || base === '') {
		return null
	}

	return { href: `${base}/${encodeURIComponent(id)}/download`, count: total }
}

/**
 * The line a reaction period's reported state reads as, or null for no
 * period or a state the portal does not know.
 *
 * @param {unknown} period The publication's `commentPeriod`.
 * @return {{state: string, text: string, date: string}|null} The state, the line and its date field.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-the-page-states-the-reaction-period-req-pdc-005
 */
function periodWords(period) {
	if (!period || typeof period !== 'object') {
		return null
	}
	const state = String(period.state || '')
	return Object.hasOwn(PERIOD_LINES, state) ? { state, ...PERIOD_LINES[state] } : null
}

/**
 * The reaction period line of a publication, from the state opencatalogi
 * reports, or null when it reports none. The portal never computes a state
 * itself: a period without a known state, or whose date cannot be written,
 * shows nothing. The form link is offered only while the period is open.
 *
 * @param {object|null} publication The publication read.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {{state: string, text: string, formUrl: string}|null} The line.
 *
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-the-page-states-the-reaction-period-req-pdc-005
 */
export function commentPeriodLine(publication, t) {
	const period = (publication || {}).commentPeriod
	const line = periodWords(period)
	const date = line === null ? '' : formatDutchDate(period[line.date])
	if (date === '') {
		return null
	}

	return {
		state: line.state,
		text: t(line.text, { date }),
		formUrl: line.state === 'open' ? safeLink(period.reactionFormUrl) : '',
	}
}

/**
 * A file size the way a Dutch reader writes it: `120 kB`, `2,4 MB`.
 *
 * @param {number} bytes The size in bytes.
 * @return {string} The size, or '' when unknown.
 *
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-list-and-offer-every-document-for-download-req-wsd-005
 */
export function humanSize(bytes) {
	if (bytes === null || bytes === undefined || bytes === '') {
		return ''
	}

	const size = Number(bytes)
	if (Number.isFinite(size) === false || size < 0) {
		return ''
	}

	return sizeWords(size)
}

/**
 * @param {number} size A size in bytes, zero or more.
 * @return {string} The size in bytes, kilobytes or megabytes.
 */
function sizeWords(size) {
	if (size < 1000) {
		return `${Math.round(size)} B`
	}

	if (size < 1000000) {
		return `${Math.round(size / 1000)} kB`
	}

	return `${(size / 1000000).toFixed(1).replace('.', ',')} MB`
}

/**
 * The file type a visitor recognises: the extension in capitals.
 *
 * @param {object} file One file from the envelope.
 * @return {string} For example `PDF`, or ''.
 */
function fileType(file) {
	const extension = String(file.extension || '').trim()
	if (extension !== '') {
		return extension.toUpperCase()
	}

	const match = String(file.title || file.name || '').match(
		/\.([A-Za-z0-9]{1,8})$/,
	)
	return match ? match[1].toUpperCase() : ''
}

/**
 * The rows of an attachment list's answer: a bare array or `{results}`.
 *
 * @param {object|Array|null} attachments The attachment list's answer.
 * @return {Array} The rows, empty for anything else.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export function attachmentRows(attachments) {
	if (Array.isArray(attachments) === true) {
		return attachments
	}
	if (attachments && Array.isArray(attachments.results) === true) {
		return attachments.results
	}
	return []
}

/**
 * The document id of one attachment row.
 *
 * @param {object} file An attachment row.
 * @return {string} The stable `documentId`, else the file id, else ''.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export function attachmentId(file) {
	return String(file.documentId ?? file.id ?? '')
}

/**
 * One document as the page shows it.
 *
 * @param {object} file An attachment row.
 * @param {boolean} preview Whether a public data-file preview is available.
 * @return {object} `{id, title, type, size, href, view, metadataUrl, diwooUrl}`.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-a-document-opens-in-the-browser-req-pdc-001
 */
function documentRow(file, preview) {
	const type = fileType(file)
	return {
		id: attachmentId(file),
		title: String(file.title || file.name || 'Document'),
		type,
		size: humanSize(file.size),
		href: safeLink(file.downloadUrl) || safeLink(file.accessUrl),
		view: viewLink(file, type, preview),
		metadataUrl: safeLink(file.metadataUrl),
		diwooUrl: safeLink(file.diwooUrl),
	}
}

/**
 * The documents of a publication, from opencatalogi's attachment envelope.
 *
 * The envelope is OpenRegister's file listing, `{results: [...], total}`; a
 * bare array is read too. Per file only what the page shows is kept, and the
 * link is dropped when it is not one a visitor can safely follow.
 *
 * The id is the stable `documentId` when the read carries it, the
 * metadata links are only the ones the read carries, never a guessed path,
 * and `view` is set only for a type the browser (or, with `preview`, the
 * public data preview) can show (REQ-PDC-001, REQ-PDC-004).
 *
 * @param {object|Array} envelope The attachments answer.
 * @param {object} [options] Options.
 * @param {boolean} [options.preview] Whether a public data-file preview is available.
 * @return {Array<object>} `{id, title, type, size, href, view, metadataUrl, diwooUrl}` rows.
 *
 * @spec openspec/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-list-and-offer-every-document-for-download-req-wsd-005
 */
export function toDocuments(envelope, { preview = false } = {}) {
	return attachmentRows(envelope)
		.filter((file) => file && typeof file === 'object')
		.map((file) => documentRow(file, preview))
}

/**
 * The object's own metadata, never a row of the "All details" table.
 */
const METADATA_KEYS = ['@self', 'id']

/**
 * Words written in capitals when a property name is turned into a label.
 */
const ACRONYMS = ['api', 'bsn', 'html', 'id', 'kvk', 'pdf', 'uri', 'url', 'uuid']

/**
 * A readable label made from a property name: `sourceUrl` reads
 * `Source URL`, `woo_category` reads `Woo category`.
 *
 * @param {string} name The property name.
 * @return {string} The label.
 * @spec openspec/changes/publication-page-all-details/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-end-with-an-all-details-table-of-every-stored-property
 */
export function propertyLabel(name) {
	const words = String(name)
		.replace(/([a-z0-9])([A-Z])/g, '$1 $2')
		.replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
		.split(/[\s_-]+/)
		.filter((word) => word !== '')
		.map((word) => word.toLowerCase())
		.map((word) => (ACRONYMS.includes(word) ? word.toUpperCase() : word))
	if (words.length === 0) {
		return ''
	}

	const first = words[0]
	words[0] = first.charAt(0).toUpperCase() + first.slice(1)
	return words.join(' ')
}

/**
 * One value as the table shows it: its text, and a link when it is shaped
 * like a web address.
 *
 * @param {unknown} value A scalar or a nested value.
 * @return {{text: string, href: string}} The item.
 */
function detailItem(value) {
	const text = typeof value === 'object' ? JSON.stringify(value) : String(value)
	const href = /^https?:\/\/\S+$/i.test(text.trim()) ? text.trim() : ''
	return { text, href }
}

/**
 * The rows of the "All details" table below the worded part: every stored
 * property except the object's own metadata (`@self`, `id`), in stored
 * order, with a readable label. A property without a value is left out; a
 * list shows one item per entry; a value shaped like a URL is a link.
 *
 * @param {object} publication One publication object.
 * @return {Array<{name: string, label: string, items: Array<{text: string, href: string}>}>} The rows.
 * @spec openspec/changes/publication-page-all-details/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-end-with-an-all-details-table-of-every-stored-property
 */
export function allDetailRows(publication) {
	if (!publication || typeof publication !== 'object' || Array.isArray(publication)) {
		return []
	}

	return Object.entries(publication)
		.filter(([name, value]) => !METADATA_KEYS.includes(name) && !isEmpty(value))
		.map(([name, value]) => ({
			name,
			label: propertyLabel(name),
			items: (Array.isArray(value) ? value : [value])
				.filter((entry) => !isEmpty(entry))
				.map(detailItem),
		}))
		.filter((row) => row.items.length > 0 && row.label !== '')
}
