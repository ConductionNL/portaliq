import assert from 'node:assert/strict'
// How the designer writes a page with its version check, pinned before `save` is split.
import { test } from 'node:test'
import { createPageSaver, PageConflictError } from '../src/editor/pageSaver.js'

function saver({ current = '', putError = null } = {}) {
	const calls = []
	const api = createPageSaver({
		get: async (address) => { calls.push(['get', address]); return { data: { '@self': { updated: current } } } },
		put: async (address, payload, config) => {
			calls.push(['put', address, payload, config])
			if (putError) {
				throw putError
			}
		},
		url: (id) => `/page/${id}`,
	})
	return { api, calls }
}

test('without a version the page is written with no check', async () => {
	const { api, calls } = saver({ current: 'other' })
	await api.save('p1', { title: 'T' }, '')
	assert.deepEqual(calls, [['put', '/page/p1', { title: 'T' }, { headers: {} }]])
})

test('an unchanged page is written with If-Match on the version', async () => {
	const { api, calls } = saver({ current: 'v1' })
	await api.save('p1', { title: 'T' }, 'v1')
	assert.deepEqual(calls, [['get', '/page/p1'], ['put', '/page/p1', { title: 'T' }, { headers: { 'If-Match': 'v1' } }]])
})

test('a page without a stored version is written with If-Match', async () => {
	const { api, calls } = saver({ current: '' })
	await api.save('p1', {}, 'v1')
	assert.deepEqual(calls.at(-1), ['put', '/page/p1', {}, { headers: { 'If-Match': 'v1' } }])
})

test('a page someone else saved is refused before anything is written', async () => {
	const { api, calls } = saver({ current: 'v2' })
	await assert.rejects(api.save('p1', {}, 'v1'), (error) => {
		assert.ok(error instanceof PageConflictError)
		assert.equal(error.changedAt, 'v2')
		return true
	})
	assert.deepEqual(calls, [['get', '/page/p1']])
})

test('a 409 on the write is a conflict with the server\'s moment; any other failure passes through', async () => {
	const conflict = { response: { status: 409, data: { currentUpdated: 'v3' } } }
	await assert.rejects(saver({ putError: conflict }).api.save('p1', {}, ''), (error) => error instanceof PageConflictError && error.changedAt === 'v3')
	const bare = { response: { status: 409 } }
	await assert.rejects(saver({ putError: bare }).api.save('p1', {}, ''), (error) => error instanceof PageConflictError && error.changedAt === '')
	const other = new Error('down')
	await assert.rejects(saver({ putError: other }).api.save('p1', {}, ''), (error) => error === other)
	const forbidden = { response: { status: 403 } }
	await assert.rejects(saver({ putError: forbidden }).api.save('p1', {}, ''), (error) => error === forbidden)
})
