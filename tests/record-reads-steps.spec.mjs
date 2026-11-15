import assert from 'node:assert/strict'
// How the steps and the timeline blocks read the open record's answer, pinned before they share the read.
import { test } from 'node:test'
import { instance } from './support/page-instance.mjs'
import { loadSfc } from './support/render-sfc.mjs'

const BLOCKS = {
	steps: { component: await loadSfc('src/site/components/mijn/StepsBlock.vue'), fetch: 'fetchSteps', list: 'steps' },
	timeline: { component: await loadSfc('src/site/components/mijn/TimelineBlock.vue'), fetch: 'fetchTimeline', list: 'entries' },
}

function blockWith(kind, read, props = {}) {
	const calls = []
	const api = { [BLOCKS[kind].fetch]: async (...args) => { calls.push(args); return read(args) } }
	const ctx = instance(BLOCKS[kind].component, { block: {}, collection: { id: 'c' }, record: { id: 'r1' }, api, ...props })
	return { ctx, calls }
}

for (const kind of Object.keys(BLOCKS)) {
	const list = BLOCKS[kind].list

	test(`${kind}: the record's id, its mandate and the collection go to the read, and a good answer is kept`, async () => {
		const answer = { [list]: [{ id: 1 }] }
		const { ctx, calls } = blockWith(kind, () => answer, { record: { '@self': { id: 'r9' }, _mandate: { id: 'm1' } } })
		await ctx.load()
		assert.deepEqual(calls, [[{ id: 'c' }, 'r9', 'm1']])
		assert.deepEqual([ctx.answer, ctx.failed], [answer, false])
	})

	test(`${kind}: the id falls back from id to uuid to @self, and no mandate sends ''`, async () => {
		const { ctx, calls } = blockWith(kind, () => ({ [list]: [] }), { record: { uuid: 'u1' } })
		await ctx.load()
		assert.deepEqual(calls[0].slice(1), ['u1', ''])
	})

	test(`${kind}: without a record or a collection nothing is read and nothing changes`, async () => {
		for (const props of [{ record: null }, { record: {} }, { collection: null }]) {
			const { ctx, calls } = blockWith(kind, () => ({ [list]: [] }), props)
			ctx.answer = { kept: true }
			ctx.failed = true
			await ctx.load()
			assert.equal(calls.length, 0)
			assert.deepEqual([ctx.answer, ctx.failed], [{ kept: true }, true])
		}
	})

	test(`${kind}: a thrown read, no answer or an answer without the list fails`, async () => {
		for (const read of [() => { throw new Error('x') }, () => null, () => ({ [list]: 'x' }), () => ({})]) {
			const { ctx } = blockWith(kind, read)
			ctx.answer = { old: true }
			await ctx.load()
			assert.deepEqual([ctx.answer, ctx.failed], [null, true])
		}
	})

	test(`${kind}: an api without the read fails, and no api fails`, async () => {
		for (const api of [{}, null]) {
			const ctx = instance(BLOCKS[kind].component, { block: {}, collection: { id: 'c' }, record: { id: 'r1' }, api })
			await ctx.load()
			assert.deepEqual([ctx.answer, ctx.failed], [null, true])
		}
	})

	test(`${kind}: an answer for a record that is no longer open is dropped`, async () => {
		const opened = {}
		const { ctx } = blockWith(kind, () => {
			opened.block.record = { id: 'r2' }
			return { [list]: [{ id: 1 }] }
		})
		opened.block = ctx
		ctx.failed = true
		await ctx.load()
		assert.deepEqual([ctx.answer, ctx.failed], [null, false], 'cleared on the start of the read, then left')
	})
}
