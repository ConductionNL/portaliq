import assert from 'node:assert/strict'
// What a portal's side card reads beyond the portal record, pinned before `load` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const asked = []
let answer = () => ({ data: {} })
globalThis.__summaryAxios = {
	get: async (url, config) => {
		asked.push(config ? [url, config] : [url])
		return answer(url)
	},
}

const card = await loadSfc('src/widgets/PortalSummaryCard.vue', {
	'@conduction/nextcloud-vue': 'export const CnStatusBadge = {}\n',
	'@nextcloud/axios': 'export default globalThis.__summaryAxios\n',
	'@nextcloud/l10n': 'export const getCanonicalLocale = () => "nl"\nexport const translate = (a, s) => s\nexport const translatePlural = (a, s, p, c) => (c === 1 ? s : p)\n',
	'@nextcloud/router': 'export const generateUrl = (path, vars = {}) => "/nc" + path.replace(/\\{(\\w+)\\}/g, (m, k) => vars[k])\n',
})

async function loaded(state) {
	asked.length = 0
	const vm = { themeName: '', waitingPages: 0, ...state }
	await card.methods.load.call(vm)
	return vm
}

test('without a portal slug nothing is read', async () => {
	const vm = await loaded({ portal: {}, kind: 'theme', themeId: 't1' })
	assert.equal(asked.length, 0)
	assert.equal(vm.themeName, '')
})

test('a theme card names the chosen set, else its id', async () => {
	answer = () => ({ data: { sets: [{ id: 't0', name: 'Rood' }, { id: 't1', name: 'Blauw' }] } })
	const named = await loaded({ portal: { slug: 'gem' }, kind: 'theme', themeId: 't1' })
	assert.deepEqual(asked, [['/nc/apps/portaliq/api/portals/gem/theme']])
	assert.equal(named.themeName, 'Blauw')
	answer = () => ({ data: { sets: [{ id: 't1' }] } })
	assert.equal((await loaded({ portal: { slug: 'gem' }, kind: 'theme', themeId: 't1' })).themeName, 't1')
	answer = () => ({ data: { sets: 'x' } })
	assert.equal((await loaded({ portal: { slug: 'gem' }, kind: 'theme', themeId: 't9' })).themeName, 't9')
	answer = () => ({})
	assert.equal((await loaded({ portal: { slug: 'gem' }, kind: 'theme', themeId: 't8' })).themeName, 't8')
})

test('a theme card without a theme reads nothing', async () => {
	await loaded({ portal: { slug: 'gem' }, kind: 'theme', themeId: '' })
	assert.equal(asked.length, 0)
})

test('a status card counts the pages waiting as draft', async () => {
	answer = () => ({ data: { total: '3' } })
	const vm = await loaded({ portal: { slug: 'gem' }, kind: 'status' })
	assert.deepEqual(asked, [['/nc/apps/openregister/api/objects/portaliq/page', { params: { portal: 'gem', status: 'draft', _limit: 1 } }]])
	assert.equal(vm.waitingPages, 3)
	answer = () => ({ data: {} })
	assert.equal((await loaded({ portal: { slug: 'gem' }, kind: 'status', waitingPages: 7 })).waitingPages, 0)
})

test('another kind reads nothing', async () => {
	await loaded({ portal: { slug: 'gem' }, kind: 'editors' })
	assert.equal(asked.length, 0)
})

test('a failed read keeps a theme name, else shows the theme id', async () => {
	answer = () => { throw new Error('x') }
	assert.equal((await loaded({ portal: { slug: 'gem' }, kind: 'theme', themeId: 't1' })).themeName, 't1')
	assert.equal((await loaded({ portal: { slug: 'gem' }, kind: 'theme', themeId: 't1', themeName: 'Oud' })).themeName, 'Oud')
	const status = await loaded({ portal: { slug: 'gem' }, kind: 'status', themeId: 'tx', waitingPages: 4 })
	assert.deepEqual([status.themeName, status.waitingPages], ['tx', 4])
})
