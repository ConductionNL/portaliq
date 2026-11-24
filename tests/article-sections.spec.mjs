// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The two sections of an article's detail page. The content section reads
// the article (from its prop, its id or the page), shows the chip and the
// moves its status allows, drives each move through its own route and tells
// the page's other widgets; the usage section groups where the article is
// used by app (marketing-articles-from-pipelinq).
//
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used

import assert from 'node:assert/strict'
import { beforeEach, test } from 'node:test'
import { sfcScriptStubbed } from './support/sfc-script-stubbed.mjs'

globalThis.__calls = []
globalThis.__answers = []

const RECORDER = `
const record = (name) => async (...args) => {
	globalThis.__calls.push([name, ...args])
	const answer = globalThis.__answers.shift()
	if (answer instanceof Error) throw answer
	return answer
}
`
const API = RECORDER + `
export const archiveArticle = record('archiveArticle')
export const publishArticle = record('publishArticle')
export const transitionArticle = record('transitionArticle')
export const fetchArticleUsages = record('fetchArticleUsages')
`

const Content = await sfcScriptStubbed('src/components/articles/ArticleContentSection.vue', {
	'@conduction/nextcloud-vue': `
export const cnRenderMarkdown = (source) => '<md>' + source + '</md>'
export const resolveImageUrl = (path) => '/resolved/' + path.slice(4)
export const CnSection = {}
`,
	'@nextcloud/axios': RECORDER + "export default { get: record('axios.get') }\n",
	'@nextcloud/event-bus': RECORDER + "export const emit = record('emit'); export const subscribe = record('subscribe'); export const unsubscribe = record('unsubscribe')\n",
	'@nextcloud/router': "export const generateUrl = (path) => '/index.php' + path\n",
	'../../modals/ArticleEditModal.vue': "export default { name: 'ArticleEditModal' }\n",
	'../../lib/articlesApi.js': API,
})

const Usage = await sfcScriptStubbed('src/components/articles/ArticleUsageSection.vue', {
	'../../lib/articlesApi.js': API,
})

const ARTICLE = { id: 'a1', title: 'Release', status: 'draft', body: '## Nieuw', heroImage: '' }

/**
 * A component's `this`: props, injected context, data, computed getters,
 * bound methods, a recording `$emit` and `t` handing the text back.
 *
 * @param {object} component The component options.
 * @param {object} props The props and injections.
 * @return {object} The instance.
 */
function vm(component, props = {}) {
	const ctx = { article: null, articleId: '', cnSectionContext: null, $route: null, t: (app, text) => text, emitted: [], ...props }
	ctx.$emit = (name, value) => ctx.emitted.push([name, value])
	for (const [name, getter] of Object.entries(component.computed || {})) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx), enumerable: true })
	}
	Object.assign(ctx, component.data.call(ctx))
	for (const [name, method] of Object.entries(component.methods || {})) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * Queue the next answers of the recorded modules.
 *
 * @param {...*} answers The answers, or errors to throw.
 */
function answers(...answers) {
	globalThis.__answers.push(...answers)
}

/**
 * The recorded calls, by name only or in full.
 *
 * @param {boolean} full Whether to keep the arguments.
 * @return {Array} The calls.
 */
function calls(full = true) {
	return full ? globalThis.__calls : globalThis.__calls.map((call) => call[0])
}

beforeEach(() => {
	globalThis.__calls.length = 0
	globalThis.__answers.length = 0
})

test('the article given as a prop wins over the one the section read', () => {
	const section = vm(Content, { article: ARTICLE })
	section.resolvedArticle = { id: 'other', status: 'archived' }
	assert.equal(section.effectiveArticle, ARTICLE)

	const reading = vm(Content)
	reading.resolvedArticle = { id: 'read', status: 'review' }
	assert.equal(reading.effectiveArticle.id, 'read')
})

