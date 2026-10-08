// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// "Hulp nodig?" on a form: the answers stay, the form's details override the
// portal's per key, and nothing shows without details (help-texts-and-form-help).
//
// @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-form-offers-help-without-losing-the-answers-req-htf-001

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { hasHelp, mailtoFor, mergeHelp, phoneNoteFor } from '../src/site/lib/help.js'
import { mountSfc } from './support/mount-sfc.mjs'

const FORM = 'src/site/components/FormBlock.vue'

// The form reads the address for its campaign capture; node has no window.
globalThis.window = {
	location: { hash: '', pathname: '/site', search: '' },
	history: { replaceState() {} },
	sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
}

const PORTAL = {
	intro: 'Komt u er niet uit? Wij helpen u graag.',
	phone: '14 020',
	phoneNote: 'Noem dat u hulp nodig heeft bij {formulier}.',
	hours: 'Ma tot vr, 9 tot 17 uur',
	desk: 'Stadskantoor, Lindelaan 1',
	email: 'info@zuiddrecht.example',
}

const fields = [{ id: 'request', label: 'Welke informatie wilt u ontvangen?', type: 'textarea', required: true }]

test('a form key overrides the portal key and the others stay', () => {
	const merged = mergeHelp(PORTAL, { phone: '14 021', hours: '  ' })

	assert.equal(merged.phone, '14 021')
	assert.equal(merged.hours, PORTAL.hours, 'an empty key on the form hides nothing')
	assert.equal(merged.desk, PORTAL.desk)
	assert.deepEqual(mergeHelp(null, null), {})
})

test('no details, no help; an image alone is not help', () => {
	assert.equal(hasHelp({}), false)
	assert.equal(hasHelp({ image: '/a.png' }), false)
	assert.equal(hasHelp({ phone: '14 020' }), true)
})

test('the phone note names the form and the e-mail names it in the subject', () => {
	assert.equal(phoneNoteFor(PORTAL.phoneNote, 'het Woo-verzoek'), 'Noem dat u hulp nodig heeft bij het Woo-verzoek.')
	assert.equal(mailtoFor('info@zuiddrecht.example', 'Woo-verzoek indienen'), 'mailto:info@zuiddrecht.example?subject=Woo-verzoek%20indienen')
	assert.equal(mailtoFor('javascript:alert(1)', 'x'), '')
	assert.equal(mailtoFor('', 'x'), '')
})

test('help halfway the request shows the details, and the typed text is still there after Sluiten', async () => {
	const form = await mountSfc(FORM, { fields, portalHelp: PORTAL, title: 'Woo-verzoek indienen' })

	await form.fire(form.find('form-field-request'), 'input', { value: 'Alle stukken over de brug' })
	await form.fire(form.find('form-help-open'), 'click')

	assert.match(form.textOf(form.find('form-help-phone')), /14 020/)
	assert.match(form.textOf(form.find('form-help-phone')), /Noem dat u hulp nodig heeft bij Woo-verzoek indienen\./)
	assert.match(form.textOf(form.find('form-help-hours')), /9 tot 17/)
	assert.match(form.textOf(form.find('form-help-desk')), /Lindelaan 1/)
	assert.match(form.find('form-help-email').props.href, /^mailto:info@zuiddrecht\.example\?subject=Woo-verzoek/)

	await form.fire(form.find('form-help-close'), 'click')

	assert.equal(form.find('form-help-dialog'), null, 'the dialog is gone')
	assert.equal(form.vm.values.request, 'Alle stukken over de brug', 'the answers stayed')
})

test('a form with its own phone line shows it and the portal\'s other details', async () => {
	const form = await mountSfc(FORM, { fields, portalHelp: PORTAL, formHelp: { phone: '14 021' } })
	await form.fire(form.find('form-help-open'), 'click')

	assert.match(form.textOf(form.find('form-help-phone')), /14 021/)
	assert.doesNotMatch(form.textOf(form.find('form-help-phone')), /14 020/)
	assert.match(form.textOf(form.find('form-help-desk')), /Lindelaan 1/)
})

test('neither portal nor form has details: no "Hulp nodig?"', async () => {
	const form = await mountSfc(FORM, { fields })
	assert.equal(form.find('form-help-open'), null)
	assert.equal(form.find('form-help'), null)
})
