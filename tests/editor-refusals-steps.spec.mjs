import assert from 'node:assert/strict'
// What the pages and menu panels say when a change is refused, pinned before they share the reading.
import { test } from 'node:test'
import { PageConflictError } from '../src/editor/pageSaver.js'
import { loadSfc } from './support/render-sfc.mjs'

const STUBS = {
	'@nextcloud/axios': 'export default { get: async () => ({ data: {} }), post: async () => ({}), put: async () => ({}), delete: async () => ({}) }\n',
	'@nextcloud/l10n': 'export const translate = (a, s) => s\nexport const translatePlural = (a, s, p, c) => (c === 1 ? s : p)\n',
	'@nextcloud/vue': 'export const NcButton = {}\nexport const NcNoteCard = {}\nexport const NcSelect = {}\nexport const NcTextField = {}\nexport const NcDialog = {}\n',
}
const pages = await loadSfc('src/editor/SitePagesPanel.vue', STUBS)
const menu = await loadSfc('src/editor/SiteMenuPanel.vue', STUBS)
const say = (panel, error) => panel.methods.messageFor.call({ t: (app, text) => `${app}:${text}` }, error)

const CASES = {
	conflict: new PageConflictError('2026-11-10'),
	unauthorised: { response: { status: 401 } },
	forbidden: { response: { status: 403 } },
	server: { response: { status: 500 }, message: 'Request failed' },
	offline: new Error('The network is down.'),
	bare: {},
	none: undefined,
}

test('the pages panel', () => {
	assert.deepEqual(Object.fromEntries(Object.entries(CASES).map(([k, e]) => [k, say(pages, e)])), {
		conflict: 'portaliq:Someone else changed this page in the meantime, so nothing was saved. The list now shows their version: try again.',
		unauthorised: 'portaliq:You are not allowed to change the pages of this portal.',
		forbidden: 'portaliq:You are not allowed to change the pages of this portal.',
		server: 'portaliq:That did not work. Nothing was changed.',
		offline: 'portaliq:The network is down.',
		bare: 'portaliq:That did not work. Nothing was changed.',
		none: 'portaliq:That did not work. Nothing was changed.',
	})
})

test('the menu panel', () => {
	assert.deepEqual(Object.fromEntries(Object.entries(CASES).map(([k, e]) => [k, say(menu, e)])), {
		conflict: 'portaliq:Someone else saved this menu after you opened it, so your changes were not saved. Close and reopen the menu to see their version.',
		unauthorised: 'portaliq:You are not allowed to change the menu of this portal.',
		forbidden: 'portaliq:You are not allowed to change the menu of this portal.',
		server: 'portaliq:That did not work. Nothing was changed.',
		offline: 'portaliq:That did not work. Nothing was changed.',
		bare: 'portaliq:That did not work. Nothing was changed.',
		none: 'portaliq:That did not work. Nothing was changed.',
	})
})
