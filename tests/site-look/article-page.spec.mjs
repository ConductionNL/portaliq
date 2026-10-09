#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// article-page.spec.mjs: a news article reads like the board Artikel. One
// title (the article's), a trail that ends on it and runs through its
// section, the section marked in the menu, the facts as a grey block, and
// Enter in a block's own search field no longer searches for
// "[object Event]" (site-article-page-follows-the-board).
//
// Usage:
//   node --test tests/site-look/article-page.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { menuCurrent } from '../../src/site/lib/menuCurrent.js'
import { blocksOwnHeading } from '../../src/site/lib/pageHeading.js'
import {
	menuRouteOf,
	searchTermOf,
	subjectCrumbs,
	subjectOf,
} from '../../src/site/lib/subjectTrail.js'
import { articleParts } from '../../src/site/widgets/nlNewsArticle/article.js'
import { loadSfc, renderComponent } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const read = (rel) => readFileSync(join(ROOT, rel), 'utf8')

const BODY = [
	'Op woensdag 14 oktober is de boekenmarkt.',
	'- **Wanneer:** woensdag 14 oktober, 12.30 tot 14.00 uur\n- **Waar:** op het grote plein. Bij regen in de hal',
	'Heeft u een vraag?',
].join('\n\n')

const routeTrail = [
	{ route: '/', label: 'Home', href: '/' },
	{ route: '/nieuws', label: 'Nieuws', href: '/nieuws' },
	{ route: '/nieuws/abc', label: 'Nieuws', href: '/nieuws/abc' },
]
const helpers = {
	labelFor: (route) => (route === '/zoeken' ? 'Nieuws' : ''),
	hrefFor: (route) => `?route=${route}`,
}

test('a news article prints its own title, so the page adds no "Nieuws" h1', () => {
	assert.equal(blocksOwnHeading([{ widgetKey: 'nlNewsArticle' }]), true)
	assert.equal(blocksOwnHeading([{ widgetKey: 'nlNewsList' }]), false)
})

test('the trail ends on the article title and runs through its section', () => {
	const subject = subjectOf({
		title: 'De Kinderboekenweek is begonnen',
		section: { route: '/zoeken', label: 'Nieuws en documenten' },
	})
	assert.deepEqual(
		subjectCrumbs(routeTrail, subject, helpers).map((crumb) => crumb.label),
		['Home', 'Nieuws en documenten', 'De Kinderboekenweek is begonnen'],
	)
	assert.equal(
		subjectCrumbs(routeTrail, subject, helpers)[1].href,
		'?route=/zoeken',
	)
	// Without a label the menu's words name the section.
	assert.equal(
		subjectCrumbs(
			routeTrail,
			subjectOf({ title: 'T', section: { route: '/zoeken' } }),
			helpers,
		)[1].label,
		'Nieuws',
	)
	// Without a section only the last crumb changes.
	assert.deepEqual(
		subjectCrumbs(routeTrail, subjectOf({ title: 'T' }), helpers).map(
			(crumb) => crumb.label,
		),
		['Home', 'Nieuws', 'T'],
	)
	// Nothing told keeps the route's trail.
	assert.equal(subjectCrumbs(routeTrail, subjectOf(null), helpers), routeTrail)
	// A section that is not an in-site path is ignored.
	assert.equal(
		subjectOf({ title: 'T', section: { route: 'https://x.test' } }).section,
		null,
	)
})

test('the menu marks the section of the article', () => {
	const subject = subjectOf({ title: 'T', section: { route: '/zoeken' } })
	const route = menuRouteOf('/nieuws/abc', subject)
	assert.equal(menuCurrent('/zoeken', route), 'true')
	assert.equal(menuCurrent('/nieuws', route), undefined)
	assert.equal(menuRouteOf('/nieuws/abc', null), '/nieuws/abc')
})

test('a list of bold labels is a set of facts; any other list stays markdown', () => {
	const parts = articleParts(BODY)
	assert.deepEqual(
		parts.map((part) => part.kind),
		['markdown', 'facts', 'markdown'],
	)
	assert.deepEqual(parts[1].items, [
		{ term: 'Wanneer', value: 'woensdag 14 oktober, 12.30 tot 14.00 uur' },
		{ term: 'Waar', value: 'op het grote plein. Bij regen in de hal' },
	])
	assert.deepEqual(
		articleParts('- een\n- **Twee:** drie').map((part) => part.kind),
		['markdown'],
	)
	assert.deepEqual(articleParts(''), [])
})

test('the article renders its facts as a grey key-value block and tells its subject', async () => {
	const options = await loadSfc(
		'src/site/widgets/nlNewsArticle/NlNewsArticle.vue',
		{
			'@conduction/nextcloud-vue':
				'export const cnRenderMarkdown = (source) => `<p>${source}</p>`\n',
		},
	)
	const item = {
		title: 'De Kinderboekenweek is begonnen',
		publishedAt: '2026-10-02T09:00:00+02:00',
		body: `Lead.\n\n${BODY}`,
	}
	const html = await renderComponent(
		{
			...options,
			// The markdown block is the site's own and tested elsewhere.
			components: { MarkdownBlock: { props: ['source'], render: () => null } },
			data: () => ({ item, state: 'ready' }),
		},
		{ kindLabel: 'Nieuws' },
	)
	assert.match(html, /<dl class="nl-news-article__facts"/)
	assert.match(html, /<dt>Wanneer<\/dt><dd>woensdag 14 oktober/)
	assert.doesNotMatch(html, /<strong>Wanneer:<\/strong>/)
	assert.match(html, /nl-news-article__kind[^>]*>Nieuws</)
	assert.match(
		options.__scopeId
			? ''
			: read('src/site/widgets/nlNewsArticle/NlNewsArticle.vue'),
		/\.nl-news-article__facts \{[^}]*background: var\(\s*--thematiq-surface-color/,
	)

	const told = []
	const self = {
		item,
		state: 'ready',
		sectionHref: '/zoeken',
		sectionLabel: 'Nieuws en documenten',
		$emit: (name, value) => told.push([name, value]),
	}
	options.methods.tellSubject.call(self)
	assert.deepEqual(told, [
		[
			'subject',
			{
				title: 'De Kinderboekenweek is begonnen',
				section: { route: '/zoeken', label: 'Nieuws en documenten' },
			},
		],
	])
	told.length = 0
	options.methods.tellSubject.call({ ...self, state: 'missing', item: null })
	assert.deepEqual(told, [['subject', null]])
})

test('the page listens for the subject and the header menu follows it', () => {
	const app = read('src/site/App.vue')
	assert.match(app, /:currentRoute="menuRoute"/)
	assert.match(app, /@subject="onSubject"/)
	assert.match(app, /return subjectCrumbs\(crumbs, this\.subject,/)
	assert.match(app, /this\.signInNeeded = false\s*this\.subject = null/)
	const grid = read('src/site/components/WidgetGrid.vue')
	assert.equal(
		(grid.match(/@subject="\$emit\('subject', \$event\)"/g) || []).length,
		2,
	)
	assert.equal((grid.match(/@search="forwardSearch"/g) || []).length, 2)
})

test('Enter in a block search field hands on no event as a search term', () => {
	assert.equal(searchTermOf('ouderavond'), 'ouderavond')
	assert.equal(searchTermOf(''), '')
	assert.equal(searchTermOf({ type: 'search', target: {} }), null)
	assert.equal(searchTermOf(undefined), null)
})
