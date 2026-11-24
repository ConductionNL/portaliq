// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The article edit modal: it starts from the article it edits (or blank), only
// saves with a title, sends the fields as the controller reads them, creates
// or updates by whether it edits, and says why when a save is refused
// (marketing-articles-from-pipelinq).
//
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq
// @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity

import assert from 'node:assert/strict'
import { beforeEach, test } from 'node:test'
import { sfcScriptStubbed } from './support/sfc-script-stubbed.mjs'

globalThis.__api = { calls: [], answer: null }
globalThis.__picker = { nodes: null, error: null, built: null }

const Modal = await sfcScriptStubbed('src/modals/ArticleEditModal.vue', {
	'../lib/articlesApi.js': `
const record = (name) => async (...args) => {
	globalThis.__api.calls.push([name, ...args])
	const answer = globalThis.__api.answer
	if (answer instanceof Error) throw answer
	return answer
}
export const createArticle = record('createArticle')
export const updateArticle = record('updateArticle')
`,
	'@nextcloud/dialogs': `
export class FilePickerClosed extends Error {}
globalThis.__FilePickerClosed = FilePickerClosed
export function getFilePickerBuilder(title) {
	const built = { title, calls: [] }
	globalThis.__picker.built = built
	const builder = {}
	for (const name of ['setMultiSelect', 'setMimeTypeFilter', 'allowDirectories', 'addButton']) {
		builder[name] = (value) => { built.calls.push([name, value]); return builder }
	}
	builder.build = () => ({
		pickNodes: async () => {
			if (globalThis.__picker.error) throw globalThis.__picker.error
			return globalThis.__picker.nodes
		},
	})
	return builder
}
`,
})
const FilePickerClosed = globalThis.__FilePickerClosed

const ARTICLE = {
	id: 'a1',
	title: 'OpenRegister 3.0 is uit',
	slug: 'openregister-3-0',
	summary: 'Kort',
	body: '## Nieuw',
	heroImage: '/img/hero.svg',
	language: 'en',
	portalPageRef: 'page-1',
	tags: ['release', 'openregister'],
	links: [{ label: 'Lees meer', url: 'https://example.org' }],
}

/**
 * The modal's `this`: props, data, computed (getter and setter), bound
 * methods, a recording `$emit` and `t` handing the text back.
 *
 * @param {object|null} article The article it edits.
 * @return {object} The instance.
 */
