import assert from 'node:assert/strict'
// What the Media dialog lists from the library's answer, pinned before `load` is split.
import { test } from 'node:test'
import { createMediaLibrary } from '../src/lib/mediaLibrary.js'

function libraryAnswering(data) {
	const asked = []
	const library = createMediaLibrary({ get: async (path) => { asked.push(path); return { data } } })
	return { library, asked }
}

test('the request asks for the portal\'s published items, at most 200', async () => {
	const { library, asked } = libraryAnswering({ results: [] })
	await library.load('gemeente & co')
	assert.deepEqual(asked, ['/apps/openregister/api/objects/portaliq/media?portal=gemeente+%26+co&status=published&_limit=200'])
})

test('only this portal\'s published items with an id are listed, with their defaults', async () => {
	const { library } = libraryAnswering({
		results: [
			{ id: 1, portal: 'p', status: 'published', title: 'One', alt: 'A', kind: 'image' },
			{ '@self': { id: 'u2' }, portal: 'p', status: 'published' },
			{ id: 0, portal: 'p', status: 'published', title: 'Zero' },
			{ portal: 'p', status: 'published', title: 'No id' },
			{ id: 3, portal: 'other', status: 'published' },
			{ id: 4, portal: 'p', status: 'draft' },
		],
	})
	assert.deepEqual(await library.load('p'), {
		state: 'ready',
		items: [
			{ id: '1', title: 'One', alt: 'A', kind: 'image' },
			{ id: 'u2', title: '', alt: '', kind: 'file' },
			{ id: '0', title: 'Zero', alt: '', kind: 'file' },
		],
	})
})

test('no results list, a list without usable items or no portal reads as empty', async () => {
	assert.deepEqual(await libraryAnswering({ results: 'x' }).library.load('p'), { state: 'empty', items: [] })
	assert.deepEqual(await libraryAnswering(null).library.load('p'), { state: 'empty', items: [] })
	assert.deepEqual(await libraryAnswering({ results: [{ portal: 'p', status: 'published' }] }).library.load('p'), { state: 'empty', items: [] })
	const { library, asked } = libraryAnswering({ results: [] })
	assert.deepEqual(await library.load(''), { state: 'empty', items: [] })
	assert.equal(asked.length, 0)
})

test('a failed read, or an answer without data, reads as an error', async () => {
	assert.deepEqual(await createMediaLibrary({ get: async () => { throw new Error('x') } }).load('p'), { state: 'error', items: [] })
	assert.deepEqual(await createMediaLibrary({ get: async () => undefined }).load('p'), { state: 'error', items: [] })
})
