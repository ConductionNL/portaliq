#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-page-tree.spec.mjs: the rest of the portal from the portal
// (portal-in-place-editing A3, REQ-PIE-010 to REQ-PIE-012): the page tree,
// new, rename, move and delete of pages, and the menu. Every payload is
// validated against the REAL schema fragments in
// lib/Settings/portaliq_register.json.
//
// Usage:
//   node --test tests/site-page-tree.spec.mjs

import Ajv from 'ajv'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	addMenuItem,
	buildPageTree,
	canDeletePage,
	createPortalObjects,
	menuPayload,
	moveMenuItem,
	movePagePayload,
	newPagePayload,
	removeMenuItem,
	renameMenuItem,
	renamePagePayload,
} from '../src/editor/index.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const register = JSON.parse(readFileSync(join(ROOT, 'lib', 'Settings', 'portaliq_register.json'), 'utf8'))
const schemas = register.components.schemas
const ajv = new Ajv({ strict: false, allErrors: true })

/**
 * A validator for one schema of the register.
 *
 * @param {string} slug The schema slug.
 * @return {Function} The validator.
 */
function validatorFor(slug) {
	return ajv.compile({ type: 'object', required: schemas[slug].required, properties: schemas[slug].properties })
}
const validPage = validatorFor('page')
const validMenu = validatorFor('menu')

/**
 * Assert the real schema accepts a payload.
 *
 * @param {Function} validate The validator.
 * @param {object} payload The payload.
 * @return {void}
 */
function assertValid(validate, payload) {
	assert.ok(validate(payload), JSON.stringify(validate.errors))
}

const PAGES = [
	{ id: 'home', title: 'Home', route: '/', portal: 'p', status: 'published' },
	{ id: 'about', title: 'Over ons', route: '/over-ons', portal: 'p', status: 'published', order: 2 },
	{ id: 'news', title: 'Nieuws', route: '/nieuws', portal: 'p', status: 'published', order: 1 },
	{ id: 'team', title: 'Team', route: '/over-ons/team', portal: 'p', status: 'draft', parent: 'about' },
	{ id: 'lost', title: 'Wees', route: '/wees', portal: 'p', status: 'draft', parent: 'gone' },
]

// REQ-PIE-010 ---------------------------------------------------------------

test('the page schema carries parent and order, and the register version moved', () => {
	assert.equal(schemas.page.properties.parent.type, 'string')
	assert.equal(schemas.page.properties.order.type, 'integer')
	assert.equal('format' in schemas.page.properties.parent, false, 'adding a format to a stored field is breaking')
	assert.equal(schemas.page.version, '0.7.0')
	// At least 0.49.0, which added the tree; a later additive bump (0.50.0,
	// signin-session-idle-warning-and-sso) keeps it.
	const atLeast = (v) => v.split('.').map(Number).reduce((acc, n, i) => acc || (acc === 0 ? Math.sign(n - [0, 49, 0][i]) : acc), 0) >= 0
	assert.ok(atLeast(register.info.version), register.info.version)
	assert.equal(register.components.registers.portaliq.version, register.info.version)
})

test('pages form a tree ordered by order, then title; an orphan sits at the top', () => {
	const tree = buildPageTree(PAGES)
	assert.deepEqual(tree.map((n) => n.page.id), ['news', 'about', 'home', 'lost'])
	assert.deepEqual(tree[1].children.map((n) => n.page.id), ['team'])
	assert.equal(tree[1].children[0].depth, 1)
})

test('an editor creates a page under another, as a draft the schema accepts', () => {
	const payload = newPagePayload({ title: 'Contact', route: 'over-ons/contact', portal: 'p', parent: 'about' }, PAGES)
	assert.deepEqual(payload, {
		title: 'Contact',
		route: '/over-ons/contact',
		portal: 'p',
		status: 'draft',
		parent: 'about',
		order: 1,
		body: { type: 'grid', widgets: [] },
	})
	assertValid(validPage, payload)
	assert.throws(() => newPagePayload({ title: 'Dubbel', route: '/over-ons', portal: 'p' }, PAGES), /route/)
	assert.throws(() => newPagePayload({ title: '', route: '/x', portal: 'p' }, PAGES), /title/)
})

test('rename keeps the route, move keeps the route and refuses a move under itself', () => {
	const about = { ...PAGES[1], body: { type: 'markdown', markdown: 'x' } }
	const renamed = renamePagePayload(about, 'Over de gemeente')
	assert.equal(renamed.title, 'Over de gemeente')
	assert.equal(renamed.route, '/over-ons')
	assertValid(validPage, renamed)

	const moved = movePagePayload(PAGES[3], { parent: 'news', order: 3 }, PAGES)
	assert.equal(moved.parent, 'news')
	assert.equal(moved.order, 3)
	assert.equal(moved.route, '/over-ons/team')
	assertValid(validPage, moved)

	const top = movePagePayload(PAGES[3], { parent: '', order: 0 }, PAGES)
	assert.equal('parent' in top, false)

	assert.throws(() => movePagePayload(PAGES[1], { parent: 'team', order: 0 }, PAGES), /under itself/)
	assert.throws(() => movePagePayload(PAGES[1], { parent: 'about', order: 0 }, PAGES), /under itself/)
})

