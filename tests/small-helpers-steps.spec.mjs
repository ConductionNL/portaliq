import assert from 'node:assert/strict'
// The header person, the cases intros and an action's input fields, pinned before they are split.
import { test } from 'node:test'
import { inputFields } from '../src/shared/actionInput.js'
import { casesIntros } from '../src/shared/collectionIntro.js'
import { personOf } from '../src/site/components/chrome/person.js'

test('the person: name, initials and the organisation line', () => {
	assert.deepEqual(personOf({ displayName: '  Anna de Vries ', organisationName: 'Bakkerij' }), { name: 'Anna de Vries', initials: 'AV', subline: 'Bakkerij' })
	assert.deepEqual(personOf({ name: 'jan' }), { name: 'jan', initials: 'J', subline: '' })
	assert.deepEqual(personOf({ displayName: '', name: 'Piet  Jansen' }), { name: 'Piet  Jansen', initials: 'PJ', subline: '' })
})

test('no person without a name, for a name that is the subject reference, or a number', () => {
	for (const session of [null, undefined, {}, { displayName: '   ' }, { displayName: 'sub-1', subjectRef: 'sub-1' }, { name: '123456789' }]) {
		assert.equal(personOf(session), null, JSON.stringify(session))
	}
	assert.notEqual(personOf({ name: '12a' }), null)
})

test('the cases intros: once each, in order, only of cases collections', () => {
	const contributions = [
		{ collections: [{ kind: 'cases', intro: ' Eerst ' }, { kind: 'documents', intro: 'Nee' }, { kind: 'cases', intro: 'Eerst' }] },
		{ collections: 'x' },
		null,
		{ collections: [{ kind: 'cases', intro: 'Tweede' }, { kind: 'cases', intro: '' }, null, { kind: 'cases', intro: 3 }] },
	]
	assert.deepEqual(casesIntros(contributions), ['Eerst', 'Tweede'])
	assert.deepEqual(casesIntros({ contributions }), ['Eerst', 'Tweede'])
	for (const empty of [null, undefined, {}, { contributions: 'x' }, 'x', []]) {
		assert.deepEqual(casesIntros(empty), [])
	}
})

test('an action\'s input fields leave out the stamped, set and hidden fields', () => {
	const action = {
		fields: ['a', 'subject', 'row', 'fixed', 'hidden', 'shown', 7, 'b'],
		subjectField: 'subject',
		rowField: 'row',
		set: { fixed: 'x' },
		fieldConfigs: { hidden: { visible: false }, shown: { visible: true } },
	}
	assert.deepEqual(inputFields(action), ['a', 'shown', 'b'])
	assert.deepEqual(inputFields({ fields: ['a'], subjectField: 3 }), ['a'])
	for (const none of [null, undefined, {}, { fields: 'a' }]) {
		assert.deepEqual(inputFields(none), [])
	}
})
