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
 * THE EDITOR EDITS ONLY THE `main` REGION, AND KEEPS THE OTHERS. A widget whose
 * slot names `header`, `hero`, `aside` or `footer` (or no known region) is not
 * shown in the grid, and every write puts it back exactly as it was stored,
 * together with the body's `clearedRegions` and any other body key. The source
 * of those is re-read from the page at write time, so the admin designer and the
 * portal edit mode, which both go through this module, cannot drop a hero.
 *
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-page-designer-must-preserve-regions-it-does-not-edit-req-ptb-010
 */

import { regionOf } from '../site/lib/regions.js'
import { normaliseWidgets, storedWidget } from './gridModel.js'

/**
 * The page without its OpenRegister `@self` envelope.
 *
 * @param {object} object The object as read.
 * @return {object} The page.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
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
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-a-save-must-not-overwrite-a-newer-save-by-someone-else-req-pie-005
 */
export function versionOf(object) {
	return String(object?.['@self']?.updated || '')
}

/**
 * The body the editor opened: the draft when there is one, else the published body.
 *
 * @param {object} page The page.
 * @return {object} The stored body, or an empty object.
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-page-designer-must-preserve-regions-it-does-not-edit-req-ptb-010
 */
export function sourceBodyOf(page) {
	return page?.draftBody || page?.body || {}
}

/**
 * Whether a stored widget belongs to the `main` region, the one the editor edits.
 *
 * @param {object} widget A stored widget.
 * @return {boolean} True for `main`, `body` or no slot.
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-page-designer-must-preserve-regions-it-does-not-edit-req-ptb-010
 */
export function isMainWidget(widget) {
	return regionOf(widget?.slot) === 'main'
}

/**
 * What the editor opens: the draft when there is one, else the published body.
 *
 * The draft wins: an editor who saved a draft and came back to the published
 * layout would conclude their work was lost.
 *
 * Only the `main` widgets reach the grid; the other regions stay on the page
 * and `bodyFor()` writes them back.
 *
 * @param {object} page The page.
 * @return {{kind: string, widgets: Array<object>, markdown: string, fromDraft: boolean}} The body.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-page-designer-must-preserve-regions-it-does-not-edit-req-ptb-010
 */
export function readBody(page) {
	const fromDraft = Boolean(page?.draftBody)
	const body = sourceBodyOf(page)
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
		widgets: normaliseWidgets(body.widgets).filter(isMainWidget),
		markdown: '',
		fromDraft,
	}
}

/**
 * The body to store for what the editor holds.
 *
 * A grid body starts from the body the editor opened (`state.page`), so every
 * key it does not edit, `clearedRegions` among them, survives. Its widgets are
 * the stored widgets outside `main`, untouched, then the edited `main` widgets.
 *
 * @param {{kind: string, widgets: Array<object>, markdown: string, page?: object}} state The editor's state.
 * @return {object} The body.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-page-designer-must-preserve-regions-it-does-not-edit-req-ptb-010
 */
export function bodyFor(state) {
	if (state.kind === 'markdown') {
		return { type: 'markdown', markdown: String(state.markdown || '') }
	}
	const source = sourceBodyOf(state.page)
	const kept =
		source.type === 'markdown' || !Array.isArray(source.widgets)
			? []
			: source.widgets
					.filter((widget) => !isMainWidget(widget))
					.map((widget) => JSON.parse(JSON.stringify(widget)))
	const rest = source.type === 'markdown' ? {} : { ...source }
	delete rest.markdown
	return {
		...rest,
		type: 'grid',
		widgets: [...kept, ...(state.widgets || []).map(storedWidget)],
	}
}

/**
 * The page with a new draft; the live body untouched.
 *
 * @param {object} page The page as loaded.
 * @param {object} body The draft body.
 * @return {object} The payload.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
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
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
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
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-designer-must-keep-a-markdown-pages-markdown-req-pie-001
 */
export function discardPayload(page) {
	const payload = withoutEnvelope(page)
	delete payload.draftBody
	return payload
}
