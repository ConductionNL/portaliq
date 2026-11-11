import assert from 'node:assert/strict'
// What each key does inside the edit button's menu, pinned before `onMenuKey` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const button = await loadSfc('src/site/components/SiteEditButton.vue')

function press(key) {
	const calls = []
	const vm = { open: true, close: () => calls.push('close'), move: (step) => calls.push(['move', step]) }
	const event = { key, preventDefault: () => calls.push('prevent') }
	button.methods.onMenuKey.call(vm, event)
	return { calls, open: vm.open }
}

test('Escape closes the menu, the arrows move through it, each without the default', () => {
	assert.deepEqual(press('Escape'), { calls: ['prevent', 'close'], open: true })
	assert.deepEqual(press('ArrowDown'), { calls: ['prevent', ['move', 1]], open: true })
	assert.deepEqual(press('ArrowUp'), { calls: ['prevent', ['move', -1]], open: true })
})

test('Tab leaves the menu closed and keeps its default', () => {
	assert.deepEqual(press('Tab'), { calls: [], open: false })
})

test('any other key does nothing', () => {
	for (const key of ['Enter', ' ', 'Home', 'a', 'constructor', 'toString']) {
		assert.deepEqual(press(key), { calls: [], open: true }, key)
	}
})
