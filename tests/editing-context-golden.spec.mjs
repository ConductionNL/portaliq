import assert from 'node:assert/strict'
// When the renderer offers editing, pinned before `fetchEditingContext` is split.
import { test } from 'node:test'
import { fetchEditingContext } from '../src/site/lib/editorApi.js'

function stub(answer) {
	const calls = []
	globalThis.window = { location: { origin: 'https://portal.example' } }
	globalThis.fetch = async (url, init) => {
		calls.push({ url: String(url), init })
		if (answer instanceof Error) {
			throw answer
		}
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.body }
	}
	return calls
}

test('a caller who may edit gets the context, asked with the route, the portal and the session', async () => {
	const body = { canEdit: true, pageId: 'p1', editorUrl: '/x' }
	const calls = stub({ status: 200, body })
	assert.deepEqual(await fetchEditingContext('/apps/portaliq/api/cms', '/over ons', 'gemeente'), body)
	assert.deepEqual(calls, [{
		url: 'https://portal.example/apps/portaliq/api/cms/editing-context?route=%2Fover+ons&portal=gemeente',
		init: { headers: { Accept: 'application/json' }, credentials: 'include' },
	}])
})

test('no route asks for the home page, and no portal sends none', async () => {
	const calls = stub({ status: 200, body: { canEdit: true } })
	await fetchEditingContext('/api/cms', '')
	assert.equal(calls[0].url, 'https://portal.example/api/cms/editing-context?route=%2F')
})

test('every refusal and every failure is null', async () => {
	const refusals = [
		{ status: 401, body: { canEdit: true } },
		{ status: 403, body: {} },
		{ status: 500, body: { canEdit: true } },
		{ status: 200, body: { canEdit: false } },
		{ status: 200, body: { canEdit: 'true' } },
		{ status: 200, body: { canEdit: 1 } },
		{ status: 200, body: {} },
		{ status: 200, body: null },
		new Error('offline'),
	]
	for (const answer of refusals) {
		stub(answer)
		assert.equal(await fetchEditingContext('/api/cms', '/a', 'p'), null, JSON.stringify(answer))
	}
})

test('a body that does not parse is null', async () => {
	globalThis.window = { location: { origin: 'https://portal.example' } }
	globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => { throw new SyntaxError('bad') } })
	assert.equal(await fetchEditingContext('/api/cms', '/a'), null)
})

test('a base that is not a valid address is null', async () => {
	globalThis.window = { location: { origin: 'not a url' } }
	globalThis.fetch = async () => { throw new Error('never') }
	assert.equal(await fetchEditingContext('/api/cms', '/a'), null)
})
