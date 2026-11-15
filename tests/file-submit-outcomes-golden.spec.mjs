import assert from 'node:assert/strict'
// Every outcome of creating a record with file fields, pinned before `submitWithFiles` is split.
import { test } from 'node:test'
import { submitWithFiles } from '../src/shared/fileFieldSubmit.js'

const action = {
	id: 'create',
	fields: ['title', 'cv', 'photo'],
	fieldConfigs: { cv: { type: 'file' }, photo: { type: 'file' } },
}
const file = (name) => ({ name, size: 10 })

function api(created, uploadOk = () => true) {
	const calls = { created: [], uploads: [] }
	return {
		calls,
		createObject: async (act, body) => { calls.created.push(body); return created },
		uploadFieldFile: async (act, id, field, picked) => {
			calls.uploads.push([id, field, picked.name])
			return { ok: uploadOk(picked) }
		},
	}
}

test('a refused create carries its status, errors and invalid fields and uploads nothing', async () => {
	const fake = api({ ok: false, status: 422, errors: { title: 'x' }, invalid: { cv: 'y' } })
	const result = await submitWithFiles(fake, action, { title: 'T', cv: 'C:/fake' }, { cv: [file('a')] })
	assert.deepEqual(result, { ok: false, status: 422, object: null, id: '', failed: [], errors: { title: 'x' }, invalid: { cv: 'y' } })
	assert.deepEqual(fake.calls.created, [{ title: 'T' }])
	assert.deepEqual(fake.calls.uploads, [])
})

test('no answer, or a refusal without details, is status 0 with empty errors', async () => {
	for (const created of [null, undefined, { ok: false }]) {
		const result = await submitWithFiles(api(created), action, {}, {})
		assert.deepEqual(result, { ok: false, status: 0, object: null, id: '', failed: [], errors: {}, invalid: {} })
	}
})

test('a create without picked files answers at once', async () => {
	const fake = api({ ok: true, object: { uuid: 'u1' } })
	const result = await submitWithFiles(fake, action, { title: 'T' }, { cv: [], other: [file('x')] })
	assert.deepEqual(result, { ok: true, object: { uuid: 'u1' }, id: 'u1', failed: [] })
	assert.deepEqual(fake.calls.uploads, [])
})

test('a record without an id fails every picked file, field by field', async () => {
	const fake = api({ ok: true, object: {} })
	const result = await submitWithFiles(fake, action, { title: 'T' }, { photo: [file('p')], cv: [file('c1'), file('c2')] })
	assert.equal(result.ok, true)
	assert.equal(result.id, '')
	assert.deepEqual(result.failed.map(({ field, file: picked }) => [field, picked.name]), [['cv', 'c1'], ['cv', 'c2'], ['photo', 'p']])
	assert.deepEqual(fake.calls.uploads, [])
})

test('files upload in field order and a failed one is named', async () => {
	const fake = api({ ok: true, object: { '@self': { id: 's1' } } }, (picked) => picked.name !== 'c2')
	const result = await submitWithFiles(fake, action, { title: 'T', photo: 'x' }, { photo: [file('p')], cv: [file('c1'), file('c2')] })
	assert.deepEqual(fake.calls.created, [{ title: 'T' }])
	assert.deepEqual(fake.calls.uploads, [['s1', 'cv', 'c1'], ['s1', 'cv', 'c2'], ['s1', 'photo', 'p']])
	assert.deepEqual([result.ok, result.id, result.failed.map((f) => f.file.name)], [true, 's1', ['c2']])
	assert.deepEqual(result.object, { '@self': { id: 's1' } })
})