test('the body is rendered from markdown, an absent one as empty', () => {
	assert.equal(vm(Content, { article: ARTICLE }).renderedBody, '<md>## Nieuw</md>')
	assert.equal(vm(Content).renderedBody, '<md></md>')
})

test('the chip, its label and the moves follow the status', () => {
	const section = vm(Content, { article: { ...ARTICLE, status: 'review' } })
	assert.deepEqual(section.statusChip, { label: 'In review', color: 'var(--color-warning)' })
	assert.equal(section.statusLabel, 'In review')
	assert.deepEqual(section.transitions.map((move) => move.id), ['returnToDraft', 'publish'])

	const unknown = vm(Content, { article: { ...ARTICLE, status: 'scheduled' } })
	assert.equal(unknown.statusLabel, 'Unknown')
	assert.deepEqual(unknown.transitions, [])

	const none = vm(Content)
	assert.equal(none.statusLabel, 'Unknown')
	assert.deepEqual(none.transitions, [])
})

test('every status has its own label', () => {
	const labels = ['draft', 'review', 'published', 'archived'].map((status) => vm(Content, { article: { status } }).statusLabel)
	assert.deepEqual(labels, ['Draft', 'In review', 'Published', 'Archived'])
})

test('the hero image is a web address as given, an app image resolved, a file previewed', () => {
	const hero = (heroImage) => vm(Content, { article: { ...ARTICLE, heroImage } }).heroImageUrl
	assert.equal(hero(''), '')
	assert.equal(hero(undefined), '')
	assert.equal(hero('https://example.org/hero.png'), 'https://example.org/hero.png')
	assert.equal(hero('http://example.org/hero.png'), 'http://example.org/hero.png')
	assert.equal(hero('app:img/hero.svg'), '/resolved/img/hero.svg')
	assert.equal(hero('/Marketing/Hero image.png'), '/index.php/core/preview.png?file=%2FMarketing%2FHero%20image.png&x=1200&y=630&a=1')
	assert.equal(vm(Content).heroImageUrl, '')
})

test('a new hero image clears the failed state', () => {
	const section = vm(Content, { article: ARTICLE })
	section.heroFailed = true
	Content.watch.heroImageUrl.call(section)
	assert.equal(section.heroFailed, false)
})

test('the id comes from the prop, then the article, then the page context, then the route', () => {
	assert.equal(vm(Content, { articleId: 'p1', article: ARTICLE }).effectiveId(), 'p1')
	assert.equal(vm(Content, { article: ARTICLE }).effectiveId(), 'a1')
	assert.equal(vm(Content, { article: { uuid: 'u1' } }).effectiveId(), 'u1')
	assert.equal(vm(Content, { cnSectionContext: { value: { objectId: 'ctx-ref' } } }).effectiveId(), 'ctx-ref')
	assert.equal(vm(Content, { cnSectionContext: { articleId: 'ctx-plain' } }).effectiveId(), 'ctx-plain')
	assert.equal(vm(Content, { $route: { params: { id: 'route-1' } } }).effectiveId(), 'route-1')
	assert.equal(vm(Content).effectiveId(), '')
	assert.equal(vm(Content, { cnSectionContext: { value: null }, $route: { params: {} } }).effectiveId(), '')
})

test('without an article prop the section reads the article by its id', async () => {
	answers({ data: { article: { ...ARTICLE, title: 'Gelezen' } } })
	const section = vm(Content, { articleId: 'a1' })

	await section.load()

	assert.deepEqual(calls(), [['axios.get', '/index.php/apps/portaliq/api/articles/a1']])
	assert.equal(section.resolvedArticle.title, 'Gelezen')
	assert.equal(section.loading, false)
	assert.equal(section.error, '')
})

test('a read without an article leaves none, and a failed read says so', async () => {
	answers({ data: {} }, new Error('404'))
	const section = vm(Content, { articleId: 'a1' })

	await section.load()
	assert.equal(section.resolvedArticle, null)

	await section.load()
	assert.equal(section.error, 'This article could not be loaded.')
	assert.equal(section.loading, false)
})

