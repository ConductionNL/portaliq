// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The route of a public document's own page (publication-detail-page-complete
// REQ-PDC-002): `/document/<publicationId>/<documentId>`.
//
// Imports nothing, so the site shell can match the route without pulling the
// page's reading code into the first bundle (webpack.site.js budget).
//
// @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
/** The in-site route of a document's page; the ids are appended. */
export const DOCUMENT_ROUTE = '/document'

/** The publication page's route, which the back link follows. */
export const PUBLICATION_ROUTE = '/publicatie'

/** The federation read of one publication, public on opencatalogi. */
export const PUBLICATION_ENDPOINT =
	'/index.php/apps/opencatalogi/api/federation/publications'

/**
 * The two ids a document route names, or null when the route is not a
 * document page.
 *
 * @param {string} route The in-site route.
 * @return {{publicationId: string, documentId: string}|null}
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export function documentRouteOf(route) {
	const path = String(route || '')
		.split(/[?#]/)[0]
		.replace(/\/+$/, '')
	const match = path.match(/^\/document\/([^/]+)\/([^/]+)$/)
	if (match === null) {
		return null
	}

	try {
		const publicationId = decodeURIComponent(match[1]).trim()
		const documentId = decodeURIComponent(match[2]).trim()
		if (publicationId === '' || documentId === '') {
			return null
		}
		return { publicationId, documentId }
	} catch {
		return null
	}
}

/**
 * Whether a route is a document page.
 *
 * @param {string} route The in-site route.
 * @return {boolean}
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export function isDocumentRoute(route) {
	return documentRouteOf(route) !== null
}

/**
 * The route of one document's page, or '' when either id is missing.
 *
 * @param {string} publicationId The publication's id.
 * @param {string} documentId The document's id.
 * @return {string}
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export function documentPageRoute(publicationId, documentId) {
	const publication = String(publicationId ?? '').trim()
	const document = String(documentId ?? '').trim()
	if (publication === '' || document === '') {
		return ''
	}

	return `${DOCUMENT_ROUTE}/${encodeURIComponent(publication)}/${encodeURIComponent(document)}`
}

/**
 * The route of the publication a document belongs to.
 *
 * @param {string} publicationId The publication's id.
 * @return {string}
 * @spec openspec/changes/publication-detail-page-complete/specs/portal-federated-search/spec.md#requirement-every-public-document-has-a-page-of-its-own-req-pdc-002
 */
export function publicationPageRoute(publicationId) {
	return `${PUBLICATION_ROUTE}/${encodeURIComponent(String(publicationId ?? '').trim())}`
}
