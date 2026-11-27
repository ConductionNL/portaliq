// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The new-article route: closing the modal goes back to the article list, and
// a saved article opens on its own detail page (marketing-articles-from-pipelinq).
//
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { sfcScriptStubbed } from './support/sfc-script-stubbed.mjs'

const FormView = await sfcScriptStubbed('src/views/articles/ArticleFormView.vue', {
	'../../modals/ArticleEditModal.vue': "export default { name: 'ArticleEditModal' }\n",
})

/**
 * The view's `this`, with a router that records where it went.
 *
 * @return {{vm: object, pushed: Array<object>}} The instance and the pushes.
 */
function view() {
	const pushed = []
	const vm = { $router: { push: (to) => pushed.push(to) } }
	for (const [name, method] of Object.entries(FormView.methods)) {
		vm[name] = method.bind(vm)
	}
	return { vm, pushed }
}

test('the view is nothing but the edit modal', () => {
	assert.deepEqual(Object.keys(FormView.components), ['ArticleEditModal'])
	const template = readFileSync(new URL('../src/views/articles/ArticleFormView.vue', import.meta.url), 'utf8')
	assert.match(template, /<ArticleEditModal @close="onClose" @saved="onSaved" \/>/)
})

test('closing goes back to the article list', () => {
	const { vm, pushed } = view()
	vm.onClose()
	assert.deepEqual(pushed, [{ name: 'Articles' }])
})

test('a saved article opens on its own detail page, by id or else uuid', () => {
	const { vm, pushed } = view()
	vm.onSaved({ id: 'a-1', uuid: 'u-1' })
	vm.onSaved({ uuid: 'u-2' })
	assert.deepEqual(pushed, [
		{ name: 'ArticleDetail', params: { id: 'a-1' } },
		{ name: 'ArticleDetail', params: { id: 'u-2' } },
	])
})

test('a save that answers no article goes back to the list', () => {
	const { vm, pushed } = view()
	vm.onSaved(null)
	vm.onSaved({})
	vm.onSaved({ id: '' })
	assert.deepEqual(pushed, [{ name: 'Articles' }, { name: 'Articles' }, { name: 'Articles' }])
})

test('the routes it pushes are routes the app declares', () => {
	const manifest = readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8')
	for (const name of ['Articles', 'ArticleDetail']) {
		assert.ok(manifest.includes(`"${name}"`), `the manifest declares ${name}`)
	}
})