test('a published page cannot be deleted from the portal, a draft can', () => {
	assert.equal(canDeletePage(PAGES[1]), false)
	assert.equal(canDeletePage(PAGES[3]), true)
})

test('the portal objects client lists, creates, deletes and saves with the version check', async () => {
	const calls = []
	const api = createPortalObjects({
		get: async (url, config) => {
			calls.push(['get', url, config?.params])
			return { data: { results: [{ ...PAGES[0], '@self': { id: 'home', updated: 't1' } }] } }
		},
		post: async (url, payload) => {
			calls.push(['post', url, payload])
			return { data: { ...payload, '@self': { id: 'new' } } }
		},
		put: async (url, payload, config) => calls.push(['put', url, config.headers['If-Match']]),
		del: async (url) => calls.push(['delete', url]),
		url: (slug, id) => `/or/portaliq/${slug}${id ? '/' + id : ''}`,
	})
	const pages = await api.list('page', 'p')
	assert.deepEqual(pages, [{ ...PAGES[0], id: 'home', version: 't1' }])
	assert.deepEqual(calls[0], ['get', '/or/portaliq/page', { portal: 'p', _limit: 500 }])

	await api.create('page', { title: 'x' })
	assert.deepEqual(calls[1], ['post', '/or/portaliq/page', { title: 'x' }])

	await api.save('page', 'home', { title: 'Home 2' }, 't1')
	assert.deepEqual(calls.slice(2), [
		['get', '/or/portaliq/page/home', undefined],
		['put', '/or/portaliq/page/home', 't1'],
	])

	await api.remove('page', 'team')
	assert.deepEqual(calls.at(-1), ['delete', '/or/portaliq/page/team'])
})

// REQ-PIE-011 ---------------------------------------------------------------

test('an editor adds, renames, reorders and removes menu items, and the menu saves as the schema wants', () => {
	let items = [
		{ name: 'Home', link: '/', order: 0 },
		{ name: 'Over ons', link: '/over-ons', order: 1, items: [{ name: 'Team', link: '/over-ons/team' }] },
	]
	items = addMenuItem(items, { name: 'Contact', link: '/over-ons/contact' })
	assert.deepEqual(items.map((i) => [i.name, i.order]), [['Home', 0], ['Over ons', 1], ['Contact', 2]])

	items = renameMenuItem(items, 2, 'Contact opnemen')
	items = moveMenuItem(items, 2, -1)
	assert.deepEqual(items.map((i) => [i.name, i.order]), [['Home', 0], ['Contact opnemen', 1], ['Over ons', 2]])
	// A sub-menu travels with its item.
	assert.equal(items[2].items[0].name, 'Team')

	items = moveMenuItem(items, 0, -1)
	assert.equal(items[0].name, 'Home', 'moving the first item up does nothing')

	items = removeMenuItem(items, 0)
	assert.deepEqual(items.map((i) => [i.name, i.order]), [['Contact opnemen', 0], ['Over ons', 1]])

	const menu = { title: 'Hoofdmenu', portal: 'p', position: 0, items: [], '@self': { id: 'm1' } }
	const payload = menuPayload(menu, items)
	assert.equal('@self' in payload, false)
	assert.equal(payload.items.length, 2)
	assertValid(validMenu, payload)
	assert.throws(() => addMenuItem(items, { name: ' ', link: '/x' }), /name/)
})

test('the site edit mode offers the pages and the menu panels', () => {
	const mode = readFileSync(join(ROOT, 'src/editor/SiteEditMode.vue'), 'utf8')
	assert.match(mode, /<SitePagesPanel/)
	assert.match(mode, /<SiteMenuPanel/)
	const pages = readFileSync(join(ROOT, 'src/editor/SitePagesPanel.vue'), 'utf8')
	assert.match(pages, /canDeletePage\(/)
	for (const id of ['site-pages-new', 'site-pages-rename', 'site-pages-move', 'site-pages-delete']) {
		assert.match(pages, new RegExp(id), id)
	}
	const menu = readFileSync(join(ROOT, 'src/editor/SiteMenuPanel.vue'), 'utf8')
	for (const id of ['site-menu-add', 'site-menu-rename', 'site-menu-up', 'site-menu-down', 'site-menu-remove', 'site-menu-save']) {
		assert.match(menu, new RegExp(id), id)
	}
})
