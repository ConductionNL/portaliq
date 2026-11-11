import assert from 'node:assert/strict'
// How the resident's modals open and close their native dialog, pinned before they share the steps.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const MODALS = ['ContactMessageModal', 'WithdrawCaseConfirm', 'ActionEditModal', 'InviteContactModal', 'PlanStartModal']
const loaded = {}
for (const name of MODALS) {
	loaded[name] = await loadSfc(`src/site/modals/e/${name}.vue`)
}

function refs({ modal = true, open = false, heading = true } = {}) {
	const calls = []
	const dialog = {
		open,
		setAttribute: (key, value) => calls.push(['setAttribute', key, value]),
		close: () => calls.push(['close']),
	}
	if (modal) {
		dialog.showModal = () => calls.push(['showModal'])
	}
	const $refs = { dialog }
	if (heading) {
		$refs.heading = { focus: () => calls.push(['focus']) }
	}
	return { $refs, calls }
}

function opened(name, shape) {
	const { $refs, calls } = refs(shape)
	const vm = { $refs, api: {}, loadHistory: async () => {} }
	const running = loaded[name].mounted.call(vm)
	Promise.resolve(running).catch(() => {})
	return calls
}

for (const name of MODALS) {
	test(`${name} opens as a modal and puts focus on its heading`, () => {
		assert.deepEqual(opened(name), [['showModal'], ['focus']])
	})

	test(`${name} falls back to the open attribute without showModal, and works without a heading`, () => {
		assert.deepEqual(opened(name, { modal: false }), [['setAttribute', 'open', ''], ['focus']])
		assert.deepEqual(opened(name, { heading: false }), [['showModal']])
	})

	test(`${name} closes an open dialog when it goes, and leaves a closed one`, () => {
		const open = refs({ open: true })
		loaded[name].beforeUnmount.call({ $refs: open.$refs })
		assert.deepEqual(open.calls, [['close']])
		const shut = refs({ open: false })
		loaded[name].beforeUnmount.call({ $refs: shut.$refs })
		assert.deepEqual(shut.calls, [])
		loaded[name].beforeUnmount.call({ $refs: {} })
		loaded[name].beforeUnmount.call({ $refs: { dialog: { open: true } } })
	})
}

test('without a dialog ref nothing opens and the heading still takes focus', () => {
	for (const name of MODALS) {
		const calls = []
		const vm = { $refs: { heading: { focus: () => calls.push('focus') } }, api: {}, loadHistory: async () => {} }
		Promise.resolve(loaded[name].mounted.call(vm)).catch(() => {})
		assert.deepEqual(calls, ['focus'], name)
	}
})
