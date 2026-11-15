#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// date-boxes-steps.spec.mjs: what one of the three date boxes does with what
// was typed: the digits it keeps and shows, the date it sends and when focus
// moves on, pinned before the box's update was split into named steps.
//
// Usage:
//   node --test tests/date-boxes-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { mountSfc } from './support/mount-sfc.mjs'

const DATE = 'src/site/components/forms/DateInputGroup.vue'

test('a box keeps digits only, shows them, sends the date and moves on only while typing', async () => {
	const group = await mountSfc(DATE, { id: 'van' })
	const box = (suffix) => group.findAll((n) => n.props.id === `van${suffix}`)[0]
	const sent = () => group.emitted['update:modelValue'].at(-1)[0]
	const shown = { value: '' }

	group.vm.update('year', '2026', { target: box('-year'), inputType: 'insertText' })
	await group.flush()
	assert.notEqual(group.focused(), box(''), 'the year box never moves on')

	group.vm.update('day', '1a', { target: shown, inputType: 'insertText' })
	await group.flush()
	assert.equal(shown.value, '1', 'the box shows its digits only')
	assert.equal(group.focused(), box('-month'), 'a separator after a digit moves on')

	shown.value = '05'
	group.vm.update('month', '05', { target: shown, inputType: 'insertFromPaste' })
	await group.flush()
	assert.equal(shown.value, '05')
	assert.equal(group.focused(), box('-year'))
	assert.equal(sent(), '2026-05-01')

	group.vm.update('day', '-', { target: box(''), inputType: 'insertText' })
	await group.flush()
	assert.equal(group.focused(), box('-year'), 'a separator alone does not move on')

	group.vm.update('day', '22', undefined)
	await group.flush()
	assert.equal(sent(), '2026-05-22', 'no event: the date is still sent')

	group.vm.update('day', null, { target: box(''), inputType: 'insertText' })
	await group.flush()
	assert.equal(group.vm.parts.day, '')
})
