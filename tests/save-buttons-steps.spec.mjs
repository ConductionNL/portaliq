import assert from 'node:assert/strict'
// What the two save buttons offer, send and say, pinned before their shared steps are merged.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const MANIFEST = {
	contributions: [
		{ app: 'opencatalogi', actions: [{ id: 'saveSearch' }, { id: 'addToDossier' }], collections: [{ id: 'col', register: 'reg', schema: 'collection' }] },
		{ app: 'other', actions: [{ id: 'elsewhere' }] },
	],
}

const sent = []
let actionAnswer = { status: 200, body: {} }

globalThis.document = { getElementById: () => null, querySelector: () => null, cookie: '', createElement: () => ({ innerHTML: '', content: { querySelectorAll: () => [] } }) }
globalThis.window = {
	location: { origin: 'https://x.example', pathname: '/', search: '', hash: '' },
	localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
}
globalThis.fetch = async (url, init = {}) => {
	const address = String(url)
	let status = 200
	let body = {}
	if (address.endsWith('/contributions')) {
		body = MANIFEST
	} else if (address.includes('/collections/')) {
		body = { objects: [{ id: 'd1', title: 'Mijn dossier' }, { '@self': { id: 'd2' } }, { title: 'zonder id' }] }
	} else {
		sent.push({ address, method: init.method, body: JSON.parse(init.body) })
		status = actionAnswer.status
		body = actionAnswer.body
	}
	return { ok: status < 300, status, json: async () => body, text: async () => JSON.stringify(body), headers: { get: () => null } }
}

const SaveSearch = await loadSfc('src/site/components/SaveSearch.vue')
const SaveToDossier = await loadSfc('src/site/components/SaveToDossier.vue')

function vmOf(component, props) {
	const defaults = {}
	for (const [key, spec] of Object.entries(component.props)) {
		defaults[key] = spec.default
	}
	return { ...defaults, ...component.data.call({}), ...props }
}

async function mounted(component, props) {
	const vm = vmOf(component, props)
	await component.mounted.call(vm)
	return vm
}

test('each button shows only when its app offers its action', async () => {
	assert.equal((await mounted(SaveSearch, { query: {} })).offered, true)
	assert.equal((await mounted(SaveSearch, { query: {}, actionId: 'missing' })).offered, false)
	assert.equal((await mounted(SaveSearch, { query: {}, app: 'other', actionId: 'saveSearch' })).offered, false)
	const dossier = await mounted(SaveToDossier, { publication: 'p1' })
	assert.equal(dossier.offered, true)
	assert.deepEqual(dossier.collection, { id: 'col', register: 'reg', schema: 'collection' })
	assert.equal((await mounted(SaveToDossier, { publication: 'p1', actionId: 'saveSearch', app: 'other' })).offered, false)
})

test('saving a search posts the trimmed name, frequency and query and says what happens next', async () => {
	sent.length = 0
	actionAnswer = { status: 200, body: {} }
	const vm = vmOf(SaveSearch, { query: { text: 'brug' } })
	Object.assign(vm, { open: true, title: ' Brug ', frequency: 'weekly' })
	await SaveSearch.methods.submit.call(vm)
	assert.equal(sent.length, 1)
	assert.match(sent[0].address, /\/actions\/opencatalogi\/saveSearch$/)
	assert.equal(sent[0].method, 'POST')
	assert.deepEqual(sent[0].body, { title: 'Brug', frequency: 'weekly', query: { text: 'brug' } })
	assert.deepEqual([vm.open, vm.busy], [false, false])
	assert.equal(vm.status, 'Zoekopdracht bewaard. U krijgt bericht als er iets nieuws is. Beheer uw zoekopdrachten in Mijn zoekopdrachten.')
})

test('a refused search save keeps the form open and says it did not work', async () => {
	actionAnswer = { status: 500, body: {} }
	const vm = vmOf(SaveSearch, { query: {} })
	vm.open = true
	await SaveSearch.methods.submit.call(vm)
	assert.deepEqual([vm.open, vm.busy], [true, false])
	assert.equal(vm.status, 'Bewaren is niet gelukt. Probeer het later opnieuw.')
})

test('opening the dossier form lists the dossiers with an id and picks the first', async () => {
	const vm = await mounted(SaveToDossier, { publication: 'p1' })
	vm.status = 'old'
	await SaveToDossier.methods.openForm.call(vm)
	assert.deepEqual(vm.dossiers, [{ id: 'd1', title: 'Mijn dossier' }, { id: 'd2', title: 'Zonder titel' }])
	assert.deepEqual([vm.open, vm.status, vm.target], [true, '', 'd1'])
})

test('keeping in a chosen dossier names it, in a new one names the typed title, and a refusal says so', async () => {
	sent.length = 0
	actionAnswer = { status: 200, body: {} }
	const vm = vmOf(SaveToDossier, { publication: 'p1', attachment: 'f9' })
	Object.assign(vm, { open: true, dossiers: [{ id: 'd1', title: 'Mijn dossier' }], target: 'd1' })
	await SaveToDossier.methods.submit.call(vm)
	assert.match(sent[0].address, /\/actions\/opencatalogi\/addToDossier$/)
	assert.deepEqual(sent[0].body, { collection: 'd1', publication: 'p1', attachment: 'f9' })
	assert.deepEqual([vm.open, vm.busy, vm.status], [false, false, 'Bewaard in Mijn dossier.'])

	const fresh = vmOf(SaveToDossier, { publication: 'p2' })
	Object.assign(fresh, { open: true, target: '', title: ' Nieuw ' })
	await SaveToDossier.methods.submit.call(fresh)
	assert.deepEqual(sent[1].body, { title: 'Nieuw', publication: 'p2', attachment: null })
	assert.equal(fresh.status, 'Bewaard in Nieuw.')

	actionAnswer = { status: 403, body: {} }
	const refused = vmOf(SaveToDossier, { publication: 'p3' })
	refused.open = true
	await SaveToDossier.methods.submit.call(refused)
	assert.deepEqual([refused.open, refused.busy, refused.status], [true, false, 'Bewaren is niet gelukt. Probeer het later opnieuw.'])
})
