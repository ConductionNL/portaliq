// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// "Hulp bij deze pagina": a closed disclosure where a text exists, nothing
// where it does not (help-texts-and-form-help).
//
// @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-page-and-each-part-of-mijn-omgeving-can-carry-a-help-text-req-htf-002

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { sectionOf } from '../src/site/lib/help.js'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const PageHelp = await loadSfc('src/site/components/PageHelp.vue', {
	'@conduction/nextcloud-vue': 'export const cnRenderMarkdown = (source) => source\n',
})

globalThis.document = {
	createElement: () => ({ innerHTML: '', content: { querySelectorAll: () => [] } }),
}

test('a text shows a closed disclosure with the summary line', async () => {
	const html = await renderComponent(PageHelp, { text: 'Hier staat wat de gemeente van u nodig heeft.' })

	assert.match(html, /<details[^>]*data-testid="page-help"/)
	assert.doesNotMatch(html, /<details[^>]*\sopen/, 'closed by default')
	assert.match(html, /<summary[^>]*>Hulp bij deze pagina<\/summary>/)
	assert.match(html, /Hier staat wat de gemeente van u nodig heeft\./)
})

test('no text, no control', async () => {
	assert.doesNotMatch(await renderComponent(PageHelp, { text: '' }), /page-help|<details/)
	assert.doesNotMatch(await renderComponent(PageHelp, {}), /page-help|<details/)
})

test('each part of Mijn omgeving finds its own text', () => {
	assert.equal(sectionOf(null, true), 'overview')
	assert.equal(sectionOf({ special: 'tasks' }), 'tasks')
	assert.equal(sectionOf({ special: 'inbox' }), 'messages')
	assert.equal(sectionOf({ special: 'messages' }), 'messages')
	assert.equal(sectionOf({ special: 'cases' }), 'cases')
	assert.equal(sectionOf({ special: 'news' }), '')
	assert.equal(sectionOf(null), '')
})
