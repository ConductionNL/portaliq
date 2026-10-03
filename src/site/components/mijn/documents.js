// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of the file items and the contact timeline: a document's
// type and size in words ("PDF, 84 kB"), who added it and when ("Van de
// gemeente, 2 oktober 2026"), and a moment in words for the timeline. No
// Vue, so tests/mijn-documents.spec.mjs runs it as node.
//
// @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005

/** File types a resident recognises by name, by media type. */
const TYPES = {
	'application/pdf': 'PDF',
	'application/msword': 'Word',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
		'Word',
	'application/vnd.oasis.opendocument.text': 'ODT',
	'application/vnd.ms-excel': 'Excel',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'Excel',
	'image/jpeg': 'JPG',
	'image/png': 'PNG',
	'text/plain': 'TXT',
}

/**
 * The page language: `en` or `nl`.
 *
 * @param {string} [locale] The locale handed in.
 * @return {string}
 */
function language(locale) {
	return String(locale || 'nl')
		.toLowerCase()
		.startsWith('en')
		? 'en'
		: 'nl'
}

/**
 * A document's type as a resident names it: from its media type, else its
 * file extension in capitals, else ''.
 *
 * @param {object} entry The document (`mimeType?`, `title`).
 * @return {string}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export function fileType(entry) {
	const known = TYPES[String(entry?.mimeType || '').toLowerCase()]
	if (known) {
		return known
	}
	const match = /\.([A-Za-z0-9]{2,5})$/.exec(
		String(entry?.title || entry?.name || ''),
	)
	return match ? match[1].toUpperCase() : ''
}

/**
 * A size in words: "84 kB", "1,2 MB" (the decimal mark of the page language).
 *
 * @param {number} bytes The size.
 * @param {string} [locale] The page language.
 * @return {string} The words, or '' without a size.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export function sizeInWords(bytes, locale = 'nl') {
	const size = Number(bytes)
	if (!Number.isFinite(size) || size <= 0) {
		return ''
	}
	if (size < 1024 * 1024) {
		return `${Math.max(1, Math.round(size / 1024))} kB`
	}
	const mb = (size / (1024 * 1024)).toFixed(1)
	return `${language(locale) === 'en' ? mb : mb.replace('.', ',')} MB`
}

/**
 * A day in words with its year: "2 oktober 2026".
 *
 * @param {string} value An ISO date.
 * @param {string} [locale] The page language.
 * @return {string} The words, or '' for no date.
 */
export function fullDay(value, locale = 'nl') {
	const date = value ? new Date(value) : null
	if (!date || Number.isNaN(date.getTime())) {
		return ''
	}
	return date.toLocaleDateString(language(locale) === 'en' ? 'en-GB' : 'nl-NL', {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	})
}

/**
 * A moment in words: "2 oktober 2026 om 14.20 uur"; a date without a time
 * reads as the day only.
 *
 * @param {string} value An ISO date or date-time.
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @param {string} [locale] The page language.
 * @return {string}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export function momentInWords(value, tr, locale = 'nl') {
	const day = fullDay(value, locale)
	if (!day || !/T\d{2}:\d{2}/.test(String(value))) {
		return day
	}
	const english = language(locale) === 'en'
	const time = new Date(value)
		.toLocaleTimeString(english ? 'en-GB' : 'nl-NL', {
			hour: '2-digit',
			minute: '2-digit',
		})
		.replace(':', english ? ':' : '.')
	return tr('{date} at {time}', { date: day, time })
}

/**
 * The line under a document's name: who added it and when, then its type
 * and size. "Van de gemeente, 2 oktober 2026. PDF, 84 kB".
 *
 * @param {object} entry The document (`kind`, `date?`, `mimeType?`, `size?`, `title`).
 * @param {(key: string, vars?: object) => string} tr The translator.
 * @param {string} [locale] The page language.
 * @return {string}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export function fileLine(entry, tr, locale = 'nl') {
	const from =
		entry?.kind === 'yours' ? tr('From you') : tr('From the municipality')
	const day = fullDay(entry?.date, locale)
	const who = day ? `${from}, ${day}` : from
	const facts = [fileType(entry), sizeInWords(entry?.size, locale)]
		.filter(Boolean)
		.join(', ')
	return facts ? `${who}. ${facts}` : `${who}.`
}
