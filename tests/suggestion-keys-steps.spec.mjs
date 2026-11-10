import assert from 'node:assert/strict'
// What each key does in the suggestion list, pinned before `onKey` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const list = await loadSfc('src/site/components/SearchSuggestions.vue')

function listWith(state) {
	const chosen = []
	const vm = { suggestions: ['a', 'b', 'c'], active: -1, closed: false, open: true, choose: (s) => chosen.push(s), ...state }
	return { vm, chosen, press: (key) => list.methods.onKey.call(vm, { key }) }
}

test('Escape closes an open list and is used; on a closed list it is not used', () => {
	const open = listWith({})
	assert.equal(open.press('Escape'), true)
	assert.equal(open.vm.closed, true)
	const shut = listWith({ open: false })
	assert.equal(shut.press('Escape'), false)
	assert.equal(shut.vm.closed, true)
})

test('Tab closes the list and is never used', () => {
	for (const open of [true, false]) {
		const tab = listWith({ open })
		assert.equal(tab.press('Tab'), false)
		assert.equal(tab.vm.closed, true)
	}
})

test('on a closed list no other key is used', () => {
	for (const key of ['Enter', 'ArrowDown', 'ArrowUp', 'Home', 'x']) {
		const shut = listWith({ open: false, active: 1 })
		assert.equal(shut.press(key), false, key)
		assert.deepEqual([shut.vm.active, shut.chosen, shut.vm.closed], [1, [], false])
	}
})

test('Enter without a pick runs the search; with a pick it chooses that suggestion', () => {
	const none = listWith({})
	assert.equal(none.press('Enter'), false)
	assert.deepEqual(none.chosen, [])
	const picked = listWith({ active: 2 })
	assert.equal(picked.press('Enter'), true)
	assert.deepEqual(picked.chosen, ['c'])
})

test('the arrows move the pick, and a key that moves nothing is not used', () => {
	const moving = listWith({})
	assert.equal(moving.press('ArrowDown'), true)
	assert.equal(moving.vm.active, 0)
	assert.equal(moving.press('ArrowDown'), true)
	assert.equal(moving.press('ArrowUp'), true)
	assert.equal(moving.vm.active, 0)
	assert.equal(moving.press('q'), false)
	assert.equal(moving.vm.active, 0)
})
