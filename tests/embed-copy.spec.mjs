#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// embed-copy.spec.mjs — what the embed frame says when it is not showing a form.
//
// Usage:
//   node --test tests/embed-copy.spec.mjs
//
// 🔴 THE FAILURE THIS CHANGE EXISTS FOR WAS AN EMPTY FRAME. The frame route
// served a correct page, with a correct Content-Security-Policy and correct
// refusals, containing a div nothing mounted into. The route worked; the form
// was never there.
//
// These pin the half that can reproduce that failure one level down: a refusal
// the controller returns and nobody has written copy for, rendering as nothing
// at all. A visitor meeting a blank rectangle on a municipality's website
// cannot tell whether the form is broken, still loading, or not for them.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	EMBED_REFUSALS,
	EMBED_REFUSAL_FALLBACK,
	labelFor,
	refusalSentence,
} from '../src/portal/embedCopy.js'

test('a payload carrying a form produces no refusal', () => {
	assert.equal(refusalSentence({ fields: [{ name: 'postcode' }] }), null)
	assert.equal(refusalSentence({}), null)
	assert.equal(refusalSentence(undefined), null)
})

test('every refusal the controller can return has its own sentence', () => {
	// The reasons PortalEmbedController::refusal() passes, by name.
	for (const reason of ['form_not_found', 'origin_not_allowed', 'form_not_published', 'identified_intake']) {
		const said = refusalSentence({ refused: reason })

		assert.ok(said, `${reason} must say something`)
		assert.notEqual(said, EMBED_REFUSAL_FALLBACK, `${reason} deserves its own words`)
		assert.ok(EMBED_REFUSALS[reason], `${reason} must be in the table`)
	}
})

// 🔴 THE SAME EMPTY-FRAME FAILURE, ONE LEVEL DOWN. A reason added to the
// controller without copy here would otherwise render as nothing.
test('a reason nobody has written copy for still says something', () => {
	const said = refusalSentence({ refused: 'some_reason_added_later' })

	assert.equal(said, EMBED_REFUSAL_FALLBACK)
	assert.notEqual(said.trim(), '')
})

test('a labelled field uses its label', () => {
	assert.equal(labelFor({ name: 'postcode', label: 'Postcode' }), 'Postcode')
})

// An input with no label is one a screen reader announces as nothing at all.
test('an unlabelled field falls back to its name rather than to silence', () => {
	assert.equal(labelFor({ name: 'postcode' }), 'postcode')
	assert.equal(labelFor({ name: 'postcode', label: '   ' }), 'postcode')
})

test('a field with nothing at all still yields a string', () => {
	assert.equal(typeof labelFor({}), 'string')
	assert.equal(typeof labelFor(undefined), 'string')
})
