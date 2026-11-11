import assert from 'node:assert/strict'
// What the public catalogue asks for one page, pinned before `reload` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const catalogue = await loadSfc('src/site/widgets/nlCatalogue/NlCatalogue.vue')

function stub(answer) {
	const asked = []
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.window = { location: { origin: 'https://x.example', search: '', hash: '' }, localStorage: { getItem: () => null, setItem() {}, removeItem() {} } }
	globalThis.fetch = async (url) => {
		asked.push(new URL(String(url)))
		if (answer instanceof Error) {
			throw answer
		}
		return { ok: answer.status < 300, status: answer.status, json: async () => answer.body }
	}
	return asked
}

function block(state = {}) {
	return { portal: 'academie', q: 'gas', types: ['learniq:course'], filters: { level: ['1'] }, sortBy: 'date', pageSize: 10, kindFacet: true, audienceFacet: false, lang: 'nl', loading: false, failed: true, result: null, ...state }
}

test('one page is asked with the words, types, filters, sort, a bounded size and the facets', async () => {
	const asked = stub({ status: 200, body: { items: [{ id: 'a' }], total: 1, page: 2, pages: 2, facets: [] } })
	const vm = block()
	await catalogue.methods.reload.call(vm, 2)
	assert.equal(asked.length, 1)
	const params = Object.fromEntries(asked[0].searchParams)
	assert.equal(params.page, '2')
	assert.equal(params.limit, '10')
	assert.equal(vm.failed, false)
	assert.equal(vm.loading, false)
	assert.deepEqual(vm.result.items, [{ id: 'a' }])
	assert.equal(asked[0].toString(), 'https://x.example/api/content/catalogue?portal=academie&search=gas&types=learniq%3Acourse&filters=%7B%22level%22%3A%5B%221%22%5D%7D&sort=date&page=2&limit=10&facetsBy=%7B%22kind%22%3A%22true%22%2C%22news%22%3A%22Nieuws%22%2C%22audience%22%3A%22false%22%7D')
})

test('the page size is kept between 5 and 20, 10 when unusable', async () => {
	for (const [pageSize, limit] of [[1, '5'], [50, '20'], ['x', '10'], [0, '10'], [7, '7']]) {
		const asked = stub({ status: 200, body: { items: [], total: 0, page: 1, pages: 1, facets: [] } })
		await catalogue.methods.reload.call(block({ pageSize }), 1)
		assert.equal(asked[0].searchParams.get('limit'), limit, String(pageSize))
	}
})

test('the facets asked follow the block\'s choices', async () => {
	const seen = []
	for (const facets of [{ kindFacet: true, audienceFacet: true }, { kindFacet: false, audienceFacet: false }, { kindFacet: false, audienceFacet: true, lang: 'en' }]) {
		const asked = stub({ status: 200, body: { items: [], total: 0, page: 1, pages: 1, facets: [] } })
		await catalogue.methods.reload.call(block(facets), 1)
		seen.push(asked[0].search)
	}
	assert.equal(new Set(seen).size, 3, 'each choice asks differently')
})

test('an unavailable catalogue is failed, not empty', async () => {
	for (const answer of [{ status: 500, body: {} }, new Error('offline')]) {
		stub(answer)
		const vm = block({ failed: false })
		await catalogue.methods.reload.call(vm, 1)
		assert.deepEqual([vm.failed, vm.loading, vm.result], [true, false, { items: [], total: 0, page: 1, pages: 1, facets: [] }])
	}
})