function modal(article = null) {
	const vm = { article, t: (app, text) => text, emitted: [] }
	vm.$emit = (name, value) => vm.emitted.push([name, value])
	for (const [name, spec] of Object.entries(Modal.computed)) {
		const getter = typeof spec === 'function' ? spec : spec.get
		Object.defineProperty(vm, name, {
			get: getter.bind(vm),
			set: spec.set ? spec.set.bind(vm) : undefined,
			enumerable: true,
		})
	}
	Object.assign(vm, Modal.data.call(vm))
	for (const [name, method] of Object.entries(Modal.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

beforeEach(() => {
	globalThis.__api.calls = []
	globalThis.__api.answer = null
	globalThis.__picker = { nodes: null, error: null, built: null }
})

test('a new article starts blank, in Dutch, with no tags or links', () => {
	const vm = modal()
	assert.equal(vm.isEditing, false)
	assert.equal(vm.modalTitle, 'New article')
	assert.deepEqual(vm.model, { title: '', slug: '', summary: '', body: '', heroImage: '', language: 'nl', portalPageRef: '' })
	assert.equal(vm.tagsText, '')
	assert.deepEqual(vm.links, [])
	assert.equal(vm.saving, false)
	assert.equal(vm.error, '')
})

test('an edited article starts from its own fields', () => {
	const vm = modal(ARTICLE)
	assert.equal(vm.isEditing, true)
	assert.equal(vm.modalTitle, 'Edit article')
	assert.deepEqual(vm.model, {
		title: 'OpenRegister 3.0 is uit',
		slug: 'openregister-3-0',
		summary: 'Kort',
		body: '## Nieuw',
		heroImage: '/img/hero.svg',
		language: 'en',
		portalPageRef: 'page-1',
	})
	assert.equal(vm.tagsText, 'release, openregister')
	assert.deepEqual(vm.links, ARTICLE.links)
})

test('editing the links never changes the article it was opened with', () => {
	const vm = modal(ARTICLE)
	vm.links[0].label = 'Anders'
	assert.equal(ARTICLE.links[0].label, 'Lees meer')
})

test('tags or links that are no list start empty', () => {
	const vm = modal({ title: 'T', tags: 'release', links: { url: 'x' } })
	assert.equal(vm.tagsText, '')
	assert.deepEqual(vm.links, [])
})

test('the language choice offers Dutch and English and falls back to Dutch', () => {
	const vm = modal(ARTICLE)
	assert.deepEqual(vm.languageOptions.map((option) => option.value), ['nl', 'en'])
	assert.equal(vm.languageOption, 'en')

	vm.languageOption = 'en'
	assert.equal(vm.model.language, 'en')
	vm.languageOption = null
	assert.equal(vm.model.language, 'nl')
	vm.languageOption = ''
	assert.equal(vm.model.language, 'nl')
})

test('only an article with a title can be saved, and not while it saves', () => {
	const vm = modal()
	assert.equal(vm.canSave, false)
	vm.model.title = '   '
	assert.equal(vm.canSave, false)
	vm.model.title = 'Titel'
	assert.equal(vm.canSave, true)
	vm.saving = true
	assert.equal(vm.canSave, false)
})

test('the payload splits the tags, drops the empty ones and carries the links', () => {
	const vm = modal(ARTICLE)
	vm.tagsText = ' release , ,openregister,  nieuws  ,'
	vm.links.push({ label: 'Tweede', url: 'https://example.org/2' })
	assert.deepEqual(vm.buildPayload(), {
		title: 'OpenRegister 3.0 is uit',
		slug: 'openregister-3-0',
		summary: 'Kort',
		body: '## Nieuw',
		heroImage: '/img/hero.svg',
		language: 'en',
		portalPageRef: 'page-1',
		tags: ['release', 'openregister', 'nieuws'],
		links: [{ label: 'Lees meer', url: 'https://example.org' }, { label: 'Tweede', url: 'https://example.org/2' }],
	})
})

test('a new article is created and the saved one emitted', async () => {
	const vm = modal()
	vm.model.title = 'Nieuw'
	vm.tagsText = 'a'
	globalThis.__api.answer = { id: 'new-1', title: 'Nieuw' }

	await vm.save()

	assert.equal(globalThis.__api.calls.length, 1)
	assert.equal(globalThis.__api.calls[0][0], 'createArticle')
	assert.equal(globalThis.__api.calls[0][1].title, 'Nieuw')
	assert.deepEqual(globalThis.__api.calls[0][1].tags, ['a'])
	assert.deepEqual(vm.emitted, [['saved', { id: 'new-1', title: 'Nieuw' }]])
	assert.equal(vm.saving, false)
	assert.equal(vm.error, '')
})

test('an edited article is updated on its own id', async () => {
	globalThis.__api.answer = { ...ARTICLE, title: 'Anders' }
	const vm = modal(ARTICLE)
	vm.model.title = 'Anders'

	await vm.save()

	assert.equal(globalThis.__api.calls[0][0], 'updateArticle')
	assert.equal(globalThis.__api.calls[0][1], 'a1')
	assert.equal(globalThis.__api.calls[0][2].title, 'Anders')
	assert.deepEqual(vm.emitted, [['saved', { ...ARTICLE, title: 'Anders' }]])
})

test('an article without an id is updated on its uuid, else its @self id', async () => {
	const byUuid = modal({ uuid: 'u-1', title: 'T' })
	await byUuid.save()
	const bySelf = modal({ '@self': { id: 's-1' }, title: 'T' })
	await bySelf.save()

	assert.deepEqual(globalThis.__api.calls.map((call) => [call[0], call[1]]), [['updateArticle', 'u-1'], ['updateArticle', 's-1']])
})

test('nothing is sent without a title', async () => {
	const vm = modal()
	await vm.save()
	assert.deepEqual(globalThis.__api.calls, [])
	assert.deepEqual(vm.emitted, [])
})

test('a refused save shows the server\'s reason and emits nothing', async () => {
	globalThis.__api.answer = Object.assign(new Error('400'), { response: { data: { error: 'slug_taken' } } })
	const vm = modal(ARTICLE)

	await vm.save()

	assert.equal(vm.error, 'slug_taken')
	assert.deepEqual(vm.emitted, [])
	assert.equal(vm.saving, false)
})

test('a save that fails without a reason says the article could not be saved', async () => {
	globalThis.__api.answer = new Error('Network Error')
	const vm = modal(ARTICLE)

	await vm.save()

	assert.equal(vm.error, 'This article could not be saved.')
	assert.equal(vm.saving, false)
})

test('the hero picker offers one image file and fills the hero with its path', async () => {
	globalThis.__picker.nodes = [{ path: '/Marketing/hero.png' }]
	const vm = modal()

	await vm.openHeroPicker()

	assert.equal(vm.model.heroImage, '/Marketing/hero.png')
	assert.equal(globalThis.__picker.built.title, 'Choose a hero image')
	const calls = Object.fromEntries(globalThis.__picker.built.calls.map(([name, value]) => [name, value]))
	assert.equal(calls.setMultiSelect, false)
	assert.deepEqual(calls.setMimeTypeFilter, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'])
	assert.equal(calls.allowDirectories, false)
	assert.equal(calls.addButton.label, 'Choose')
})

test('a single picked node is taken as it comes', async () => {
	globalThis.__picker.nodes = { path: '/hero.webp' }
	const vm = modal()
	await vm.openHeroPicker()
	assert.equal(vm.model.heroImage, '/hero.webp')
})

test('closing the picker keeps the hero and shows no error', async () => {
	globalThis.__picker.error = new FilePickerClosed('closed')
	const vm = modal(ARTICLE)

	await vm.openHeroPicker()

	assert.equal(vm.model.heroImage, '/img/hero.svg')
	assert.equal(vm.error, '')
})

test('a picker that fails says so and keeps the hero', async () => {
	globalThis.__picker.error = new Error('boom')
	const vm = modal(ARTICLE)

	await vm.openHeroPicker()

	assert.equal(vm.model.heroImage, '/img/hero.svg')
	assert.equal(vm.error, 'The file picker could not be opened.')
})

test('picking nothing keeps the hero', async () => {
	globalThis.__picker.nodes = []
	const vm = modal(ARTICLE)
	await vm.openHeroPicker()
	assert.equal(vm.model.heroImage, '/img/hero.svg')
})
