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

import { wooCategoryLabel } from './wooCategories.js'

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
 * @return {string} For example `2-9-2026`.
 */
function readableDate(value, locale) {
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return ''
	}

	try {
		return date.toLocaleDateString(locale === 'en' ? 'en-GB' : 'nl-NL')
	} catch {
		return ''
	}
}

/**
 * The summary a visitor reads under the title: `summary`, else `description`.
 *
 * @param {object} publication One publication object.
 * @return {string} The summary, or ''.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
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
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
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
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 */
export function visitorRows(publication, { t, locale, themeNames = {} }) {
	if (!publication || typeof publication !== 'object') {
		return []
	}

	const rows = []
	const date = isEmpty(publication.publicationDate)
		? ''
		: readableDate(publication.publicationDate, locale)
	if (date !== '') {
		rows.push({
			name: 'publicationDate',
			label: t('Publication date'),
			value: date,
			kind: 'text',
		})
	}

	if (!isEmpty(publication.wooCategory)) {
		rows.push({
			name: 'wooCategory',
			label: t('Information category'),
			value: wooCategoryLabel(publication.wooCategory, locale),
			kind: 'text',
		})
	}

	const themes = themeIdsOf(publication)
		.map((id) => themeNames[id])
		.filter((name) => typeof name === 'string' && name.trim() !== '')
	if (themes.length > 0) {
		rows.push({
			name: 'themes',
			label: t('Themes'),
			value: themes,
			kind: 'list',
		})
	}

	return rows
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
 * A file size the way a Dutch reader writes it: `120 kB`, `2,4 MB`.
 *
 * @param {number} bytes The size in bytes.
 * @return {string} The size, or '' when unknown.
 *
 * @spec openspec/changes/woo-search-and-detail/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-list-and-offer-every-document-for-download-req-wsd-005
 */
export function humanSize(bytes) {
	if (bytes === null || bytes === undefined || bytes === '') {
		return ''
	}

	const size = Number(bytes)
	if (Number.isFinite(size) === false || size < 0) {
		return ''
	}

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
 * The documents of a publication, from opencatalogi's attachment envelope.
 *
 * The envelope is OpenRegister's file listing, `{results: [...], total}`; a
 * bare array is read too. Per file only what the page shows is kept, and the
 * link is dropped when it is not one a visitor can safely follow.
 *
 * @param {object|Array} envelope The attachments answer.
 * @return {Array<object>} `{id, title, type, size, href}` rows.
 *
 * @spec openspec/changes/woo-search-and-detail/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-list-and-offer-every-document-for-download-req-wsd-005
 */
export function toDocuments(envelope) {
	let files = []
	if (Array.isArray(envelope) === true) {
		files = envelope
	} else if (envelope && Array.isArray(envelope.results) === true) {
		files = envelope.results
	}

	return files
		.filter((file) => file && typeof file === 'object')
		.map((file) => ({
			id: String(file.id ?? ''),
			title: String(file.title || file.name || 'Document'),
			type: fileType(file),
			size: humanSize(file.size),
			href: safeLink(file.downloadUrl) || safeLink(file.accessUrl),
		}))
}
