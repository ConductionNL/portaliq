#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// inbox-steps-golden.spec.mjs: how the inbox turns a message body into text
// and named links, which message actions it draws, its tabs, which app action
// completes a task and which chosen files a task refuses, pinned in a golden
// file before each was split into named steps.
//
// Usage:
//   node --test tests/inbox-steps-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/inbox-steps-golden.spec.mjs

import { test } from 'node:test'
import { actionOf, bodyParts, inboxTabs } from '../src/site/pages/inbox/inbox.js'
import { completeViaAction, validateFiles } from '../src/site/pages/inbox/tasks.js'
import { golden } from './support/golden.mjs'

const ORIGIN = 'https://gemeente.example'
const SITE = `${ORIGIN}/apps/portaliq/site`
const LABELS = { publication: 'Bekijk de publicatie', link: 'Open de pagina' }

test('message bodies, actions and tabs', () => {
	const file = golden('inbox-steps')
	file.check('bodyParts', [
		'',
		null,
		'Geen link hier.',
		`Lees het besluit hier: ${SITE}?route=/publicatie/abc.`,
		`Uw aanvraag.\nLees het besluit hier: ${SITE}?route=/zaken`,
		`- Besluit: ${SITE}?route=/publicatie/1\n- Bijlage: ${SITE}?route=/publicatie/2`,
		`Zie ook. - Eerste: ${SITE}?route=/a - Tweede: ${SITE}?route=/b`,
		`Open ${SITE}#open=x/y en ${SITE}?route=/c#frag),`,
		`Ga naar https://elders.example/apps/portaliq/site?route=/x of ${ORIGIN}/other`,
		`Link: http://gemeente.example/apps/portaliq/site/?route=relative`,
		`Kapot: ${SITE}?route=%E0%A4%A en ftp://x`,
		`Zin. Lees meer: ${SITE}`,
		`:${SITE}`,
	].map((body) => bodyParts(body, ORIGIN, LABELS)))
	file.check('bodyParts without origin', bodyParts(`Zie ${SITE}`, '', LABELS))
	file.check('actionOf', [
		[null, ORIGIN],
		[{ action: { label: ' ', href: '/x' } }, ORIGIN],
		[{ action: { label: 3, href: '/x' } }, ORIGIN],
		[{ action: { label: ' Open ', href: '' } }, ORIGIN],
		[{ action: { label: 'Open', href: '/zaken/1' } }, ORIGIN],
		[{ action: { label: 'Open', href: '//elders.example' } }, ORIGIN],
		[{ action: { label: 'Open', href: '/a\\b' } }, ORIGIN],
		[{ action: { label: 'Open', href: '#open=a/b.c-d' } }, ORIGIN],
		[{ action: { label: 'Open', href: '#open=<x>' } }, ORIGIN],
		[{ action: { label: 'Open', href: `${ORIGIN}/apps/portaliq` } }, ORIGIN],
		[{ action: { label: 'Open', href: `${ORIGIN}.evil.example/x` } }, ORIGIN],
		[{ action: { label: 'Open', href: `${ORIGIN}/x` } }, ''],
		[{ action: { label: 'Open', href: 'javascript:alert(1)' } }, ORIGIN],
	].map(([message, origin]) => actionOf(message, origin)))
	file.check('actionOf default origin', actionOf({ action: { label: 'Open', href: `${ORIGIN}/x` } }))
	file.check('inboxTabs', [
		inboxTabs(null),
		inboxTabs([{ tab: ' Zaken ' }, { tab: 'Zaken' }, { tab: '' }, { tab: 3 }, null, { tab: 'Nieuws' }]),
	])
	file.save()
})

test('the action that completes a task and the files a task refuses', () => {
	const file = golden('inbox-task-steps')
	const aggregate = {
		contributions: [
			null,
			{
				app: 'dossiq',
				actions: [
					null,
					{ id: 'lever', type: 'create', endpoint: '/x' },
					{ id: 'lever', type: 'update', endpoint: '/x' },
					{ id: 'lever', type: 'call', endpoint: '' },
					{ id: 'lever', type: 'call', endpoint: '/lever', taskField: 'taak' },
				],
			},
			{ app: 'other', actions: [{ id: 'go', endpoint: '/go', taskField: '' }] },
		],
	}
	file.check('completeViaAction', [
		[null, aggregate],
		[{ metadata: { completeVia: { app: ' dossiq ', action: '' } } }, aggregate],
		[{ metadata: { completeVia: { app: 3, action: 'x' } } }, aggregate],
		[{ metadata: { completeVia: { app: 'dossiq', action: ' lever ' } } }, aggregate],
		[{ metadata: { completeVia: { app: 'other', action: 'go' } } }, aggregate],
		[{ metadata: { completeVia: { app: 'other', action: 'gone' } } }, aggregate],
		[{ metadata: { completeVia: { app: 'nobody', action: 'go' } } }, aggregate],
		[{ metadata: { completeVia: { app: 'other', action: 'go' } } }, null],
	].map(([task, agg]) => completeViaAction(task, agg)))
	const t = (key, vars) => `${key}|${JSON.stringify(vars ?? null)}`
	const task = (upload) => ({ metadata: { upload } })
	const pdf = { name: 'a.PDF', type: 'application/pdf', size: 1000 }
	const big = { name: 'b.pdf', type: 'application/pdf', size: 5 * 1024 * 1024 }
	const png = { name: 'c.png', type: 'image/png', size: 10 }
	file.check('validateFiles', [
		validateFiles(task({ required: true }), [], t),
		validateFiles(task({ required: true }), null, t),
		validateFiles(task({}), [], t),
		validateFiles(task({ maxFiles: 1 }), [pdf, png], t),
		validateFiles(task({ maxFiles: 5, maxSizeBytes: 1024 * 1024 }), [pdf, big], t),
		validateFiles(task({ maxFiles: 5, acceptedTypes: ['.pdf', 'image/jpeg'] }), [pdf, png], t),
		validateFiles(task({ maxFiles: 5, acceptedTypes: ['pdf', 'image/png'] }), [pdf, png], t),
		validateFiles(task({ acceptedTypes: ['image/*'], maxSizeBytes: 1 }), [png], t),
		validateFiles(task({ acceptedTypes: ['image/*'] }), [png], t),
		validateFiles(null, [pdf], t),
	])
	file.save()
})
