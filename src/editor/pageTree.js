/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pages of a portal as a tree, and the payloads for new, rename and move.
 *
 * THE ROUTE STAYS THE ADDRESS. `parent` and `order` place a page in the tree
 * an editor manages; they never change its route, so moving a page never
 * breaks a link someone saved. A new page is always a draft: it is not served
 * until someone publishes it.
 *
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */

import { withoutEnvelope } from './pageBody.js'

/**
 * The route as the site matches it: one leading slash, no trailing one.
 *
 * @param {string} route The typed route.
 * @return {string} The route.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */
export function normaliseRoute(route) {
	const trimmed = String(route || '').trim().replace(/^\/+|\/+$/g, '')
	return '/' + trimmed
}

/**
 * Sort pages: by `order`, pages without one last, then by title.
 *
 * @param {object} a A page.
 * @param {object} b A page.
 * @return {number} The comparison.
 */
function byOrder(a, b) {
	const oa = Number.isInteger(a.order) ? a.order : Number.MAX_SAFE_INTEGER
	const ob = Number.isInteger(b.order) ? b.order : Number.MAX_SAFE_INTEGER
	return oa - ob || String(a.title || '').localeCompare(String(b.title || ''))
}

/**
 * The pages as a tree of `{page, depth, children}`.
 *
 * A page whose parent is not among the pages (deleted, or on another portal)
 * sits at the top rather than disappearing: an editor has to be able to see it
 * to move it.
 *
 * @param {Array<object>} pages The portal's pages, each with an `id`.
 * @return {Array<object>} The top-level nodes.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */
export function buildPageTree(pages) {
	const ids = new Set(pages.map((p) => p.id))
	const childrenOf = (parent, depth, seen) =>
		pages
			.filter((p) => (parent === null ? !p.parent || !ids.has(p.parent) : p.parent === parent))
			.filter((p) => !seen.has(p.id))
			.sort(byOrder)
			.map((page) => {
				const next = new Set(seen).add(page.id)
				return { page, depth, children: childrenOf(page.id, depth + 1, next) }
			})
	return childrenOf(null, 0, new Set())
}

/**
 * The tree flattened in display order, for a list with indentation.
 *
 * @param {Array<object>} tree The nodes.
 * @return {Array<object>} The nodes, depth first.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */
export function flattenPageTree(tree) {
	return tree.flatMap((node) => [node, ...flattenPageTree(node.children)])
}

/**
 * The ids of a page and everything under it.
 *
 * @param {string} id The page id.
 * @param {Array<object>} pages The pages.
 * @return {Set<string>} The ids.
 */
function subtreeIds(id, pages) {
	const out = new Set([id])
	let grew = true
	while (grew) {
		grew = false
		for (const page of pages) {
			if (page.parent && out.has(page.parent) && !out.has(page.id)) {
				out.add(page.id)
				grew = true
			}
		}
	}
	return out
}

/**
 * Whether the portal edit mode may delete a page: only one never published.
 * A published page has visitors and links; taking it down is an admin task.
 *
 * @param {object} page The page.
 * @return {boolean} True for a draft page.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */
export function canDeletePage(page) {
	return page?.status === 'draft'
}

/**
 * A new draft page under a parent, placed last among its siblings.
 *
 * @param {{title: string, route: string, portal: string, parent?: string}} input What the editor typed.
 * @param {Array<object>} pages The portal's pages, to refuse a taken route.
 * @return {object} The page to create.
 * @throws {Error} When the title is empty or the route is taken.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */
export function newPagePayload({ title, route, portal, parent = '' }, pages) {
	const name = String(title || '').trim()
	if (!name) {
		throw new Error('A page needs a title.')
	}
	const address = normaliseRoute(route)
	if (pages.some((p) => normaliseRoute(p.route) === address)) {
		throw new Error('Another page already uses this route.')
	}
	const siblings = pages.filter((p) => (p.parent || '') === (parent || ''))
	const payload = {
		title: name,
		route: address,
		portal,
		status: 'draft',
		order: siblings.length,
		body: { type: 'grid', widgets: [] },
	}
	if (parent) {
		payload.parent = parent
	}
	return payload
}

/**
 * The page with a new title; the route is untouched.
 *
 * @param {object} page The page as read.
 * @param {string} title The new title.
 * @return {object} The page to store.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */
export function renamePagePayload(page, title) {
	const name = String(title || '').trim()
	if (!name) {
		throw new Error('A page needs a title.')
	}
	const payload = withoutEnvelope(page)
	delete payload.id
	delete payload.version
	return { ...payload, title: name }
}

/**
 * The page under a new parent and at a new position; the route is untouched.
 *
 * @param {object} page The page as read.
 * @param {{parent: string, order: number}} target Where it goes; '' is the top.
 * @param {Array<object>} pages The portal's pages.
 * @return {object} The page to store.
 * @throws {Error} When the page would move under itself.
 * @spec openspec/changes/portal-in-place-editing/specs/portal-in-place-editing/spec.md#requirement-pages-must-form-a-tree-an-editor-manages-from-the-portal-req-pie-010
 */
export function movePagePayload(page, { parent, order }, pages) {
	if (parent && subtreeIds(page.id, pages).has(parent)) {
		throw new Error('A page cannot move under itself.')
	}
	const payload = withoutEnvelope(page)
	delete payload.id
	delete payload.version
	delete payload.parent
	payload.order = Math.max(0, Math.trunc(Number(order) || 0))
	if (parent) {
		payload.parent = parent
	}
	return payload
}
