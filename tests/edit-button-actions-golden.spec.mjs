import assert from 'node:assert/strict'
// The entries of the site's edit button, pinned before `actions` is split.
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const button = await loadSfc('src/site/components/SiteEditButton.vue')
const actionsFor = (context) => button.computed.actions.call({ context })

test('every entry the context carries, in order', () => {
	assert.deepEqual(actionsFor({ pageId: 'p1', designerUrl: '/d', pagesUrl: '/p', newPageUrl: '/n' }), [
		{ key: 'page', label: 'Deze pagina bewerken', emit: 'edit' },
		{ key: 'designer', label: 'In de beheeromgeving openen', href: '/d' },
		{ key: 'pages', label: 'Alle pagina’s', href: '/p' },
		{ key: 'new', label: 'Nieuwe pagina', href: '/n' },
	])
})

test('a route without a page offers no in-place edit, and empty addresses are left out', () => {
	assert.deepEqual(actionsFor({ pageId: '', designerUrl: '', pagesUrl: '/p', newPageUrl: null }), [
		{ key: 'pages', label: 'Alle pagina’s', href: '/p' },
	])
	assert.deepEqual(actionsFor({ newPageUrl: '/n' }), [{ key: 'new', label: 'Nieuwe pagina', href: '/n' }])
})

test('no context offers nothing', () => {
	assert.deepEqual(actionsFor(null), [])
	assert.deepEqual(actionsFor(undefined), [])
	assert.deepEqual(actionsFor({}), [])
})
