import assert from 'node:assert/strict'
// What the detail card does with a chosen file, pinned before `onUpload` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const component = await loadSfc('src/site/components/collections/DetailCard.vue')

function card(result, rowId = 'r1') {
	const calls = { upload: [], reads: 0, uploads: [] }
	const self = {
		rowId,
		collection: { id: 'c' },
		api: { uploadFile: async (...args) => { calls.upload.push(args); calls.uploads.push({ ...self.upload }); return result } },
		t: (key, vars) => (vars ? `${key}|${JSON.stringify(vars)}` : key),
		upload: { busy: false, message: '' },
		$refs: { upload: { value: 'C:/fake/a.pdf' } },
		readFull: async () => { calls.reads++ },
	}
	return { self, calls }
}

const chosen = (file) => ({ target: { files: file ? [file] : [] } })

test('nothing is sent without a file or a record', async () => {
	for (const [event, rowId] of [[chosen(null), 'r1'], [undefined, 'r1'], [{ target: {} }, 'r1'], [chosen({ name: 'a' }), '']]) {
		const { self, calls } = card({ ok: true }, rowId)
		await component.methods.onUpload.call(self, event)
		assert.equal(calls.upload.length, 0)
		assert.deepEqual(self.upload, { busy: false, message: '' })
		assert.equal(self.$refs.upload.value, 'C:/fake/a.pdf')
	}
})

test('a stored file is named from the answer, the input is cleared and the record read again', async () => {
	const file = { name: 'local.pdf' }
	const { self, calls } = card({ ok: true, file: { name: 'stored.pdf' } })
	await component.methods.onUpload.call(self, chosen(file))
	assert.deepEqual(calls.upload, [[{ id: 'c' }, 'r1', file]])
	assert.deepEqual(calls.uploads, [{ busy: true, message: '' }])
	assert.deepEqual(self.upload, { busy: false, message: 'File added: {name}|{"name":"stored.pdf"}' })
	assert.equal(self.$refs.upload.value, '')
	assert.equal(calls.reads, 1)
})

test('without a stored name the chosen name is used', async () => {
	const { self } = card({ ok: true })
	await component.methods.onUpload.call(self, chosen({ name: 'local.pdf' }))
	assert.equal(self.upload.message, 'File added: {name}|{"name":"local.pdf"}')
})

test('a refused or missing answer says it did not work and reads nothing', async () => {
	for (const result of [{ ok: false }, null, undefined]) {
		const { self, calls } = card(result)
		await component.methods.onUpload.call(self, chosen({ name: 'a.pdf' }))
		assert.deepEqual(self.upload, { busy: false, message: 'The upload did not work.' })
		assert.equal(self.$refs.upload.value, '')
		assert.equal(calls.reads, 0)
	}
})

test('without an input ref the upload still finishes', async () => {
	const { self, calls } = card({ ok: true, file: { name: 's' } })
	self.$refs = {}
	await component.methods.onUpload.call(self, chosen({ name: 'a' }))
	assert.equal(calls.reads, 1)
})
