#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// not-found-without-breadcrumb.spec.mjs: a route that answers 404 shows no
// breadcrumb, as the NietGevonden board draws it. A trail would name the
// missing address as a place on the site ("Home › Deze-pagina-bestaat-niet").
// Other routes keep their trail.
//
// Usage:
//   node --test tests/not-found-without-breadcrumb.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const app = readFileSync(new URL('../src/site/App.vue', import.meta.url), 'utf8')

/**
 * The body of one of the shell's methods or computeds, read from App.vue.
 *
 * @param {string} signature Its first line, e.g. `breadcrumbs()`.
 * @return {string} The body.
 */
function bodyOf(signature) {
	const start = app.indexOf(`\t\t${signature} {`)
	assert.notEqual(start, -1, `${signature} is in App.vue`)
	return app.slice(app.indexOf('{', start) + 1, app.indexOf('\n\t\t},\n', start))
}

const body = bodyOf('breadcrumbs()')
// The trail's two-crumb routes go through these two methods of the shell.
const ownRouteCrumbLabel = new Function(bodyOf('ownRouteCrumbLabel()'))
const homeAndHereCrumbs = new Function('label', bodyOf('homeAndHereCrumbs(label)'))

/**
 * The shell's breadcrumbs computed, run on a stand-in for the component.
 *
 * @param {object} self The component state it reads.
 * @return {Array<object>} The crumbs.
 */
function breadcrumbsOf(self) {
	return new Function('crumbLabel', 'menuLabelFor', 'subjectCrumbs', 'signInCrumbs', 'accountCrumbs', body).call(
		{ ownRouteCrumbLabel, homeAndHereCrumbs, ...self },
		({ fromMenu, fromRoute }) => fromMenu || fromRoute,
		() => '',
		(crumbs) => crumbs,
		() => [],
		() => [],
	)
}

const shell = {
	route: '/deze-pagina-bestaat-niet',
	t: (s) => s,
	hrefForRoute: (r) => r,
	menus: [],
	site: {},
	page: null,
	subject: null,
}

test('a 404 has no breadcrumb', () => {
	assert.deepEqual(breadcrumbsOf({ ...shell, error: { status: 404 } }), [])
})

test('a shared dossier trails home and its own title', () => {
	const crumbs = breadcrumbsOf({
		...shell,
		route: '/gedeeld/abc',
		error: null,
		sharedDossierRoute: true,
		sharedDossierTitle: 'Bouwdossier',
	})
	assert.deepEqual(crumbs, [
		{ route: '/', label: 'Home', href: '/' },
		{ route: '/gedeeld/abc', label: 'Bouwdossier', href: '/gedeeld/abc' },
	])
})

test('a document page trails home and the document, "Document" until it loads', () => {
	const route = { ...shell, route: '/document/p1/d1', error: null, documentRoute: { publicationId: 'p1', documentId: 'd1' } }
	assert.deepEqual(breadcrumbsOf(route).map((c) => c.label), ['Home', 'Document'])
	assert.deepEqual(breadcrumbsOf({ ...route, subject: { title: 'Besluit.pdf' } }).map((c) => c.label), ['Home', 'Besluit.pdf'])
})

test('a page that is there keeps its trail', () => {
	const crumbs = breadcrumbsOf({ ...shell, route: '/afval', error: null, page: { title: 'Afval' } })
	assert.deepEqual(crumbs.map((c) => c.label), ['Home', 'Afval'])
})

test('without the trail the page keeps the room the board leaves under the header', () => {
	const page = readFileSync(new URL('../src/site/components/NotFoundPage.vue', import.meta.url), 'utf8')
	const css = page.slice(page.indexOf('<style')).replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ')
	assert.match(css, /\.pq-not-found \{[^}]*padding-block: 3\.6875rem 1rem;/)
})
