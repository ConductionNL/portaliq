#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// subjects-image-and-featured.spec.mjs: every branch of a subject's image and
// of the featured subjects (src/site/lib/subjects.js), pinned before they
// were split into named steps.
//
// Usage:
//   node --test tests/subjects-image-and-featured.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { featuredFrom, imageOf } from '../src/site/lib/subjects.js'

test('an image is a bare address or an object with its alternative text', () => {
	assert.deepEqual(imageOf(' https://x/a.png '), { url: 'https://x/a.png', alt: '' })
	assert.deepEqual(imageOf('https://x/a.png', 'Afval'), { url: 'https://x/a.png', alt: 'Afval' })
	assert.deepEqual(imageOf({ url: 'u', alt: ' Een bak ' }, 'Afval'), { url: 'u', alt: 'Een bak' })
	assert.deepEqual(imageOf({ url: 'u', title: 'Titel' }, 'Afval'), { url: 'u', alt: 'Titel' })
	assert.deepEqual(imageOf({ url: 'u', alt: '  ', title: 'Titel' }, 'Afval'), { url: 'u', alt: 'Afval' })
	assert.deepEqual(imageOf({ url: 'u', alt: null, title: 'Titel' }), { url: 'u', alt: 'Titel' })
	assert.deepEqual(imageOf({ downloadUrl: 'd' }), { url: 'd', alt: '' })
	assert.deepEqual(imageOf({ url: null, downloadUrl: 'd' }), { url: 'd', alt: '' })
	assert.equal(imageOf({ url: '', downloadUrl: 'd' }), null, 'an empty url does not fall back')
	assert.equal(imageOf({ url: 5 }), null)
	assert.equal(imageOf(null), null)
	assert.equal(imageOf(undefined), null)
	assert.equal(imageOf(''), null)
	assert.equal(imageOf(42), null)
	assert.deepEqual(imageOf({ url: 'u', alt: 3 }, 'Afval'), { url: 'u', alt: 'Afval' })
	assert.deepEqual(imageOf(['u']), null)
})

const row = (fields) => ({ id: fields.slug, title: fields.slug, featured: true, ...fields })

test('only featured rows with a slug, in featured order then title, at most count', () => {
	const body = {
		results: [
			row({ slug: 'zorg', featuredOrder: 2 }),
			row({ slug: 'afval', featuredOrder: 2 }),
			row({ slug: 'wonen', featuredOrder: 1 }),
			row({ slug: 'werk' }),
			row({ slug: 'niet', featured: false }),
			row({ slug: 'string', featured: 'true' }),
			{ id: 'geen', title: 'Geen slug', featured: true },
			null,
		],
	}
	const slugs = (list) => list.map((subject) => subject.slug)
	assert.deepEqual(slugs(featuredFrom(body)), ['wonen', 'afval', 'zorg', 'werk'])
	assert.deepEqual(slugs(featuredFrom(body, 2)), ['wonen', 'afval'])
	assert.deepEqual(slugs(featuredFrom(body, '3')), ['wonen', 'afval', 'zorg'])
	assert.deepEqual(featuredFrom(body, 0), [])
	assert.deepEqual(featuredFrom(body, -1), [])
	assert.deepEqual(featuredFrom(body, 'x'), [])
	assert.deepEqual(featuredFrom(body, null), [])
	assert.equal(featuredFrom(body, undefined).length, 4)
	assert.deepEqual(featuredFrom(null), [])
	assert.deepEqual(featuredFrom({ results: 'x' }), [])
	assert.deepEqual(featuredFrom({}), [])
})
