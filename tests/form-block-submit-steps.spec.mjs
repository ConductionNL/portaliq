import assert from 'node:assert/strict'
// What a landing page's form checks, sends and shows, pinned before `submit` is split.
import { test } from 'node:test'

// Vue reads `document` when it loads, so the block is loaded before the stubs.
const { loadSfc } = await import('./support/render-sfc.mjs')
const formBlock = await loadSfc('src/site/components/FormBlock.vue')

function stubSite(stored, answerStatus = 200) {
	const calls = []
	const session = { getItem: (key) => stored[key] ?? null, setItem() {}, removeItem() {} }
	globalThis.document = {
		referrer: 'https://doc.referrer',
		getElementById: (id) => (id === 'portaliq-site-config' ? { textContent: JSON.stringify({ apiBase: '/apps/portaliq/api/content/site' }) } : null),
	}
	globalThis.window = { localStorage: session, sessionStorage: session, location: { origin: 'http://localhost', search: '', hash: '' } }
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url: String(url), body: JSON.parse(init.body) })
		return { ok: answerStatus < 300, status: answerStatus, json: async () => ({ object: { id: 'new' } }) }
	}
	return calls
}

function block(fields, values) {
	const ticks = []
	const focused = []
	const vm = {
		formId: 'f1', portal: 'gemeente', fields, values, errors: {}, submitting: false, status: 'old',
		$refs: { summary: { focus: () => focused.push('summary') } },
		$nextTick: (fn) => { ticks.push(fn) },
	}
	return { vm, ticks, focused }
}

test('a missing required field is named, nothing is sent and the summary takes focus on the next tick', async () => {
	const calls = stubSite({})
	const { vm, ticks, focused } = block([{ id: 'email', label: 'E-mail', required: true, type: 'email' }, { id: 'when', label: 'Wanneer', type: 'date' }], {})
	await formBlock.methods.submit.call(vm)
	assert.deepEqual(Object.keys(vm.errors), ['email'])
	assert.equal(calls.length, 0)
	assert.equal(vm.status, null)
	assert.equal(vm.submitting, false)
	assert.equal(focused.length, 0)
	ticks.forEach((fn) => fn())
	assert.deepEqual(focused, ['summary'])
	vm.$refs = {}
	ticks.forEach((fn) => fn())
})

test('a filled form sends its values with the captured campaign touches and referrer, then clears', async () => {
	const calls = stubSite({
		'portaliq:campaign:gemeente:first': JSON.stringify({ source: 'mail', medium: '' }),
		'portaliq:campaign:gemeente:last': JSON.stringify({ campaign: 'herfst' }),
		'portaliq:campaign:gemeente:referrer': JSON.stringify({ value: 'https://stored.referrer' }),
	})
	const { vm } = block([{ id: 'email', label: 'E-mail', required: true }], { email: 'a@example.nl' })
	await formBlock.methods.submit.call(vm)
	assert.equal(calls.length, 1)
	assert.match(calls[0].url, /\?actionId=submit-f1$/)
	assert.deepEqual(calls[0].body, {
		email: 'a@example.nl',
		utmFirstTouch: { source: 'mail' },
		utmLastTouch: { campaign: 'herfst' },
		referrer: 'https://stored.referrer',
	})
	assert.deepEqual([vm.status, vm.values, vm.submitting, vm.errors], ['success', {}, false, {}])
})

test('a refused submission keeps the values and says it failed', async () => {
	stubSite({}, 500)
	const { vm } = block([{ id: 'email', label: 'E-mail' }], { email: 'a' })
	await formBlock.methods.submit.call(vm)
	assert.deepEqual([vm.status, vm.values, vm.submitting], ['error', { email: 'a' }, false])
})

test('without a stored referrer the document referrer goes along', async () => {
	const calls = stubSite({})
	const { vm } = block([], {})
	await formBlock.methods.submit.call(vm)
	assert.equal(calls[0].body.referrer, 'https://doc.referrer')
})
