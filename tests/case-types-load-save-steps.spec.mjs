import assert from 'node:assert/strict'
// How a portal's case types are read and saved, pinned before `load` and `save` are split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const calls = []
let answer = () => ({ data: {} })
globalThis.__caseTypesAxios = {
	get: async (url) => { calls.push(['get', url]); return answer('get') },
	put: async (url, body) => { calls.push(['put', url, body]); return answer('put') },
}
globalThis.t = (app, text) => text

const widget = await loadSfc('src/widgets/PortalCaseTypes.vue', {
	'@nextcloud/axios': 'export default globalThis.__caseTypesAxios\n',
	'@nextcloud/router': 'export const generateUrl = (path, vars = {}) => "/nc" + path.replace(/\\{(\\w+)\\}/g, (m, k) => vars[k])\n',
	'@nextcloud/vue': 'export const NcButton = {}\nexport const NcCheckboxRadioSwitch = {}\nexport const NcLoadingIcon = {}\nexport const NcNoteCard = {}\nexport const NcSelect = {}\nexport const NcTextField = {}\n',
	'@conduction/nextcloud-vue': 'export const CnQrCode = {}\n',
})

function vmOf(state = {}) {
	calls.length = 0
	const vm = { slug: 'gem', loading: false, saving: false, error: 'old', notice: '', columns: null, fields: null, saved: [], rows: [], ...state }
	vm.accept = (caseTypes) => widget.methods.accept.call(vm, caseTypes)
	return vm
}

test('loading reads the portal\'s case types, columns and fields', async () => {
	answer = () => ({ data: { caseTypes: [{ typeId: 'a', shown: true }], columns: { a: ['x'] }, fields: ['f'] } })
	const vm = vmOf()
	await widget.methods.load.call(vm)
	assert.equal(calls.length, 1)
	assert.equal(calls[0][0], 'get')
	assert.deepEqual([vm.rows, vm.saved, vm.columns, vm.fields, vm.error, vm.loading], [[{ typeId: 'a', shown: true }], [{ typeId: 'a', shown: true }], { a: ['x'] }, ['f'], '', false])
	assert.notEqual(vm.rows[0], vm.saved[0], 'the rows and the stored state are copies')
})

test('a load answer without parts reads as empty', async () => {
	answer = () => ({ data: {} })
	const vm = vmOf()
	await widget.methods.load.call(vm)
	assert.deepEqual([vm.rows, vm.columns, vm.fields], [[], {}, []])
})

test('without a portal nothing is read', async () => {
	const vm = vmOf({ slug: '' })
	await widget.methods.load.call(vm)
	assert.deepEqual([calls.length, vm.loading, vm.error], [0, false, ''])
})

test('a failed load says so', async () => {
	answer = () => { throw new Error('x') }
	const vm = vmOf()
	await widget.methods.load.call(vm)
	assert.deepEqual([vm.error, vm.loading], ['The case types of this portal could not be loaded.', false])
})

test('saving sends the hidden list and takes the answer as stored', async () => {
	answer = () => ({ data: { caseTypes: [{ typeId: 'b', shown: false }] } })
	const vm = vmOf({ rows: [{ typeId: 'a', shown: true }, { typeId: 'b', shown: false }] })
	await widget.methods.save.call(vm)
	assert.deepEqual(calls.map((c) => [c[0], c[2]]), [['put', { hiddenCaseTypes: [{ register: '', schema: '', typeId: 'b', label: '' }] }]])
	assert.deepEqual([vm.rows, vm.notice, vm.error, vm.saving], [[{ typeId: 'b', shown: false }], 'Your choices are saved.', '', false])
	answer = () => ({ data: {} })
	const empty = vmOf()
	await widget.methods.save.call(empty)
	assert.deepEqual(empty.rows, [])
})

test('a failed save says so and keeps the rows', async () => {
	answer = () => { throw new Error('x') }
	const vm = vmOf({ rows: [{ typeId: 'a', shown: false }] })
	await widget.methods.save.call(vm)
	assert.deepEqual([vm.error, vm.notice, vm.saving, vm.rows], ['Your choices could not be saved. Try again.', '', false, [{ typeId: 'a', shown: false }]])
})

test('load and save ask the same address', async () => {
	answer = () => ({ data: {} })
	const vm = vmOf()
	await widget.methods.load.call(vm)
	const loaded = calls[0][1]
	await widget.methods.save.call(vm)
	assert.equal(calls[0][1], loaded)
	assert.match(loaded, /gem/)
})
