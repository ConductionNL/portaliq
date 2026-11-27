// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The article client: every call goes to the article endpoints of this app,
// with the method and body the controller routes expect, and answers what the
// envelope carries, or the documented empty value when it carries nothing
// (marketing-articles-from-pipelinq).
//
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { beforeEach, test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT = join(ROOT, 'node_modules', '.cache', 'articles-api', String(process.pid))
mkdirSync(OUT, { recursive: true })

// The two Nextcloud modules the client imports, replaced by recorders.
writeFileSync(join(OUT, 'axios.mjs'), `
const calls = globalThis.__articleCalls
const answer = (method) => (url, body) => {
	calls.push(body === undefined ? { method, url } : { method, url, body })
	const next = globalThis.__articleAnswers.shift()
	if (next instanceof Error) {
		return Promise.reject(next)
	}
	return Promise.resolve({ data: next })
}
export default { get: answer('get'), post: answer('post'), patch: answer('patch') }
`)
writeFileSync(join(OUT, 'router.mjs'), "export const generateUrl = (path) => '/index.php' + path\n")

globalThis.__articleCalls = []
globalThis.__articleAnswers = []

const source = readFileSync(join(ROOT, 'src', 'lib', 'articlesApi.js'), 'utf8')
	.replace("from '@nextcloud/axios'", `from '${pathToFileURL(join(OUT, 'axios.mjs')).href}'`)
	.replace("from '@nextcloud/router'", `from '${pathToFileURL(join(OUT, 'router.mjs')).href}'`)
writeFileSync(join(OUT, 'articlesApi.mjs'), source)
const api = await import(pathToFileURL(join(OUT, 'articlesApi.mjs')).href)

const ARTICLE = { id: 'a1', title: 'OpenRegister 3.0 is uit', status: 'draft' }

beforeEach(() => {
	globalThis.__articleCalls.length = 0
	globalThis.__articleAnswers.length = 0
})

/**
 * Queue the next answers the fake axios gives.
 *
 * @param {...*} answers The response bodies, or errors to reject with.
 */
function answers(...answers) {
	globalThis.__articleAnswers.push(...answers)
}

/**
 * The calls the client made.
 *
 * @return {Array<object>} The calls.
 */
function calls() {
	return globalThis.__articleCalls
}

test('the list reads one page of articles with the page size in the query', async () => {
	answers({ data: [ARTICLE] })
	assert.deepEqual(await api.fetchArticles(25), [ARTICLE])
	assert.deepEqual(calls(), [{ method: 'get', url: '/index.php/apps/portaliq/api/articles?limit=25' }])
})

test('the list asks for a hundred articles unless told otherwise', async () => {
	answers({ data: [] })
	await api.fetchArticles()
	assert.equal(calls()[0].url, '/index.php/apps/portaliq/api/articles?limit=100')
})

test('the page size is encoded, so it cannot add a parameter of its own', async () => {
	answers({ data: [] })
	await api.fetchArticles('5&admin=1')
	assert.equal(calls()[0].url, '/index.php/apps/portaliq/api/articles?limit=5%26admin%3D1')
})

test('a list answer without data is an empty list', async () => {
	answers({}, null, undefined)
	assert.deepEqual(await api.fetchArticles(), [])
	assert.deepEqual(await api.fetchArticles(), [])
	assert.deepEqual(await api.fetchArticles(), [])
})

test('the usages are read for one article and the envelope is returned whole', async () => {
	const envelope = { data: [{ id: 'u1', app: 'portaliq' }], counts: { portaliq: 1 } }
	answers(envelope)
	assert.deepEqual(await api.fetchArticleUsages('a1'), envelope)
	assert.deepEqual(calls(), [{ method: 'get', url: '/index.php/apps/portaliq/api/articles/a1/usages' }])
})

test('a new article is posted with its fields and the created article answered', async () => {
	answers({ article: ARTICLE })
	const payload = { title: 'OpenRegister 3.0 is uit', markdown: '## Nieuw' }
	assert.deepEqual(await api.createArticle(payload), ARTICLE)
	assert.deepEqual(calls(), [{ method: 'post', url: '/index.php/apps/portaliq/api/articles', body: payload }])
})

test('a change is patched onto the article it names', async () => {
	answers({ article: { ...ARTICLE, title: 'Nieuwe titel' } })
	const payload = { title: 'Nieuwe titel' }
	assert.deepEqual(await api.updateArticle('a1', payload), { ...ARTICLE, title: 'Nieuwe titel' })
	assert.deepEqual(calls(), [{ method: 'patch', url: '/index.php/apps/portaliq/api/articles/a1', body: payload }])
})

test('publish and archive have their own routes and send no body', async () => {
	answers({ article: { ...ARTICLE, status: 'published' } }, { article: { ...ARTICLE, status: 'archived' } })
	assert.equal((await api.publishArticle('a1')).status, 'published')
	assert.equal((await api.archiveArticle('a1')).status, 'archived')
	assert.deepEqual(calls(), [
		{ method: 'post', url: '/index.php/apps/portaliq/api/articles/a1/publish' },
		{ method: 'post', url: '/index.php/apps/portaliq/api/articles/a1/archive' },
	])
})

test('the other moves go through the transition route with the move named in the body', async () => {
	answers({ article: { ...ARTICLE, status: 'review' } }, { article: { ...ARTICLE, status: 'draft' } })
	assert.equal((await api.transitionArticle('a1', 'submitForReview')).status, 'review')
	assert.equal((await api.transitionArticle('a1', 'restore')).status, 'draft')
	assert.deepEqual(calls(), [
		{ method: 'post', url: '/index.php/apps/portaliq/api/articles/a1/transition', body: { transition: 'submitForReview' } },
		{ method: 'post', url: '/index.php/apps/portaliq/api/articles/a1/transition', body: { transition: 'restore' } },
	])
})

test('a write answer without an article is null, never the envelope', async () => {
	answers({ message: 'saved' }, {}, null, { data: ARTICLE }, {})
	assert.equal(await api.createArticle({ title: 'x' }), null)
	assert.equal(await api.updateArticle('a1', { title: 'x' }), null)
	assert.equal(await api.publishArticle('a1'), null)
	assert.equal(await api.archiveArticle('a1'), null)
	assert.equal(await api.transitionArticle('a1', 'restore'), null)
})

test('a refused call reaches the caller as the error axios raised', async () => {
	const refusal = Object.assign(new Error('Request failed with status code 409'), { response: { status: 409 } })
	answers(refusal, refusal)
	await assert.rejects(api.publishArticle('a1'), refusal)
	await assert.rejects(api.fetchArticles(), refusal)
})
