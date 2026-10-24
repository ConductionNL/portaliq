#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-details.spec.mjs: the "Gegevens van de portal" card is manifest config
// over nextcloud-vue's settings-form and chips-field widgets
// (portal-domains-and-editors REQ-PDE-004). The values below are the portal
// schema's, not the widgets': a wrong enum value is refused by OpenRegister.
//
// Usage:
//   node --test tests/portal-details.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const REGISTER = JSON.parse(read('lib/Settings/portaliq_register.json'))
const PORTAL = REGISTER.components.schemas.portal.properties
const DETAIL = MANIFEST.pages.find((page) => page.id === 'PortalDetail').config

const widget = (id) => DETAIL.widgets.find((w) => w.id === id)
const form = widget('portal-settings-details')
const field = (key) => form.content.fields.find((f) => f.key === key)

test('the details card is a settings-form over fields the portal schema has', () => {
	assert.equal(form.type, 'settings-form')
	for (const f of form.content.fields) {
		assert.ok(PORTAL[f.key], `the portal schema has no "${f.key}"`)
	}
	assert.equal(field('title').required, true)
})

test('the select options are the schema enums, not a placeholder', () => {
	assert.deepEqual(field('kind').options.map((o) => o.value).sort(), [...PORTAL.kind.enum].sort())
	assert.deepEqual(field('headerVariant').options.map((o) => o.value).sort(), [...PORTAL.headerVariant.enum].sort())
})

test('the address accepts lowercase words joined by dashes and nothing else', () => {
	const re = new RegExp(field('slug').pattern)
	for (const ok of ['zuiddrecht', 'open-tilburg', 'a1-b2']) assert.ok(re.test(ok), ok)
	for (const bad of ['Bad Slug', 'UPPER', '-lead', 'trail-', 'a--b', '']) assert.equal(re.test(bad), false, bad)
	assert.ok(field('slug').patternMessage)
})

test('languages and embedding origins are chips in the same form, saved with it', () => {
	const locales = field('locales')
	assert.equal(locales.type, 'chips')
	assert.equal(PORTAL.locales.type, 'array')
	assert.equal(locales.minItems, 1)
	assert.ok(locales.minItemsMessage)
	assert.deepEqual(locales.default, ['nl'])
	// A free code, as the old taggable select allowed, not a fixed list.
	assert.equal(locales.options, undefined)
	const code = new RegExp(locales.pattern)
	for (const ok of ['nl', 'en', 'fy', 'pap', 'nl-be']) assert.ok(code.test(ok), ok)
	for (const bad of ['', 'Nederlands', 'n', 'nl_BE']) assert.equal(code.test(bad), false, bad)
	const frame = field('frameAncestors')
	assert.equal(frame.type, 'chips')
	assert.equal(PORTAL.frameAncestors.type, 'array')
	const origin = new RegExp(frame.pattern)
	for (const ok of ['https://www.example.nl', 'http://localhost:8080']) assert.ok(origin.test(ok), ok)
	for (const bad of ['www.example.nl', 'https://', 'ftp://x.nl', 'https://x.nl/path']) assert.equal(origin.test(bad), false, bad)
	assert.ok(frame.patternMessage)
})

test('an absent kind or header shows the value the site already assumes', () => {
	assert.equal(field('kind').default, 'site')
	assert.ok(PORTAL.kind.enum.includes(field('kind').default))
	assert.equal(field('headerVariant').default, 'double')
	assert.ok(PORTAL.headerVariant.enum.includes(field('headerVariant').default))
})

test('the logo is picked from the portal media library and stored as an address the site serves', () => {
	const logo = field('logo')
	assert.equal(logo.type, 'reference')
	assert.equal(logo.register, 'portaliq')
	assert.ok(REGISTER.components.schemas[logo.schema], `the register has no "${logo.schema}" schema`)
	const media = REGISTER.components.schemas[logo.schema].properties
	assert.ok(media.kind.enum.includes(logo.filter.kind))
	assert.ok(media.status.enum.includes(logo.filter.status))
	// Only this portal's images: media.portal holds the portal slug.
	assert.equal(logo.filter.portal, '@object.slug')
	assert.ok(media.portal)
	// A typed address, accepted where PortalRuntimeConfigResolver::logoUrl() accepts it.
	assert.equal(logo.free, true)
	const typed = new RegExp(logo.freePattern)
	for (const ok of ['/apps/portaliq/logo/open-tilburg.svg', 'https://www.example.nl/logo.svg', 'http://localhost/logo.png']) assert.ok(typed.test(ok), ok)
	for (const bad of ['//evil.example/logo.svg', 'javascript:alert(1)', 'logo.svg', 'ftp://x.nl/l.svg', '']) assert.equal(typed.test(bad), false, bad)
	assert.ok(logo.freePatternMessage)
	assert.ok(media[logo.labelField], logo.labelField)
	// Root-relative, so PortalRuntimeConfigResolver::logoUrl() lets it through,
	// and the route the public media items are served on.
	assert.match(logo.valueTemplate, /^\/apps\/portaliq\/api\/content\/media\/\{id\}\?portal=\{portal\}$/)
	assert.match(read('appinfo/routes.php'), /'url' => '\/api\/content\/media\/\{id\}'/)
})

test('the details are one card, and the old component is gone', () => {
	const sections = widget('portal-settings').content.sections
	assert.ok(sections.some((s) => s.widgetId === 'portal-settings-details'))
	for (const id of ['portal-settings-locales', 'portal-settings-frame']) {
		assert.equal(widget(id), undefined, `${id} is a card of its own again`)
	}
	assert.deepEqual(form.content.fields.map((f) => f.key), ['title', 'tagline', 'slug', 'organisation', 'kind', 'headerVariant', 'locales', 'accountLabel', 'logo', 'frameAncestors'])
	assert.equal(read('src/registry.js').includes('PortalDetailsForm'), false)
})
