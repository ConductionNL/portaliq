import assert from 'node:assert/strict'
// What the public detail page shows after reading its item, pinned before `load` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const NlPublicDetail = await loadSfc('src/site/widgets/nlPublicDetail/NlPublicDetail.vue', {
	'@conduction/nextcloud-vue': 'export const cnRenderMarkdown = (source) => source\n',
})

const DETAIL = {
	dates: [{ id: 'd1', date: '2026-10-22', places: 7 }, { id: 'd2', date: '2026-11-05', places: 0 }],
	action: { id: 'enrol', countMax: 4 },
}

function memory(entries = {}) {
	const kept = { ...entries }
	return {
		kept,
		getItem: (key) => (key in kept ? kept[key] : null),
		setItem: (key, value) => { kept[key] = value },
		removeItem: (key) => { delete kept[key] },
	}
}

function stubWindow(storage, status, body) {
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.window = {
		location: { origin: 'https://x.example', pathname: '/cursus/f', search: '', hash: '' },
		localStorage: memory(),
		sessionStorage: storage,
	}
	const asked = []
	globalThis.fetch = async (url) => {
		asked.push(String(url))
		if (status === 0) {
			throw new Error('offline')
		}
		return { ok: status < 300, status, json: async () => body }
	}
	return asked
}

async function loaded(props, status, body, storage = memory()) {
	const asked = stubWindow(storage, status, body)
	const vm = { ...NlPublicDetail.data.call({}), app: 'learniq', kind: 'course', portal: 'academie', routeParam: 'f', ...props }
	try {
		await NlPublicDetail.methods.load.call(vm)
	} finally {
		delete globalThis.window
	}
	return { vm, asked, storage }
}

test('a missing route part reads as missing and nothing is read', async () => {
	for (const props of [{ routeParam: '' }, { app: '' }, { kind: '' }]) {
		const { vm, asked } = await loaded(props, 200, {})
		assert.equal(vm.state, 'missing')
		assert.equal(asked.length, 0)
	}
})

test('an item the index does not return reads as missing, and a failed read as failed', async () => {
	assert.equal((await loaded({}, 404, {})).vm.state, 'missing')
	assert.equal((await loaded({}, 500, {})).vm.state, 'failed')
	assert.equal((await loaded({}, 0, {})).vm.state, 'failed')
})

test('a found item is shown, with no kept choice', async () => {
	const { vm, asked } = await loaded({}, 200, { item: { id: 'i' }, detail: DETAIL })
	assert.equal(vm.state, 'ready')
	assert.deepEqual(vm.item, { id: 'i' })
	assert.deepEqual(vm.detail.dates, DETAIL.dates)
	assert.match(asked[0], /slug=f/)
	assert.equal(vm.chosenDate, NlPublicDetail.data.call({}).chosenDate)
	assert.equal(vm.count, NlPublicDetail.data.call({}).count)
})

test('a kept choice comes back once, clamped to the action\'s most and only on a choosable date', async () => {
	const storage = memory({ 'portaliq.detailChoice:/cursus/f': JSON.stringify({ date: 'd1', count: 9 }) })
	const { vm } = await loaded({}, 200, { item: { id: 'i' }, detail: DETAIL }, storage)
	assert.deepEqual([vm.chosenDate, vm.count], ['d1', 4])
	assert.deepEqual(storage.kept, {}, 'the choice is taken, not kept')

	const full = memory({ 'portaliq.detailChoice:/cursus/f': JSON.stringify({ date: 'd2', count: 2 }) })
	const again = await loaded({}, 200, { item: { id: 'i' }, detail: DETAIL }, full)
	assert.deepEqual([again.vm.chosenDate, again.vm.count], ['', 2])
})
