// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// One public document's own page (publication-detail-page-complete REQ-PDC-002).
//
// `/document/<publicationId>/<documentId>` opens on every portal without a CMS
// page. The document is read through the public read of its publication: the
// publication by id, then that publication's attachment list, and the document
// is the row whose `documentId` is the one in the route. A publication the
// anonymous read does not return (a draft, a depublished one) has no documents
// to show, so its document page is not found, the same answer an unknown id
// gets.
//
// Imports only plain modules, so tests/document-page.spec.mjs runs it as node.
//
// @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002

import { PUBLICATION_ENDPOINT, publicationPageRoute } from './documentRoute.js'
import { formatDutchDate } from './federatedSearch.js'
import { attachmentId, attachmentRows, toDocuments } from './publicationDetail.js'

export {
	DOCUMENT_ROUTE,
	documentPageRoute,
	documentRouteOf,
	isDocumentRoute,
	PUBLICATION_ENDPOINT,
	PUBLICATION_ROUTE,
	publicationPageRoute,
} from './documentRoute.js'

/**
 * Whether a publication read answered the publication that was asked for.
 *
 * @param {object|null} publication The read's answer.
 * @param {string} publicationId The id asked for.
 * @return {boolean}
 */
function isPublication(publication, publicationId) {
	if (publication === null || typeof publication !== 'object') {
		return false
	}

	const self = publication['@self'] || {}
	return self.id === publicationId || publication.id === publicationId
}

/**
 * A value as trimmed text, '' for nothing.
 *
 * @param {unknown} value The value.
 * @return {string} The text.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
function trimmed(value) {
	return String(value || '').trim()
}

/**
 * The rows of a document's metadata list, in the order a reader takes them:
 * type, size, date, document kind, creation date. Only what the read carries.
 *
 * @param {object} file One row of the attachment list.
 * @param {(key: string) => string} t The translator.
 * @return {Array<{label: string, value: string}>}
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export function documentFacts(file, t) {
	const row = file || {}
	const view = toDocuments([row])[0]
	const facts = [
		{
			label: t('File type'),
			value: view.type || trimmed(row.format).toUpperCase(),
		},
		{ label: t('Size'), value: view.size },
		{ label: t('Published on'), value: formatDutchDate(row.published) },
		{ label: t('Document type'), value: trimmed(row.documentsoort) },
		{ label: t('Created on'), value: formatDutchDate(row.creationDate) },
	]

	return facts.filter((fact) => fact.value !== '')
}

/**
 * A publication's title, from its own fields or its metadata.
 *
 * @param {object} publication The publication read.
 * @return {string} The title, '' without one.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
function publicationTitle(publication) {
	const self = publication['@self'] || {}
	return String(publication.title || self.name || publication.name || '')
}

/**
 * The page a document route renders, from the two reads.
 *
 * Null, rendered as not found, when the publication read returned nothing or
 * another publication, or when the attachment list holds no row with the id.
 *
 * @param {object} reads The reads.
 * @param {object|null} reads.publication The publication read's answer.
 * @param {object|Array|null} reads.attachments The attachment list's answer.
 * @param {string} reads.publicationId The publication id in the route.
 * @param {string} reads.documentId The document id in the route.
 * @param {(key: string) => string} reads.t The translator.
 * @param {boolean} [reads.preview] Whether a public data-file preview exists.
 * @return {object|null} `{document, facts, publication: {id, title, route}}`.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export function documentPageOf({
	publication,
	attachments,
	publicationId,
	documentId,
	t,
	preview = false,
}) {
	if (isPublication(publication, publicationId) === false) {
		return null
	}

	const file = attachmentRows(attachments).find(
		(row) => row && typeof row === 'object' && attachmentId(row) === documentId,
	)
	if (file === undefined) {
		return null
	}

	return {
		document: toDocuments([file], { preview })[0],
		facts: documentFacts(file, t),
		publication: {
			id: publicationId,
			title: publicationTitle(publication),
			route: publicationPageRoute(publicationId),
		},
	}
}

/**
 * A reader that answers a URL's JSON, or null for a failed response.
 *
 * @param {(url: string, init: object) => Promise<object>} fetcher `fetch` or a stand-in.
 * @return {(url: string) => Promise<object|null>} The reader.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
function jsonReader(fetcher) {
	return async (url) => {
		const response = await fetcher(url, {
			headers: { Accept: 'application/json' },
		})
		return response.ok === true ? response.json() : null
	}
}

/**
 * Read a document's page through the public read of its publication.
 *
 * Any failed read, a 404 of a draft included, is not found: telling a
 * visitor that a document exists but is hidden is itself a disclosure.
 *
 * @param {object} asked What to read.
 * @param {string} asked.publicationId The publication id in the route.
 * @param {string} asked.documentId The document id in the route.
 * @param {(key: string) => string} asked.t The translator.
 * @param {boolean} [asked.preview] Whether a public data-file preview exists.
 * @param {string} [asked.endpoint] The publication read's base.
 * @param {(url: string, init: object) => Promise<object>} [asked.fetcher] A stand-in for `fetch` in a test.
 * @return {Promise<object|null>} The page, or null for not found.
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export async function loadDocumentPage({
	publicationId,
	documentId,
	t,
	preview,
	endpoint = PUBLICATION_ENDPOINT,
	fetcher = globalThis.fetch,
}) {
	const base = `${String(endpoint).replace(/\/+$/, '')}/${encodeURIComponent(publicationId)}`
	const read = jsonReader(fetcher)

	try {
		const publication = await read(base)
		if (isPublication(publication, publicationId) === false) {
			return null
		}
		const attachments = await read(`${base}/attachments`)
		return documentPageOf({
			publication,
			attachments,
			publicationId,
			documentId,
			t,
			preview,
		})
	} catch {
		return null
	}
}
