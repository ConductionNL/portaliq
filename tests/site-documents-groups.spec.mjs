// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The documents block groups rows under a heading per value, marks new
// documents, shows a status pill, the provider's own line and an authored
// note (documents-grouped-per-record).
//
// @spec openspec/changes/documents-grouped-per-record/specs/site-mijn-omgeving/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { groupDocuments, statusState } from '../src/site/components/mijn/documents.js'
import { inState } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const DocumentsBlock = await loadSfc('src/site/components/mijn/DocumentsBlock.vue')

const ZAKEN = {
	id: 'mijnKinderen',
	register: 'learniq',
	schema: 'pupil',
	kind: 'cases',
	documents: { label: 'Documenten', provider: 'docs' },
}

const ROWS = [
	{ id: 'a', title: 'Rapport Vera', group: 'Groep 7', isNew: true },
	{ id: 'b', title: 'Rapport Sami', group: 'Groep 4' },
	{ id: 'c', title: 'Toestemming foto\'s', group: 'Groep 7', status: 'Ondertekend', statusTone: 'success', meta: 'Door u ingevuld op 28 augustus 2026' },
	{ id: 'd', title: 'Schoolgids' },
]

function render (block) {
  return renderComponent(inState(DocumentsBlock, { answer: { documents: ROWS }, failed: false }), {
		block: { type: 'documents', collection: 'mijnKinderen', ...block },
		collection: ZAKEN,
		record: { id: 'k-1' },
		locale: 'nl',
	})
}

test('rows group under a heading per value, in the provider order', () => {
	const groups = groupDocuments(ROWS, 'group')
	assert.deepEqual(groups.map((g) => g.heading), ['Groep 7', 'Groep 4', ''])
	assert.deepEqual(groups[0].entries.map((e) => e.id), ['a', 'c'])
	assert.equal(groupDocuments(ROWS, '').length, 1)
	assert.deepEqual(groupDocuments(null, 'group'), [])
})

test('the block renders the headings, the new badge, the status pill and the provider line', async () => {
	const html = await render({ groupBy: 'group' })
	assert.equal((html.match(/data-testid="mijn-documents-group"/g) || []).length, 3)
	assert.match(html, /Groep 7[\s\S]*Groep 4/)
	assert.equal((html.match(/data-testid="mijn-file-new"/g) || []).length, 1)
	assert.match(html, /Nieuw/)
	assert.match(html, /data-testid="mijn-file-status"[^>]*>(<!--\[-->)?Ondertekend/)
	assert.match(html, /Door u ingevuld op 28 augustus 2026/)
})

test('without groupBy the rows stay one flat list', async () => {
	const html = await render({})
	assert.equal((html.match(/data-testid="mijn-documents-group"/g) || []).length, 1)
	assert.doesNotMatch(html, /pq-documents-block__group-heading/)
})

test('the note shows under the list', async () => {
	const html = await render({ note: 'Het eerste rapport komt op vrijdag 12 februari 2027.' })
	assert.match(html, /data-testid="mijn-documents-note"[^>]*>\s*Het eerste rapport komt op vrijdag 12 februari 2027\./)
	assert.doesNotMatch(await render({}), /mijn-documents-note/)
})

test('a status tone the badge does not know reads neutral', () => {
	assert.equal(statusState({ statusTone: 'success' }), 'success')
	assert.equal(statusState({ statusTone: 'purple' }), 'neutral')
	assert.equal(statusState({}), 'neutral')
})
