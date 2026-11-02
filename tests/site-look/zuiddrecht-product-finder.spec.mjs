#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// zuiddrecht-product-finder.spec.mjs: board Productzoeker needs a finder and
// the product pages it chooses from. The Zuiddrecht example set declares the
// parking finder, the site declares its twelve product pages, each with its
// price as the summary, and the parking page links every one of them.
//
// Usage:
//   node --test tests/site-look/zuiddrecht-product-finder.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { planFinder } from '../../src/site/lib/finderPlan.js'

/**
 * A JSON file of the app.
 *
 * @param {string} path The file, from the app root.
 * @return {object} Its content.
 */
function json(path) {
	return JSON.parse(readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8'))
}

const site = json('lib/Settings/sites/zuiddrecht.json')
const set = json('lib/Settings/example-sets/zuiddrecht.json')
const finder = set.objects.find((object) => object.schema === 'portalFinder')?.data
const schema = json('lib/Settings/portaliq_register.json').components.schemas.portalFinder

test('the example set declares a published parking finder for the portal', () => {
	assert.ok(finder, 'a portalFinder object')
	assert.equal(finder.portal, site.portal.slug)
	assert.equal(finder.status, 'published')
	for (const key of schema.required) {
		assert.ok(key in finder, `the finder has ${key}`)
	}
})

test('every product and every ruled-out route is a page of the site with its price', () => {
	const pages = new Map(site.pages.map((page) => [page.route, page]))
	assert.equal(finder.products.length, 12)
	for (const route of finder.products) {
		assert.ok(pages.get(route)?.summary, `${route} is a page with a summary`)
	}
	for (const question of finder.questions) {
		for (const route of [...(question.excludesOnYes || []), ...(question.excludesOnNo || [])]) {
			assert.ok(finder.products.includes(route), `${question.id} rules out ${route}, one of the products`)
		}
	}
})

test('a resident with a car keeps the resident permits and loses the business ones', () => {
	const products = finder.products.map((route) => ({ route, title: route }))
	const plan = planFinder(
		{ products, questions: finder.questions },
		{ 'woont-in-zuiddrecht': 'yes', 'heeft-auto': 'yes' },
	)
	const left = plan.remaining.map((product) => product.route)
	assert.ok(left.includes('/parkeren/parkeervergunning-bewoners'))
	assert.ok(!left.includes('/parkeren/bedrijfsvergunning'))
	assert.ok(!left.includes('/parkeren/fietsenkluis-huren'))
	assert.equal(plan.steps[plan.current].question.id, 'woont-in-binnenstad')
})

test('the parking page links every product, so none is reachable only through the finder', () => {
	const parking = site.pages.find((page) => page.route === '/parkeren')
	const linked = parking.body.widgets.flatMap((widget) =>
		(widget.props.links || []).map((link) => link.href),
	)
	for (const route of finder.products) {
		assert.ok(linked.includes(route), route)
	}
})
