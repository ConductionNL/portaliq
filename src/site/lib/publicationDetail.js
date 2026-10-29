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

import { richTextLines } from '../components/collections/richText.js'
import { valueLabel } from './facetLabels.js'

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
 * The summary a visitor reads under the title: `summary`, else `description`,
 * else `shortDescription` (the stackiq schemas name it so).
 *
 * @param {object} publication One publication object.
 * @return {string} The summary, or ''.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words
 * @spec openspec/changes/publication-page-shows-the-schemas-fields/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-the-public-fields-of-any-other-schema
 */
export function publicationSummary(publication) {
	const row = publication || {}
	for (const key of ['summary', 'description', 'shortDescription']) {
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
			value: valueLabel('wooCategory', publication.wooCategory, locale),
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
 * The long description a visitor reads under the summary, as lines of text
 * (its markdown headings and paragraphs, never markup), or none when absent
 * or the same as the summary.
 *
 * @param {object} publication One publication object.
 * @return {Array<{index: number, level: number, text: string}>} The lines.
 * @spec openspec/changes/publication-page-shows-the-schemas-fields/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-the-public-fields-of-any-other-schema
 */
export function longDescriptionLines(publication) {
	const value = (publication || {}).longDescription
	if (typeof value !== 'string' || value.trim() === '') {
		return []
	}

	if (value.trim() === publicationSummary(publication)) {
		return []
	}

	return richTextLines(value)
}

/**
 * The fields the page shows elsewhere: never a row of their own.
 */
const SHOWN_ELSEWHERE = new Set([
	'@self',
	'id',
	'name',
	'title',
	'summary',
	'description',
	'shortDescription',
	'longDescription',
	'publicationDate',
	'wooCategory',
	'themes',
])

/**
 * A field name in words, for a field the schema gives no title:
 * `hostingLocation` reads `Hosting location`.
 *
 * @param {string} name The field name.
 * @return {string} The label.
 * @spec openspec/changes/publication-page-shows-the-schemas-fields/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-the-public-fields-of-any-other-schema
 */
export function fieldLabel(name) {
	const words = String(name)
		.replace(/[_-]+/g, ' ')
		.replace(/([a-z0-9])([A-Z])/g, '$1 $2')
		.trim()
		.toLowerCase()

	return words.charAt(0).toUpperCase() + words.slice(1)
}

/**
 * The schema the publication carries in `@self.schema` when it was asked
 * for with `_extend=@self.schema`, or null: a peer that ignores the extend
 * answers with the schema's id.
 *
 * @param {object} publication One publication object.
 * @return {object|null} The schema's properties by name, or null.
 */
function schemaProperties(publication) {
	const schema = ((publication || {})['@self'] || {}).schema
	if (!schema || typeof schema !== 'object') {
		return null
	}

	const properties = schema.properties
	return properties && typeof properties === 'object' ? properties : null
}

/**
 * The field names in the order the page shows them: the schema's `order`,
 * then the schema's own order, then whatever the schema does not describe.
 *
 * @param {object} publication One publication object.
 * @param {object|null} properties The schema's properties, or null.
 * @return {Array<string>} The field names.
 */
function fieldOrder(publication, properties) {
	if (properties === null) {
		return Object.keys(publication)
	}

	const described = Object.keys(properties)
		.map((name, index) => ({
			name,
			index,
			order: Number(properties[name]?.order),
		}))
		.sort((a, b) => {
			const left = Number.isFinite(a.order) ? a.order : Infinity
			const right = Number.isFinite(b.order) ? b.order : Infinity
			return left === right ? a.index - b.index : left - right
		})
		.map((entry) => entry.name)

	return [
		...described,
		...Object.keys(publication).filter((name) => !(name in properties)),
	]
}

/**
 * Whether a property points at another object (`$ref`), for itself or its
 * items: its value is then that object, or only its id.
 *
 * @param {object} property The schema property.
 * @return {boolean} True for a relation.
 */
function isRelation(property) {
	const ref = (value) => typeof value === 'string' && value !== ''
	return ref(property.$ref) || ref((property.items || {}).$ref)
}

/**
 * One value as text: a related object by its name or title, a date the
 * reader's way, an enum value by the schema's label, a yes/no in words. A
 * related object known only by its id reads as nothing: no codes on the page.
 *
 * @param {unknown} value The value.
 * @param {object} property The schema property, or `{}`.
 * @param {string} locale The site's language.
 * @return {string} The text, or '' when there is nothing to show.
 */
function valueText(value, property, locale) {
	if (value === null || value === undefined) {
		return ''
	}

	if (typeof value === 'boolean') {
		if (locale === 'en') {
			return value === true ? 'Yes' : 'No'
		}
		return value === true ? 'Ja' : 'Nee'
	}

	if (typeof value === 'object') {
		const self = value['@self'] || {}
		const name = value.name || value.title || self.name
		return typeof name === 'string' ? name.trim() : ''
	}

	if (isRelation(property) === true) {
		return ''
	}

	const text = String(value).trim()
	const labels = property['x-enum-labels']
	if (labels && typeof labels[text] === 'string') {
		return labels[text]
	}

	const isDate =
		['date', 'date-time'].includes(property.format)
		|| /^\d{4}-\d{2}-\d{2}(T[\d:.]+(Z|[+-]\d{2}:?\d{2})?)?$/.test(text)
	if (isDate === true) {
		return readableDate(text, locale) || text
	}

	return text
}

/**
 * Every other field the API returned, as a row, so the schema in
 * OpenRegister decides what the page shows: its property-level RBAC already
 * strips what an anonymous reader may not see, and a property the schema
 * marks `visible: false` stays off the page as it stays out of
 * OpenRegister's own exports. The schema names and orders the rows when the
 * publication carries it; otherwise the field name is put in words.
 *
 * Left out: an empty value, a file, a related object known only by its id,
 * and every row of a Woo publication (it carries `wooCategory`), whose
 * remaining fields are the archive's bookkeeping (see visitorRows).
 *
 * @param {object} publication One publication object.
 * @param {object} context The reader.
 * @param {string} context.locale The site's language.
 * @return {Array<{name: string, label: string, value: string|Array<string>|Array<object>, kind: string}>} The rows; `kind` is `text`, `link`, `list` or `lines`.
 * @spec openspec/changes/publication-page-shows-the-schemas-fields/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-the-public-fields-of-any-other-schema
 */
export function schemaRows(publication, { locale }) {
	if (!publication || typeof publication !== 'object') {
		return []
	}

	if (Object.hasOwn(publication, 'wooCategory')) {
		return []
	}

	const properties = schemaProperties(publication)
	const rows = []
	for (const name of fieldOrder(publication, properties)) {
		const value = publication[name]
		const property = (properties || {})[name] || {}
		if (SHOWN_ELSEWHERE.has(name) || isEmpty(value)) {
			continue
		}

		if (property.visible === false || property.type === 'file') {
			continue
		}

		const label =
			typeof property.title === 'string' && property.title.trim() !== ''
				? property.title.trim()
				: fieldLabel(name)

		if (Array.isArray(value) === true) {
			const items = value
				.map((item) => valueText(item, property.items || {}, locale))
				.filter((item) => item !== '')
			if (items.length > 0) {
				rows.push({ name, label, value: items, kind: 'list' })
			}
			continue
		}

		if (property.format === 'markdown' && typeof value === 'string') {
			const lines = richTextLines(value)
			if (lines.length > 0) {
				rows.push({ name, label, value: lines, kind: 'lines' })
			}
			continue
		}

		const text = valueText(value, property, locale)
		if (text === '') {
			continue
		}

		rows.push({
			name,
			label,
			value: text,
			kind: /^https?:\/\//i.test(text) === true ? 'link' : 'text',
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
