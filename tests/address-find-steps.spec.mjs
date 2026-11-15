import assert from 'node:assert/strict'
// When the address block takes the register's street and town, pinned before `find` is split.
import { test } from 'node:test'
import { emptyAddress } from '../src/site/components/forms/address.js'
import { loadSfc } from './support/render-sfc.mjs'

const screen = await loadSfc('src/site/components/forms/AddressNL.vue')
const BLOCK = { ...emptyAddress(), postcode: '1234 AB', number: '12', letter: 'a', addition: '2' }

function block(state, answer, during = () => {}) {
	const emitted = []
	const asked = []
	const vm = { lookup: true, base: '/api', block: { ...BLOCK }, touched: { street: false, town: false }, foundNote: false, $emit: (name, value) => emitted.push([name, value]), ...state }
	globalThis.window = globalThis
	globalThis.fetch = async (url) => {
		asked.push(String(url))
		during(vm)
		return answer
	}
	return { vm, emitted, asked, find: () => screen.methods.find.call(vm) }
}
const found = { ok: true, json: async () => ({ street: 'Lindelaan', town: 'Zuiddrecht' }) }

test('a whole address with the lookup on takes the register\'s street and town', async () => {
	const { vm, emitted, asked, find } = block({}, found)
	await find()
	assert.equal(asked.length, 1)
	assert.match(asked[0], /postcode=1234/)
	assert.deepEqual(emitted.map(([name, value]) => [name, value.street, value.town]), [['update:modelValue', 'Lindelaan', 'Zuiddrecht']])
	assert.equal(vm.foundNote, true)
})

test('the lookup off, or an address that is not whole, asks nothing', async () => {
	for (const state of [{ lookup: false }, { block: { ...BLOCK, number: '' } }, { block: { ...BLOCK, postcode: '12' } }]) {
		const { vm, emitted, asked, find } = block(state, found)
		await find()
		assert.deepEqual([asked.length, emitted.length, vm.foundNote], [0, 0, false])
	}
})

test('a miss changes nothing', async () => {
	for (const answer of [{ ok: false }, { ok: true, json: async () => ({}) }]) {
		const { vm, emitted, find } = block({}, answer)
		await find()
		assert.deepEqual([emitted.length, vm.foundNote], [0, false])
	}
})

test('an address changed while the register was asked is left alone', async () => {
	for (const field of ['postcode', 'number', 'letter', 'addition']) {
		const { vm, emitted, find } = block({}, found, (self) => { self.block = { ...self.block, [field]: `${self.block[field]}x` } })
		await find()
		assert.deepEqual([emitted.length, vm.foundNote], [0, false], field)
	}
	const { emitted, find } = block({}, found, (self) => { self.block = { ...self.block, street: 'Eigen' } })
	await find()
	assert.equal(emitted.length, 1, 'a change of the street alone does not hold the answer back')
})
