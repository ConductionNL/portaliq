#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-helpers-golden.spec.mjs: what the traffic client's helpers report
// and what they leave out (the configuration it reads, links, search terms,
// form and field ids, dimensions, script errors, routes, experiments, heatmap
// clicks, selectors and variant addresses), pinned in a golden file before
// they were split into named steps.
//
// Usage:
//   node --test tests/traffic-helpers-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/traffic-helpers-golden.spec.mjs

import { test } from 'node:test'
import * as h from '../src/traffic/helpers.js'
import { golden } from './support/golden.mjs'

const el = (tagName, className = '', parentNode = null, extra = {}) => ({ nodeType: 1, tagName, className, parentNode, ...extra })

test('the traffic helpers answer as they did', () => {
	const file = golden('traffic-helpers')
	file.check('readConfig', [
		h.readConfig(null, null),
		h.readConfig({ dataset: { origin: 'https://a.example/', appPath: '/apps/portaliq/', portal: 'p' } }, { portal: 'q' }),
		h.readConfig({ dataset: { origin: 'https://a.example', apppath: '/x' } }, { portal: 'q' }),
		h.readConfig({ dataset: {}, src: 'https://b.example/index.php/apps/portaliq/api/traffic-client.js?v=2' }, { portal: 'q' }),
		h.readConfig({ src: 'HTTP://B.example/api/traffic-client.js#x' }, null),
		h.readConfig({ src: 'https://b.example/other.js' }, { portal: 'q' }),
		h.readConfig({ dataset: { portal: 'p' } }, { portal: 3 }),
	])
	file.check('classifyLink', [
		['https://a.example/docs/Rapport%202026.PDF?x=1', 'a.example'],
		['https://a.example/page', 'A.example'],
		['https://b.example', 'a.example'],
		['http://b.example/file.unknown', 'a.example'],
		['mailto:x@y', 'a.example'],
		['', ''],
		['https://a.example/bad%E0%A4%A.docx', 'a.example'],
	].map(([href, host]) => h.classifyLink(href, host)))
	file.check('searchTermFrom', [
		'', 'https://a/x', 'https://a/x?q=', 'https://a/x?Zoek=+hond+en+kat+&q=2', 'https://a/x?a=1&search=%E0%A4%A#frag',
		'https://a/x?query', 'https://a/x?zoekterm=' + 'x'.repeat(300), 'https://a/x?other=1#q=2',
	].map((location) => h.searchTermFrom(location)))
	const form = (attrs, id) => ({ id, getAttribute: (name) => attrs[name] ?? null })
	file.check('formIdOf', [
		h.formIdOf(null), h.formIdOf({}),
		h.formIdOf(form({ 'data-portaliq-form': 'aanvraag', name: 'n' }, 'f')),
		h.formIdOf(form({ name: 'n' }, 'f')), h.formIdOf(form({ name: 'n' }, '')),
		h.formIdOf(form({ action: '/send' }, '')), h.formIdOf(form({}, '')),
	])
	file.check('fieldIdOf', [
		null, {}, { tagName: 'div', id: 'd' }, { tagName: 'input', type: 'HIDDEN', id: 'h' }, { tagName: 'INPUT', type: 'submit', id: 's' },
		{ tagName: 'input', type: 'Button', id: 'b' }, { tagName: 'input', type: 'text', id: 't' }, { tagName: 'input', name: 'n' },
		{ tagName: 'select', id: '' , name: 'sel' }, { tagName: 'textarea', id: 'x'.repeat(300) }, { tagName: 'INPUT' },
	].map((field) => h.fieldIdOf(field)))
	file.check('dimensionParams', [
		h.dimensionParams(null, {}),
		h.dimensionParams({ customDimensions: 'x' }, {}),
		h.dimensionParams({ customDimensions: [{ id: 'a' }] }, null),
		h.dimensionParams({ customDimensions: [null, { id: '' }, { id: 'a' }, { id: 'b' }, { id: 'c' }, { id: 'd' }, { id: 'e' }] }, { a: 'x', b: '', c: null, d: 0, e: 'y'.repeat(300), f: 'z' }),
	])
	file.check('errorParams', [
		h.errorParams(null),
		h.errorParams({}),
		h.errorParams({ message: 'Boom', filename: 'https://a.example/app.js?token=secret#x', lineno: '12', colno: 'x' }),
		h.errorParams({ error: { stack: 'Error: at https://a/x?token=1' } }),
		h.errorParams({ message: 'm'.repeat(300), filename: 'file:///local/app.js', error: { stack: '' } }),
	])
	file.check('siteRoute', [
		'', '/', 'https://a.example', 'https://a.example/', 'https://a.example/contact/', 'https://a.example/x?route=%2Fnieuws%2F&portal=p',
		'https://a.example/x?portal=p&route=', 'https://a.example/x?route=nieuws', 'contact//', '/a/b?x=1#y', 'https://a.example/x?route=%E0%A4%A',
		'https://a.example/x?myroute=/a',
	].map((location) => h.siteRoute(location)))
	const running = { id: 'e1', status: 'running', page: '/home/', variants: [{}, {}] }
	file.check('experimentFor', [
		h.experimentFor(null, '/home'),
		h.experimentFor({ experiments: 'x' }, '/home'),
		h.experimentFor({ experiments: [null, { ...running, status: 'draft' }, { ...running, variants: [{}] }, { ...running, variants: 'x' }, running, { ...running, id: 'e2' }] }, '/home'),
		h.experimentFor({ experiments: [{ ...running, page: '' }] }, '/'),
		h.experimentFor({ experiments: [running] }, '/other'),
	])
	const target = el('BUTTON', 'pq-btn btn-2 primary extra', el('DIV', { baseVal: 'svg-a svg-b' }, el('SECTION', '', el('MAIN', 'm'))))
	file.check('heatClickParams', [
		h.heatClickParams(null, {}),
		h.heatClickParams({ pageX: 1, pageY: 1 }, null),
		h.heatClickParams({ pageX: 1, pageY: 1 }, { width: 0, height: 10 }),
		h.heatClickParams({ pageX: 1, pageY: 1 }, { width: 10, height: 'x' }),
		h.heatClickParams({ pageX: 11, pageY: 1 }, { width: 10, height: 10 }),
		h.heatClickParams({ pageX: 'a', pageY: 1 }, { width: 10, height: 10 }),
		h.heatClickParams({ pageX: 3.33333, pageY: 10, target }, { width: 10, height: 10, viewport: 800 }),
		h.heatClickParams({ pageX: 0, pageY: 0 }, { width: 10, height: 10, viewport: 5000 }),
		h.heatClickParams({ pageX: 0, pageY: 0, target: { tagName: 'X'.repeat(40) } }, { width: 10, height: 10, viewport: 1 }),
	])
	file.check('safeSelector', [
		h.safeSelector(null),
		h.safeSelector({ nodeType: 3 }),
		h.safeSelector(el('SPAN', 'a  b c d', el('P', null, { nodeType: 9 }))),
		h.safeSelector(el('I', 'x'.repeat(140))),
	])
	file.check('variantUrl', [
		['https://a.example/home', '/b'],
		['https://a.example/home?portal=p#x', '/b'],
		['https://a.example/x?portal=p&route=%2Fhome&utm=1', '/b c'],
		['https://a.example/x?', '/b'],
		['/home?route=a&route=b', '/c'],
		['', '/b'],
		['relative/path?x=1', '/b'],
	].map(([location, route]) => h.variantUrl(location, route)))
	file.save()
})
