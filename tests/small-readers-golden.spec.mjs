#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// small-readers-golden.spec.mjs: a mandate's end day, the footer's content,
// the header menu's words for a route, the assistant's reply and the page
// body the editor saves, pinned in a golden file before each was split into
// named steps.
//
// Usage:
//   node --test tests/small-readers-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/small-readers-golden.spec.mjs

import { test } from 'node:test'
import { bodyFor } from '../src/editor/pageBody.js'
import { endDayOf } from '../src/site/components/e/mandates.js'
import { askAssistant } from '../src/site/lib/assistantApi.js'
import { footerContentOf, menuLabelFor } from '../src/site/lib/shellData.js'
import { golden } from './support/golden.mjs'

test('the small readers answer as pinned', async () => {
	const file = golden('small-readers')
	file.check('endDayOf', [
		null,
		{},
		{ day: ' ', month: '', year: null },
		{ day: '5', month: '3', year: '2027' },
		{ day: '05', month: '03', year: 2027 },
		{ day: 31, month: 2, year: 2027 },
		{ day: '29', month: '2', year: '2028' },
		{ day: '1', month: '13', year: '2027' },
		{ day: '123', month: '1', year: '2027' },
		{ day: '1', month: '1', year: '27' },
		{ day: '1', month: '', year: '2027' },
		{ day: 'a', month: '1', year: '2027' },
	].map((end) => endDayOf(end)))
	file.check('footerContentOf', [
		null,
		{},
		{ footer: { description: 'D', colophon: 5, socials: [{ a: 1 }], legalLinks: 'x', badges: [1], cta: { label: 'Doe', href: '/x' }, contact: { title: 'Bel', lines: ['1', '2'] } } },
		{ footer: { cta: { label: 'Doe' }, contact: { lines: [] } } },
		{ footer: { cta: { href: '/x' }, contact: { lines: 'x' } } },
		{ footer: { contact: { lines: ['a'] } } },
	].map((site) => footerContentOf(site)))
	const menus = [
		{ position: 1, items: [{ link: '/afval', name: 'Uit footer' }] },
		{ items: [
			{ link: '/', name: ' Home ' },
			{ link: '/zorg/', name: '' , children: [{ link: '/zorg', name: 'Zorg en hulp' }] },
			{ link: '/wonen', name: 'Wonen', items: [{ link: '/wonen/huur/', name: ' Huren ' }] },
			{ link: '/leeg', name: '   ' },
			null,
		] },
	]
	file.check('menuLabelFor', ['/', '', '/zorg', '/zorg/', '/wonen/huur', '/afval', '/leeg', '/nergens'].map((route) => menuLabelFor(menus, route)))
	file.check('menuLabelForNoMenus', [menuLabelFor(null, '/'), menuLabelFor([{}], '/')])

	const replies = []
	for (const body of [
		{ status: 'answered', answer: 'Ja.', sources: [{ title: 'T', url: '/t' }], removed: true, conversationId: 'c1' },
		{ status: 'answered', answer: 'Ja.', sources: [] },
		{ status: 'answered', answer: 5, sources: [{ title: 'T' }] },
		{ status: 'abstained', answer: 'x', sources: [{ title: 'T' }], conversationId: 7 },
		{ status: 'answered', answer: 'Ja.', sources: 'x' },
	]) {
		replies.push(await askAssistant('/api', { question: 'q' }, async () => ({ ok: true, json: async () => body })))
	}
	file.check('assistantReply', replies)

	const states = [
		{ kind: 'markdown', markdown: '# Hoi' },
		{ kind: 'markdown' },
		{ kind: 'grid', widgets: [], page: null },
		{ kind: 'grid', widgets: [{ id: 'n', type: 'nlHeading', region: 'main', x: 0, y: 0, w: 4, h: 1 }], page: { body: { type: 'grid', clearedRegions: ['aside'], markdown: 'oud', widgets: [{ id: 'h', type: 'nlHeading', region: 'header' }, { id: 'm', type: 'nlParagraph', region: 'main' }] } } },
		{ kind: 'grid', page: { body: { type: 'markdown', markdown: 'oud' } } },
		{ kind: 'grid', widgets: [{ id: 'n', type: 'nlHeading' }], page: { draft: { type: 'grid', widgets: 'x' } } },
	]
	file.check('bodyFor', states.map((state) => bodyFor(state)))
	file.check('bodyForJson', JSON.stringify(states.map((state) => bodyFor(state))))
	file.check('footerJson', JSON.stringify(footerContentOf({ footer: { cta: { label: 'a', href: '/b' }, contact: { title: 't', lines: ['l'] } } })))
	file.save()
})
