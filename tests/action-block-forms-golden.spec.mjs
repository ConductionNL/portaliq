// What an action block draws for each kind of form, pinned before its form branches are merged.
import { test } from 'node:test'
import { golden } from './support/golden.mjs'
import { inState } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ActionBlock = await loadSfc('src/site/components/c/ActionBlock.vue')
const pinned = golden('action-block-forms')
const t = (key) => key

const create = { id: 'createNote', type: 'create', label: 'Notitie maken', fields: ['subject'], fieldConfigs: { subject: { label: 'Onderwerp' } } }
const update = { id: 'editNote', type: 'update', label: '', fields: ['subject'] }
const bezwaar = {
	id: 'createBezwaar', type: 'create', label: 'Bezwaar maken', recordField: 'againstCaseId',
	fields: ['subject', 'againstCaseId'], fieldConfigs: { subject: { label: 'Waarover' }, againstCaseId: { label: 'Zaak' } },
}
const forward = {
	id: 'approve', type: 'endpoint-forward', label: 'Uren goedkeuren', endpoint: '/apps/x/api/approve', method: 'POST',
	fields: ['note'], fieldConfigs: { note: { label: 'Waarom' } }, submitLabel: 'Keur goed',
}

const CASES = {
	'action-create': { block: { type: 'action', action: 'createNote' }, action: create },
	'action-create-headless': { block: { type: 'action', action: 'createNote', headless: true, heading: false }, action: create },
	'action-update-unlabelled': { block: { type: 'action', action: 'editNote' }, action: update },
	'action-endpoint': { block: { type: 'action', action: 'approve' }, action: forward },
	'cta-record': { block: { type: 'cta', action: 'createBezwaar', label: 'Maak bezwaar', withRecord: true, record: 'case-1' }, action: bezwaar },
	'cta-record-unlabelled': { block: { type: 'cta', action: 'createBezwaar', withRecord: true, record: 'case-1' }, action: bezwaar },
	'cta-endpoint': { block: { type: 'cta', action: 'approve', label: 'Goedkeuren' }, action: forward },
	'cta-create': { block: { type: 'cta', action: 'createNote', label: 'Nieuwe notitie' }, action: create },
}

for (const [name, props] of Object.entries(CASES)) {
	test(`${name}: closed and open`, async () => {
		const base = { ...props, api: {}, t, portalHelp: { intro: 'Help bij dit formulier', phone: '14 030' } }
		pinned.check(`${name}:closed`, await renderComponent(inState(ActionBlock, { open: false }), base))
		pinned.check(`${name}:open`, await renderComponent(inState(ActionBlock, { open: true }), base))
	})
}

test('save the golden', () => pinned.save())
