#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// media-library.spec.mjs: the page designer's Media dialog
// (site-page-seo-history-and-media T08, REQ-SPH-004). It lists the page's
// portal's published library items, and picking one stores a media:<id>
// reference (never a copy) as the hero image or the share image, or hands the
// editor a markdown reference. It also pins the wiring: the designer opens the
// dialog, the site renders the hero with its alternative text, and the
// manifest carries the Media pages.
//
// Usage:
//   node --test tests/media-library.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { createMediaLibrary, markdownReference, withMedia } from '../src/lib/mediaLibrary.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const TOWN_HALL = { id: 'm1', title: 'Stadhuis', alt: 'Het stadhuis aan de Markt', kind: 'image', portal: 'gemeente', status: 'published' }

test('the dialog reads the portal\'s published items from OpenRegister', async () => {
	const urls = []
	const library = createMediaLibrary({
		get: async (url) => {
			urls.push(url)
			return { data: { results: [TOWN_HALL, { ...TOWN_HALL, id: 'm2', portal: 'elders' }] } }
		},
	})

	const result = await library.load('gemeente')

	assert.deepEqual(urls, ['/apps/openregister/api/objects/portaliq/media?portal=gemeente&status=published&_limit=200'])
	assert.equal(result.state, 'ready')
	assert.deepEqual(result.items.map((i) => i.id), ['m1'], 'an item of another portal is left out')
})

test('no portal or no items reads as empty, a failure as an error', async () => {
	assert.equal((await createMediaLibrary({ get: async () => ({ data: { results: [] } }) }).load('gemeente')).state, 'empty')
	assert.equal((await createMediaLibrary({ get: async () => ({ data: {} }) }).load('')).state, 'empty')
	assert.equal((await createMediaLibrary({ get: async () => { throw new Error('x') } }).load('gemeente')).state, 'error')
})

test('picking an item stores a reference as the hero or share image, not a copy', () => {
	const page = { title: 'Contact', heroImage: 'https://old.example/a.jpg' }

	assert.equal(withMedia(page, TOWN_HALL, 'hero').heroImage, 'media:m1')
	assert.equal(withMedia(page, TOWN_HALL, 'share').seoImage, 'media:m1')
	assert.equal(page.heroImage, 'https://old.example/a.jpg', 'the loaded page is not mutated')
	assert.throws(() => withMedia(page, { ...TOWN_HALL, kind: 'file' }, 'hero'), /image/)
})

test('a markdown reference carries the alternative text for an image and the title for a file', () => {
	assert.equal(markdownReference(TOWN_HALL), '![Het stadhuis aan de Markt](media:m1)')
	assert.equal(markdownReference({ id: 'm3', title: 'Afvalkalender', kind: 'file' }), '[Afvalkalender](media:m3)')
})

test('the designer opens the Media dialog, the site shows the hero with its alternative text, the manifest has the library', () => {
	const designer = readFileSync(join(ROOT, 'src/views/PageLayoutDesigner.vue'), 'utf8')
	assert.match(designer, /import MediaPickerDialog from '\.\.\/dialogs\/MediaPickerDialog\.vue'/)
	assert.match(designer, /data-testid="designer-media"/)
	assert.match(designer, /withMedia\(this\.page, item, target\)/)

	const site = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	assert.match(site, /:src="page\.hero\.url"\s+:alt="page\.hero\.alt"/)

	const manifest = JSON.parse(readFileSync(join(ROOT, 'src/manifest.json'), 'utf8'))
	const ids = manifest.pages.filter((p) => p.config?.schema === 'media').map((p) => p.id)
	assert.deepEqual(ids, ['Media', 'MediaDetail'])
	assert.ok(manifest.menu.some((m) => m.route === 'Media'))
})
