// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Articles under Website, after News: an index, a new-article page and an
// article page, every write through ArticleController
// (marketing-articles-from-pipelinq).
//
// @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { chipForStatus, groupUsages, transitionsForStatus } from '../src/lib/articleStatus.js'

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const registry = readFileSync(new URL('../src/registry.js', import.meta.url), 'utf8')
const page = (id) => manifest.pages.find((p) => p.id === id)

test('the menu carries Articles under Website, right after News', () => {
	const ordered = [...manifest.menu].sort((a, b) => a.order - b.order).map((item) => item.id)
	const website = ordered.indexOf('CapWebsite')
	const mine = ordered.indexOf('CapMijn')
	const articles = ordered.indexOf('ArticlesMenu')
	assert.ok(website < articles && articles < mine, 'inside the Website section')
	assert.equal(ordered[articles - 1], 'NewsMenu', 'right after News')
	assert.equal(manifest.menu.find((item) => item.id === 'ArticlesMenu').route, 'Articles')
})

test('the index lists articles with title and status, and never opens the object form', () => {
	const index = page('Articles')
	assert.equal(index.type, 'index')
	assert.equal(index.route, '/articles')
	assert.equal(index.config.schema, 'article')
	assert.deepEqual(index.config.columns.map((c) => c.key), ['title', 'status', 'language', 'publishedAt'])
	assert.equal(index.config.showAdd, false)
	assert.equal(index.config.showEditAction, false)
	assert.deepEqual(index.config.viewSwitch, ['table', 'cards'])
	assert.deepEqual(index.config.headerActions[0], { id: 'new-article', label: 'New article', icon: 'Plus', handler: 'navigate', route: 'ArticleNew' })
})

test('the new and detail pages mount the registered article components', () => {
	assert.equal(page('ArticleNew').component, 'ArticleFormView')
	assert.equal(page('ArticleNew').route, '/articles/new')
	const detail = page('ArticleDetail')
	assert.equal(detail.route, '/articles/:id')
	const types = detail.config.widgets.map((w) => w.type)
	assert.deepEqual(types, ['ArticleContentSection', 'data', 'ArticleUsageSection', 'audit-trail'])
	for (const widget of detail.config.widgets) {
		assert.ok(detail.config.layout.some((cell) => cell.widgetId === widget.id), `${widget.id} is placed`)
	}
	for (const name of ['ArticleContentSection', 'ArticleUsageSection']) {
		assert.match(registry, new RegExp(`\\n\\t${name}: \\{\\n\\t\\tkind: 'widget',\\n\\t\\tcomponent: ${name},`))
	}
	assert.match(registry, /\n\tArticleFormView: \{\n\t\tkind: 'page',\n\t\tcomponent: ArticleFormView,/)
})

test('usages are grouped by the app that reported them, portaliq first', () => {
	const groups = groupUsages([
		{ id: 't-1', title: 'Najaarsmailing', kind: 'campaignTemplate', app: 'pipelinq', url: '/t-1' },
		{ id: 'p1', title: 'Najaarsactie', kind: 'page', app: 'portaliq', url: '/p1' },
		{ id: 'x', title: 'Ander', kind: 'thing', app: 'anderapp' },
		{ title: 'no id' },
		null,
	])
	assert.deepEqual(groups.map((g) => g.app), ['portaliq', 'anderapp', 'pipelinq'])
	assert.deepEqual(groups[0].items.map((i) => i.id), ['p1'])
	assert.deepEqual(groupUsages(undefined), [])
})

test('the lifecycle offers only the declared moves', () => {
	assert.deepEqual(transitionsForStatus('archived').map((t) => t.id), ['restore'])
	assert.deepEqual(transitionsForStatus('published').map((t) => t.id), ['archive'])
	assert.equal(chipForStatus('nonsense').label, 'Unknown')
})
