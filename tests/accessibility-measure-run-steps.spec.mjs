import assert from 'node:assert/strict'
// What one measurement run does from start to stored, pinned before `measure` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const widget = await loadSfc('src/widgets/PortalAccessibility.vue', {
	'@nextcloud/axios': 'export default { get: async () => ({ data: {} }), put: async () => ({ data: {} }) }\n',
	'@nextcloud/router': 'export const generateUrl = (p) => p\n',
	'@nextcloud/vue': 'export const NcButton = {}\nexport const NcCheckboxRadioSwitch = {}\nexport const NcLoadingIcon = {}\nexport const NcNoteCard = {}\nexport const NcTextArea = {}\nexport const NcTextField = {}\n',
})

globalThis.t = (app, text) => text

function widgetWith(stored) {
	const seen = { progress: [], opened: [], ran: [], stored: [], said: [], loads: 0, closed: 0, measuringWhileStoring: null }
	const vm = {
		pages: ['/a', '/b'],
		slug: 'gem',
		objectData: { theme: 'rood' },
		measuring: false,
		set progress(value) { seen.progress.push(value) },
		openInFrame: async (route) => {
			seen.opened.push(route)
			if (route === '/b') {
				throw new Error('The page refused to load in a frame.')
			}
			return { root: route }
		},
		runInFrame: async (root, source) => {
			seen.ran.push([root, typeof source])
			return { violations: [] }
		},
		closeFrame: () => { seen.closed++ },
		api: { store: async (slug, run) => { seen.measuringWhileStoring = vm.measuring; seen.stored.push([slug, run]); return stored } },
		say: (type, text) => seen.said.push([type, text]),
		load: async () => { seen.loads++ },
	}
	return { vm, seen, run: () => widget.methods.measure.call(vm) }
}

test('a stored run measures every page in order, keeps the reasons, says so and reloads', async () => {
	const { vm, seen, run } = widgetWith({ ok: true })
	await run()
	assert.deepEqual(seen.progress, [{ done: 0, total: 2 }, { done: 1, total: 2 }, { done: 2, total: 2 }])
	assert.deepEqual(seen.opened, ['/a', '/b'])
	assert.deepEqual(seen.ran, [[{ root: '/a' }, 'string']])
	assert.equal(seen.closed, 1)
	assert.equal(seen.measuringWhileStoring, true)
	const [[slug, stored]] = seen.stored
	assert.equal(slug, 'gem')
	assert.equal(stored.theme, 'rood')
	assert.equal(typeof stored.axeVersion, 'string')
	assert.ok(stored.axeVersion.length > 0)
	assert.deepEqual(stored.pages.map((p) => [p.url, p.measured, p.reason || '']), [['/a', true, ''], ['/b', false, 'The page refused to load in a frame.']])
	assert.equal(vm.measuring, false)
	assert.deepEqual(seen.said, [['success', 'The measurement is stored. The statement now follows it.']])
	assert.equal(seen.loads, 1)
})

test('a run the server does not store says so and does not reload', async () => {
	const { vm, seen, run } = widgetWith({ ok: false })
	vm.objectData = null
	await run()
	assert.equal(seen.stored[0][1].theme, '')
	assert.equal(vm.measuring, false)
	assert.deepEqual(seen.said, [['error', 'The measurement could not be stored.']])
	assert.equal(seen.loads, 0)
})
