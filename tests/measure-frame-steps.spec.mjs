import assert from 'node:assert/strict'
// How the accessibility measurement waits for a page in its frame, pinned before the watcher is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const widget = await loadSfc('src/widgets/PortalAccessibility.vue', {
	'@nextcloud/axios': 'export default { get: async () => ({ data: {} }), put: async () => ({ data: {} }) }\n',
	'@nextcloud/router': 'export const generateUrl = (p) => p\n',
	'@nextcloud/vue': 'export const NcButton = {}\nexport const NcCheckboxRadioSwitch = {}\nexport const NcLoadingIcon = {}\nexport const NcNoteCard = {}\nexport const NcTextArea = {}\nexport const NcTextField = {}\n',
})

globalThis.t = (app, text) => text

function harness(contentDocument) {
	const timers = []
	const appended = []
	const frame = { attributes: {}, listeners: {}, setAttribute(k, v) { this.attributes[k] = v }, addEventListener(type, fn, options) { this.listeners[type] = { fn, options } } }
	Object.defineProperty(frame, 'contentDocument', { get: contentDocument })
	globalThis.document = { createElement: (tag) => { assert.equal(tag, 'iframe'); return frame }, body: { appendChild: (el) => appended.push(el) } }
	globalThis.setTimeout = (fn, ms) => { timers.push({ fn, ms }) }
	let now = 1000
	Date.now = () => now
	const vm = { siteBase: '/apps/portaliq/site', slug: 'gem', frame: null, closeFrame() { this.closed = (this.closed || 0) + 1 } }
	const opened = widget.methods.openInFrame.call(vm, '/over')
	return { vm, frame, timers, appended, opened, tick: (ms) => { now += ms }, load: () => frame.listeners.load.fn() }
}

test('the frame is hidden, untabbable, titled, addressed and appended once loading is asked', () => {
	const { vm, frame, appended } = harness(() => null)
	assert.equal(vm.closed, 1, 'an earlier frame is closed first')
	assert.equal(vm.frame, frame)
	assert.deepEqual(frame.attributes, { 'aria-hidden': 'true' })
	assert.deepEqual([frame.tabIndex, frame.title, frame.className], [-1, 'Accessibility measurement', 'portal-accessibility__frame'])
	assert.equal(frame.src, '/apps/portaliq/site?portal=gem&route=%2Fover&measure=1')
	assert.deepEqual(appended, [frame])
	assert.deepEqual(frame.listeners.load.options, { once: true })
})

test('a page that refuses the frame rejects with why', async () => {
	const { opened, load } = harness(() => { throw new Error('cross-origin') })
	load()
	await assert.rejects(opened, { message: 'The page refused to load in a frame.' })
	const empty = harness(() => null)
	empty.load()
	await assert.rejects(empty.opened, { message: 'The page refused to load in a frame.' })
})

test('the site root resolves at once, or after the frame is looked at again', async () => {
	const root = { id: 'root' }
	const now = harness(() => ({ querySelector: (sel) => (sel === '[data-testid=site-root]' ? root : null) }))
	now.load()
	assert.equal(await now.opened, root)
	assert.equal(now.timers.length, 0)

	let shown = null
	const later = harness(() => ({ querySelector: () => shown }))
	later.load()
	assert.equal(later.timers.length, 1)
	assert.equal(later.timers[0].ms, 250)
	later.tick(10000)
	shown = root
	later.timers[0].fn()
	assert.equal(await later.opened, root)
})

test('a page that shows no site content within 20 seconds rejects', async () => {
	const slow = harness(() => ({ querySelector: () => null }))
	slow.load()
	slow.tick(20000)
	slow.timers[0].fn()
	assert.equal(slow.timers.length, 2, 'at exactly 20 seconds it still waits')
	slow.tick(1)
	slow.timers[1].fn()
	await assert.rejects(slow.opened, { message: 'The page showed no site content within 20 seconds.' })
	assert.equal(slow.timers.length, 2)
})