test('the id watcher reads only when no article was given and an id is known', () => {
	const handler = Content.watch.articleId.handler
	assert.equal(Content.watch.articleId.immediate, true)

	const reads = []
	const withId = vm(Content, { articleId: 'a1' })
	withId.load = () => reads.push('a1')
	handler.call(withId)

	const given = vm(Content, { articleId: 'a1', article: ARTICLE })
	given.load = () => reads.push('given')
	handler.call(given)

	const nothing = vm(Content)
	nothing.load = () => reads.push('nothing')
	handler.call(nothing)

	assert.deepEqual(reads, ['a1'])
})

test('the section listens for page refreshes while it is mounted', () => {
	const section = vm(Content)
	Content.mounted.call(section)
	Content.beforeUnmount.call(section)
	assert.deepEqual(calls(false), ['subscribe', 'unsubscribe'])
	assert.equal(calls()[0][1], 'cn:page:refresh')
	assert.equal(calls()[0][2], section.onPageRefresh)
	assert.equal(calls()[1][2], section.onPageRefresh)
})

test('a refresh tells the page, re-reads a section-held article quietly and tells the other widgets', async () => {
	answers({ data: { article: ARTICLE } })
	const section = vm(Content, { articleId: 'a1' })

	await section.refresh()

	assert.deepEqual(section.emitted, [['refresh', undefined]])
	assert.deepEqual(calls(false), ['axios.get', 'emit'])
	assert.deepEqual(calls()[1], ['emit', 'cn:page:refresh', { source: 'article-content' }])
	assert.equal(section.loading, false)
})

test('a refresh with an article prop leaves the reading to the page', async () => {
	const section = vm(Content, { article: ARTICLE })
	await section.refresh()
	assert.deepEqual(calls(false), ['emit'])
})

test('a page refresh re-reads, unless it came from this section, the article is a prop or there is no id', async () => {
	answers({ data: { article: ARTICLE } })
	const waited = []
	const section = vm(Content, { articleId: 'a1' })

	section.onPageRefresh({ source: 'article-content' })
	vm(Content, { article: ARTICLE }).onPageRefresh({})
	vm(Content).onPageRefresh({})
	assert.deepEqual(calls(), [])

	section.onPageRefresh({ source: 'other', waitUntil: (done) => waited.push(done) })
	await waited[0]
	assert.deepEqual(calls(false), ['axios.get'])
	assert.equal(section.resolvedArticle, ARTICLE)

	answers({ data: { article: ARTICLE } })
	section.onPageRefresh(undefined)
	assert.deepEqual(calls(false), ['axios.get', 'axios.get'])
})

test('a saved edit closes the modal and refreshes', async () => {
	const section = vm(Content, { article: ARTICLE })
	section.showEdit = true
	await section.onSaved()
	assert.equal(section.showEdit, false)
	assert.deepEqual(section.emitted, [['refresh', undefined]])
})

test('each move has its label, an unknown one its own name', () => {
	const section = vm(Content)
	assert.deepEqual(
		['submitForReview', 'publish', 'returnToDraft', 'archive', 'restore', 'teleport'].map((id) => section.actionLabel(id)),
		['Submit for review', 'Publish', 'Return to draft', 'Archive', 'Restore as draft', 'teleport'],
	)
})

test('publish and archive use their own routes; the rest the transition route', async () => {
	const section = vm(Content, { article: ARTICLE })
	for (const move of [
		{ id: 'publish', endpoint: 'publish' },
		{ id: 'archive', endpoint: 'archive' },
		{ id: 'submitForReview', endpoint: 'transition' },
	]) {
		await section.runTransition(move)
	}

	assert.deepEqual(calls().filter((call) => call[0] !== 'emit'), [
		['publishArticle', 'a1'],
		['archiveArticle', 'a1'],
		['transitionArticle', 'a1', 'submitForReview'],
	])
	assert.equal(calls(false).filter((name) => name === 'emit').length, 3, 'each move refreshes the page')
	assert.equal(section.busy, false)
	assert.equal(section.actionError, '')
})

