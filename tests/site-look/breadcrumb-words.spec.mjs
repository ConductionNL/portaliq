#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// breadcrumb-words.spec.mjs: a portal chooses whether the last crumb reads
// the menu's words or the page's title, the sign-in page is named
// "Inloggen", and a lone sign-in card reads as one row
// (site-breadcrumb-follows-the-school-boards).
//
// Usage:
//   node --test tests/site-look/breadcrumb-words.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { crumbLabel, signInCrumbs } from '../../src/site/lib/crumbWords.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const read = (rel) => readFileSync(join(ROOT, rel), 'utf8')
const css = read('css/site-theme.css')
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/\s+/g, ' ')

const zoeken = {
	fromRoute: 'Nieuws en documenten',
	fromMenu: 'Nieuws',
	isLast: true,
	pageTitle: 'Nieuws en documenten',
}

test('a portal that chooses page names the page on screen by its title', () => {
	assert.equal(crumbLabel({ ...zoeken, choice: 'page' }), 'Nieuws en documenten')
	// A crumb above the page keeps the menu's words: its title is not known.
	assert.equal(crumbLabel({ ...zoeken, isLast: false, choice: 'page' }), 'Nieuws')
	// A page without a title falls back to the menu's words.
	assert.equal(crumbLabel({ ...zoeken, pageTitle: '', choice: 'page' }), 'Nieuws')
})

test('every other portal keeps the menu words (Zuiddrecht, "Home › Afval")', () => {
	assert.equal(crumbLabel({ ...zoeken, choice: 'menu' }), 'Nieuws')
	assert.equal(crumbLabel({ ...zoeken, choice: undefined }), 'Nieuws')
	assert.equal(
		crumbLabel({ ...zoeken, fromMenu: '', choice: 'menu' }),
		'Nieuws en documenten',
	)
})

test('the sign-in page reads "Home › Inloggen"', () => {
	const words = { Home: 'Home', 'Log in': 'Inloggen' }
	const crumbs = signInCrumbs(
		'/mijn',
		(key) => words[key],
		(r) => `#${r}`,
	)
	assert.deepEqual(
		crumbs.map((crumb) => [crumb.label, crumb.href]),
		[
			['Home', '#/'],
			['Inloggen', '#/mijn'],
		],
	)
	assert.equal(JSON.parse(read('src/shared/i18n/nl.json'))['Log in'], 'Inloggen')
})

test('the page asks the portal choice and names the signed-out own area as sign-in', () => {
	const app = read('src/site/App.vue')
	assert.match(app, /choice: this\.site\.breadcrumb/)
	assert.match(
		app,
		/if \(!this\.session\) \{\s*return signInCrumbs\(this\.route, this\.t, this\.hrefForRoute\)/,
	)
})

test('a lone sign-in card puts its line beside the mark, under the title', () => {
	assert.match(
		css,
		/\.pq-signin__card:only-child \.pq-signin__card-head > div \{ display: block; \}/,
	)
	assert.match(
		css,
		/\.pq-signin__card:only-child \.pq-signin__card-text\.utrecht-paragraph \{[^}]*--thematiq-website-text-muted/,
	)
})
