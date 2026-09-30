// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Turning one publication object into the rows the detail page renders.
 *
 * Extracted from the component so a plain node script can assert it — this app
 * has no JS test runner, and the shaping here is where the interesting cases
 * live: an empty value, an array, a nested object, a URL.
 *
 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
 */

/**
 * Property names the page never shows.
 *
 * `@self` is OpenRegister's metadata envelope — register ids, folder paths,
 * lock state, the authorization block. It is machinery, and printing it would
 * put internal identifiers on a citizen-facing page.
 *
 * Note what is NOT here: `hidden`, `downloads`, `rating`. The reference portal
 * prints those, so this reproduction prints them too. They are bookkeeping and
 * arguably should go — as a separate, reviewable change rather than smuggled in
 * under "match the reference".
 */
const HIDDEN_PROPERTIES = new Set(['@self', 'id', 'title', 'name'])

/**
 * Words that stay upper-case when a property name is humanised.
 */
const ACRONYMS = new Map([
	['url', 'URL'],
	['id', 'Id'],
	['yml', 'Yml'],
	['nl', 'Nl'],
])

/**
 * Turn a camelCase property name into the reference's label.
 *
 * `publiccodeYmlVersion` → `Publiccode Yml Version`, which is what
 * opencatalogi.nl prints. The transformation is mechanical, so the page needs
 * no per-field label table and a schema that gains a property gains a row.
 *
 * @param {string} name The property name.
 * @return {string} The human label.
 */
export function humaniseLabel(name) {
	return (
		String(name || '')
			// camelCase and consecutive capitals both become word boundaries, so
			// `landingUrl` and `publiccodeYmlVersion` both split correctly.
			.replace(/([a-z0-9])([A-Z])/g, '$1 $2')
			.replace(/[_-]+/g, ' ')
			.split(' ')
			.filter(Boolean)
			.map((word) => {
				const acronym = ACRONYMS.get(word.toLowerCase())
				if (acronym !== undefined) {
					return acronym
				}

				return word.charAt(0).toUpperCase() + word.slice(1)
			})
			.join(' ')
	)
}

/**
 * Whether a value should render as a link.
 *
 * @param {*} value The value.
 * @return {boolean} True for an http(s) URL.
 */
function isUrl(value) {
	return typeof value === 'string' && /^https?:\/\//i.test(value) === true
}

/**
 * Whether a value counts as empty for display.
 *
 * An empty ARRAY and an empty OBJECT are empty too. Without that, a
 * publication with `platforms: []` renders an empty bullet list — a row that
 * looks like a rendering fault rather than an absent value.
 *
 * @param {*} value The value.
 * @return {boolean} True when it should show as `-`.
 */
function isEmpty(value) {
	if (value === null || value === undefined || value === '') {
		return true
	}

	if (Array.isArray(value) === true) {
		return value.length === 0
	}

	if (typeof value === 'object') {
		return Object.keys(value).length === 0
	}

	return false
}

/**
 * One nested object rendered as label/value entries.
 *
 * @param {object} value The object.
 * @return {Array<object>} `{name, value, kind}` entries.
 */
function groupEntries(value) {
	return Object.entries(value).map(([name, entry]) => {
		if (isEmpty(entry) === true) {
			return { name, value: '-', kind: 'text' }
		}

		if (isUrl(entry) === true) {
			return { name, value: entry, kind: 'link' }
		}

		if (typeof entry === 'object') {
			// A third level is rendered as JSON rather than recursed. The
			// reference does the same — its `localisation.availableLanguages`
			// prints `[ "en" ]` — and unbounded nesting on a public page is a
			// depth nobody reads.
			return { name, value: JSON.stringify(entry), kind: 'text' }
		}

		return { name, value: String(entry), kind: 'text' }
	})
}

/**
 * The rows the detail page renders, in the order the API returned them.
 *
 * ORDER IS THE API'S, deliberately. The reference prints properties in
 * response order, and imposing a curated order here would be a design decision
 * disguised as a rendering detail — and would silently drop any property the
 * curator had not thought of.
 *
 * @param {object} publication One publication object.
 * @return {Array<object>} `{name, label, value, kind}` rows.
 */
export function detailFields(publication) {
	if (!publication || typeof publication !== 'object') {
		return []
	}

	const rows = []

	for (const [name, value] of Object.entries(publication)) {
		if (HIDDEN_PROPERTIES.has(name) === true) {
			continue
		}

		if (isEmpty(value) === true) {
			rows.push({ name, label: humaniseLabel(name), value: '-', kind: 'text' })
			continue
		}

		if (isUrl(value) === true) {
			rows.push({ name, label: humaniseLabel(name), value, kind: 'link' })
			continue
		}

		if (Array.isArray(value) === true) {
			rows.push({
				name,
				label: humaniseLabel(name),
				// Stringified per item: an array of objects would otherwise
				// render as `[object Object]` once per entry.
				value: value.map((item) =>
					typeof item === 'object' ? JSON.stringify(item) : String(item),
				),
				kind: 'list',
			})
			continue
		}

		if (typeof value === 'object') {
			rows.push({
				name,
				label: humaniseLabel(name),
				value: groupEntries(value),
				kind: 'group',
			})
			continue
		}

		rows.push({
			name,
			label: humaniseLabel(name),
			value: String(value),
			kind: 'text',
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