test('a refused move shows the reason and refreshes nothing', async () => {
	answers(Object.assign(new Error('400'), { response: { data: { error: 'transition_not_allowed' } } }))
	const section = vm(Content, { article: ARTICLE })

	await section.runTransition({ id: 'publish', endpoint: 'publish' })

	assert.equal(section.actionError, 'transition_not_allowed')
	assert.deepEqual(calls(false), ['publishArticle'])
	assert.equal(section.busy, false)
})

test('a move that fails without a reason says it could not be applied', async () => {
	answers(new Error('Network Error'))
	const section = vm(Content, { article: ARTICLE })
	await section.runTransition({ id: 'archive', endpoint: 'archive' })
	assert.equal(section.actionError, 'That move could not be applied.')
})

test('without an id no move is sent', async () => {
	const section = vm(Content)
	await section.runTransition({ id: 'publish', endpoint: 'publish' })
	assert.deepEqual(calls(), [])
	assert.equal(section.busy, false)
})

test('the usage section groups the rows by app, portaliq first', async () => {
	answers({ data: [{ id: 'm1', app: 'pipelinq', kind: 'blast' }, { id: 'p1', app: 'portaliq', kind: 'page' }], counts: {} })
	const section = vm(Usage, { articleId: 'a1' })

	await section.load()

	assert.deepEqual(calls(), [['fetchArticleUsages', 'a1']])
	assert.deepEqual(section.groups.map((group) => group.app), ['portaliq', 'pipelinq'])
	assert.equal(section.loading, false)
	assert.equal(section.error, '')
})

test('the usage section takes a bare list as well as the envelope', async () => {
	answers([{ id: 'p1', app: 'portaliq' }], {}, null)
	const section = vm(Usage, { articleId: 'a1' })

	await section.load()
	assert.equal(section.rows.length, 1)
	await section.load()
	assert.deepEqual(section.rows, [])
	await section.load()
	assert.deepEqual(section.rows, [])
})

test('the usage section reads the id from the page and reads nothing without one', async () => {
	answers({ data: [] })
	await vm(Usage, { cnSectionContext: { value: { objectId: 'ctx-1' } } }).load()
	await vm(Usage).load()
	assert.deepEqual(calls(), [['fetchArticleUsages', 'ctx-1']])
	assert.equal(vm(Usage, { $route: { params: { id: 'r1' } } }).effectiveId(), 'r1')
	assert.equal(vm(Usage, { articleId: 'prop', $route: { params: { id: 'r1' } } }).effectiveId(), 'prop')
})

test('a failed usage read says so', async () => {
	answers(new Error('500'))
	const section = vm(Usage, { articleId: 'a1' })
	await section.load()
	assert.equal(section.error, 'Where this article is used could not be loaded.')
	assert.equal(section.loading, false)
})

test('apps and kinds read in words, unknown ones as given', () => {
	const section = vm(Usage)
	assert.deepEqual(['portaliq', 'pipelinq', 'decidiq', ''].map((app) => section.appLabel(app)), ['Website', 'Marketing', 'decidiq', 'Other apps'])
	assert.deepEqual(
		['page', 'campaignTemplate', 'template', 'blast', 'socialPost', 'podcast', undefined].map((kind) => section.kindLabel(kind)),
		['Page', 'Campaign template', 'Campaign template', 'Mailing', 'Social post', 'podcast', ''],
	)
})

test('the usage section reads once it is mounted', () => {
	const section = vm(Usage, { articleId: 'a1' })
	let loads = 0
	section.load = () => loads++
	Usage.mounted.call(section)
	assert.equal(loads, 1)
})
