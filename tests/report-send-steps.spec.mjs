import assert from 'node:assert/strict'
// What the report form sends and shows on each answer, pinned before `send` is split.
import { test } from 'node:test'
import { reportWords } from '../src/site/lib/reportApi.js'
import { loadSfc } from './support/render-sfc.mjs'

const FORM = 'src/site/components/ReportBlock.vue'

async function formWith(answer, fields = {}) {
	const block = await loadSfc(FORM)
	const filed = []
	const vm = {
		...block.data(),
		words: reportWords('en'),
		caseType: 'c', register: 'r', schema: 's',
		api: { file: async (filing) => { filed.push(filing); return answer } },
		...fields,
	}
	await block.methods.send.call(vm)
	return { vm, filed }
}

test('a blank body is refused and nothing is sent', async () => {
	const { vm, filed } = await formWith({ ok: true, code: 'X' }, { body: '   ' })
	assert.equal(filed.length, 0)
	assert.equal(vm.problem, reportWords('en').empty)
	assert.equal(vm.busy, false)
})

test('a blank when-and-where is left out and the name goes along as contact', async () => {
	const { vm, filed } = await formWith({ ok: true, code: 'C1' }, { body: ' b ', whenWhere: '  ', name: 'An', reach: '' , trap: 't' })
	assert.deepEqual(filed[0], { caseType: 'c', register: 'r', schema: 's', report: { body: 'b' }, contact: filed[0].contact, trap: 't' })
	assert.equal(vm.code, 'C1')
	assert.deepEqual([vm.body, vm.whenWhere, vm.name, vm.reach, vm.problem, vm.busy], ['', '', '', '', '', false])
})

test('a failed challenge says so and keeps the form', async () => {
	const { vm } = await formWith({ ok: false, error: 'challenge_failed', code: '' }, { body: 'b', whenWhere: 'w', name: 'n' })
	assert.equal(vm.problem, reportWords('en').challengeFailed)
	assert.deepEqual([vm.body, vm.whenWhere, vm.name, vm.code, vm.busy], ['b', 'w', 'n', '', false])
})

test('any other refusal, or an answer without a code, says it failed', async () => {
	for (const answer of [{ ok: false, error: 'other', code: '' }, { ok: true, error: '', code: '' }, { ok: false, error: '', code: 'Z' }]) {
		const { vm } = await formWith(answer, { body: 'b' })
		assert.equal(vm.problem, reportWords('en').failed)
		assert.equal(vm.code, '')
		assert.equal(vm.body, 'b')
	}
})

test('an earlier problem is cleared on a good send', async () => {
	const { vm } = await formWith({ ok: true, code: 'K' }, { body: 'b', problem: 'old' })
	assert.equal(vm.problem, '')
	assert.equal(vm.code, 'K')
})
