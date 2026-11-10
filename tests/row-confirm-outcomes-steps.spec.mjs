import assert from 'node:assert/strict'
// How the row action's confirm step handles each answer, pinned before `confirm` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const step = await loadSfc('src/site/modals/c/RowActionConfirm.vue')

function dialog(result, state = {}) {
	const calls = { forwards: [], navigated: [], emitted: [] }
	const vm = {
		collection: { id: 'col' },
		row: { id: 'r1' },
		action: { id: 'pay', label: 'Betalen', successText: 'Betaald.' },
		api: { forwardRowAction: async (...args) => { calls.forwards.push(args); return result } },
		fileSlot: false,
		files: [{ name: 'a.pdf' }],
		busy: false,
		errors: { old: 'x' },
		message: '',
		link: 'old',
		answers: () => ({ note: 'n' }),
		checkFiles: () => true,
		said: (errors) => ({ said: errors }),
		translate: (key) => `[${key}]`,
		navigate: (url) => calls.navigated.push(url),
		$emit: (name) => calls.emitted.push(name),
		...state,
	}
	return { vm, calls, run: () => step.methods.confirm.call(vm) }
}

test('a file check that fails sends nothing and changes nothing', async () => {
	const { vm, calls, run } = dialog({ ok: true, status: 200, body: {} }, { fileSlot: true, checkFiles: () => false })
	await run()
	assert.deepEqual([calls.forwards.length, vm.busy, vm.errors], [0, false, { old: 'x' }])
})

test('without a file slot the file check is not asked', async () => {
	const { calls, run } = dialog({ ok: true, status: 200, body: {} }, { checkFiles: () => { throw new Error('asked') } })
	await run()
	assert.equal(calls.forwards.length, 1)
})

test('a done answer names the success text, clears the link and says done', async () => {
	const { vm, calls, run } = dialog({ ok: true, status: 200, body: {} })
	await run()
	assert.deepEqual(calls.forwards[0].slice(0, 4), [{ id: 'col' }, 'r1', 'pay', { note: 'n' }])
	assert.deepEqual([vm.busy, vm.errors, vm.message, vm.link, calls.emitted], [false, {}, 'Betaald.', '', ['done']])
})

test('a done answer without a success text says the translated key', async () => {
	const { vm, run } = dialog({ ok: true, status: 200, body: {} }, { action: { id: 'pay' } })
	await run()
	assert.equal(vm.message, '[Done.]')
})

test('the target\'s own sentence and link win', async () => {
	const { vm, run } = dialog({ ok: true, status: 200, body: { message: 'Gedeeld.', link: 'https://x.example/s' } })
	await run()
	assert.deepEqual([vm.message, vm.link], ['Gedeeld.', 'https://x.example/s'])
})

test('a refusal is translated, and one that names inputs shows them and keeps the dialog open', async () => {
	const refused = dialog({ ok: false, status: 500, body: {} })
	await refused.run()
	assert.equal(refused.vm.message.startsWith('['), true)
	assert.deepEqual(refused.calls.emitted, ['done'])

	const named = dialog({ ok: false, status: 422, body: { errors: { note: 'Te kort' } } })
	await named.run()
	assert.equal(named.vm.busy, false)
	assert.deepEqual(named.calls.emitted, [])
	assert.equal(named.vm.message, '')
	assert.ok(named.vm.errors.said, 'the inputs are said')
})

test('a checked redirect navigates and leaves the step busy', async () => {
	const { vm, calls, run } = dialog({ ok: true, status: 200, body: { checkoutUrl: 'https://pay.example/c' } })
	await run()
	assert.deepEqual([calls.navigated, vm.busy, calls.emitted], [['https://pay.example/c'], true, []])
})
