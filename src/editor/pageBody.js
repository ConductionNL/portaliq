/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A page's body as the editor reads it, and the payloads it writes.
 *
 * A PAGE IS A GRID OR MARKDOWN, AND THE EDITOR KEEPS WHICH. The designer this
 * replaces read only `body.widgets` and always published `{type: 'grid'}`, so
 * publishing a markdown page destroyed its markdown. Here the body type travels
 * from the read to every write: a markdown page is written back as markdown,
 * and nothing in the grid editor can reach its payload.
 *
 * The write is a REPLACE (a full-object PUT), so every payload starts from the
 * whole stored page: a save that rebuilt the object from the fields this module
 * knows would drop every property it does not.
 *
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 */

import { normaliseWidgets, storedWidget } from './gridModel.js'

/**
 * The page without its OpenRegister `@self` envelope.
 *
 * @param {object} object The object as read.
 * @return {object} The page.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 */
export function withoutEnvelope(object) {
	const page = { ...(object || {}) }
	delete page['@self']
	return page
}

/**
 * The version marker of an object as read: its `@self.updated`.
 *
 * @param {object} object The object as read.
 * @return {string} The marker, or '' when the store gave none.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-a-save-must-not-overwrite-a-newer-save-by-someone-else-req-pie-005
 */
export function versionOf(object) {
	return String(object?.['@self']?.updated || '')
}

/**
 * What the editor opens: the draft when there is one, else the published body.
 *
 * The draft wins: an editor who saved a draft and came back to the published
 * layout would conclude their work was lost.
 *
 * @param {object} page The page.
 * @return {{kind: string, widgets: Array<object>, markdown: string, fromDraft: boolean}} The body.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 */
export function readBody(page) {
	const fromDraft = Boolean(page?.draftBody)
	const body = page?.draftBody || page?.body || {}
	if (body.type === 'markdown') {
		return {
			kind: 'markdown',
			widgets: [],
			markdown: String(body.markdown || ''),
			fromDraft,
		}
	}
	return {
		kind: 'grid',
		widgets: normaliseWidgets(body.widgets),
		markdown: '',
		fromDraft,
	}
}

/**
 * The body to store for what the editor holds.
 *
 * @param {{kind: string, widgets: Array<object>, markdown: string}} state The editor's body.
 * @return {object} The body.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 */
export function bodyFor(state) {
	if (state.kind === 'markdown') {
		return { type: 'markdown', markdown: String(state.markdown || '') }
	}
	return { type: 'grid', widgets: (state.widgets || []).map(storedWidget) }
}

/**
 * The page with a new draft; the live body untouched.
 *
 * @param {object} page The page as loaded.
 * @param {object} body The draft body.
 * @return {object} The payload.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 */
export function draftPayload(page, body) {
	return { ...withoutEnvelope(page), draftBody: body }
}

/**
 * The page with the body published and the draft cleared.
 *
 * The write is a replace, so removing the key is how the draft is cleared.
 *
 * @param {object} page The page as loaded.
 * @param {object} body The body to publish.
 * @return {object} The payload.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 */
export function publishPayload(page, body) {
	const payload = { ...withoutEnvelope(page), body }
	delete payload.draftBody
	return payload
}

/**
 * The page with its draft thrown away and its body as it was.
 *
 * @param {object} page The page as loaded.
 * @return {object} The payload.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 */
export function discardPayload(page) {
	const payload = withoutEnvelope(page)
	delete payload.draftBody
	return payload
}
