#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-units.golden.spec.mjs: pins the open-record target, the assistant's
// reply, cell formatting, record templates and form summaries over a spread
// of inputs (tests/Fixtures/Golden/page-units.json).
//
// Usage:
//   node --test tests/golden/page-units.golden.spec.mjs

import { test } from 'node:test'
import { consumeOpenTarget } from '../../src/shared/openRecord.js'
import { formatCell } from '../../src/site/components/collections/cells.js'
import { summarySentence } from '../../src/site/components/forms/summary.js'
import { fillTemplate } from '../../src/site/components/mijn/template.js'
import { askAssistant } from '../../src/site/lib/assistantApi.js'
import { golden, outcomeOf, recordingFetch } from '../support/golden.mjs'

const t = (key, vars = {}) => `[${key}]${Object.entries(vars).map(([k, v]) => `${k}=${v}`).join(',')}`

/**
 * A storage double that records what is written.
 *
 * @param {string|null} kept What it holds.
 * @param {boolean} broken Whether every call throws.
 * @return {{storage: object, log: Array}} The double and its log.
 */
function storageWith(kept, broken = false) {
	const log = []
	return {
		log,
		storage: {
			getItem(key) {
				log.push(['get', key])
				if (broken) throw new Error('blocked')
				return kept
			},
			setItem(key, value) {
				log.push(['set', key, value])
				if (broken) throw new Error('blocked')
			},
			removeItem(key) {
				log.push(['remove', key])
			},
		},
	}
}

const LOCATIONS = [
	null,
	{ hash: '', pathname: '/mijn', search: '' },
	{ hash: '#open=dossiq/cases/c%201', pathname: '/mijn', search: '?a=1' },
	{ hash: '#open=dossiq/cases', pathname: '/mijn' },
	{ hash: '#open=dossiq/cases/c1' },
]
const KEPT = [null, 'null', '{"app":"dossiq","collection":"cases","id":"c2"}', '{"app":"dossiq","collection":1,"id":"c2"}', 'not json']

const ASSISTANT = {
	answered: [{ status: 200, body: { status: 'answered', answer: 'Ja.', sources: [{ title: 'Afval', url: '/afval' }], removed: true, conversationId: 'k1' } }],
	noSources: [{ status: 200, body: { status: 'answered', answer: 'Ja.', sources: [] } }],
	abstained: [{ status: 200, body: { status: 'abstained', answer: null, sources: 'x', conversationId: 5 } }],
	off: [{ status: 503, body: {} }],
	network: [{ throws: true }],
}
const ASKS = [
	{ portal: 'zuid', question: 'Wanneer?', locale: 'en', conversationId: 'k0' },
	{ question: 'Wat?' },
]

const CELLS = [null, '', 0, 1, true, 'ja', '2026-03-01', '2026-03-01T10:15:00Z', 12.5, 'abc', { name: 'Naam' }, ['a', 'b']]
const RENDERS = ['boolean', 'date', 'datetime', 'currency', 'text', undefined]
const LABELS = [undefined, { ja: 'Zeker', 1: 'Een', true: 'Waar' }]

const TEMPLATES = [
	'',
	'Uw aanvraag {title} is ontvangen. Wij beslissen voor {deadline}! Uw kenmerk is {identifier}? Klaar.',
	'Alleen {missing} hier. En {title}.',
]
const RECORDS = [null, { title: 'Verhuizing', deadline: '2026-11-01', identifier: 'Z-1', count: 3 }, { title: '', deadline: null, identifier: 7 }]
const OPTIONS = [undefined, { whenEmpty: { missing: 'Nog niets.', deadline: '' } }, { fields: ['title', 'deadline'], locale: 'en' }, { fields: null, whenEmpty: null }]

const SUMMARY = {
	template: '{kind} op {when} voor {who}, {extra}',
	phrases: { kind: { parkeren: 'een parkeervergunning', leeg: '  ' } },
}
const VALUES = [
	{},
	{ kind: 'parkeren', when: '2026-10-03', who: ['a', 'b', '', null, ['c']], extra: 'x' },
	{ kind: 'leeg', when: '2026-12-25', who: 'z', extra: { nested: true } },
	{ kind: 'anders', when: '  ', who: 'a', extra: 3 },
	{ kind: 'parkeren', when: 'morgen', who: 'b', extra: 0 },
]
const SUMMARY_OPTIONS = { who: [{ value: 'a', label: ' Anna ' }, { value: 'b', label: '' }, null], kind: 'not a list' }
const NOW = new Date(2026, 9, 2, 12)

test('the page helpers answer as pinned', async () => {
	const file = golden('page-units')
	for (const [l, location] of LOCATIONS.entries()) {
		for (const [k, kept] of KEPT.entries()) {
			for (const broken of [false, true]) {
				const { storage, log } = storageWith(kept, broken)
				const replaced = []
				const history = { replaceState: (...args) => { replaced.push(args); if (broken) throw new Error('no') } }
				const target = consumeOpenTarget(location, history, storage)
				file.check(`open:${l}:${k}:${broken}`, { target, log, replaced })
			}
		}
		file.check(`open:${l}:noStorage`, consumeOpenTarget(location, null, null))
	}

	for (const [answerName, answers] of Object.entries(ASSISTANT)) {
		for (const [a, ask] of ASKS.entries()) {
			const recorder = recordingFetch(answers)
			const outcome = await outcomeOf(() => askAssistant('/apps/portaliq/api/content/', ask, recorder.fetch))
			file.check(`assistant:${answerName}:${a}`, { calls: recorder.calls, outcome })
		}
	}

	for (const [c, value] of CELLS.entries()) {
		for (const render of RENDERS) {
			for (const [v, valueLabels] of LABELS.entries()) {
				file.check(`cell:${c}:${render}:${v}`, formatCell(value, render, { locale: 'nl-NL', t, valueLabels }))
			}
		}
	}
	file.check('cell:badCurrency', formatCell(5, 'currency', { locale: 'xx-invalid-locale-tag-!', t }))

	for (const [i, template] of TEMPLATES.entries()) {
		for (const [r, record] of RECORDS.entries()) {
			for (const [o, options] of OPTIONS.entries()) {
				file.check(`template:${i}:${r}:${o}`, fillTemplate(template, record, options))
			}
		}
	}

	for (const [v, values] of VALUES.entries()) {
		file.check(`summary:${v}`, summarySentence(SUMMARY, values, SUMMARY_OPTIONS, { locale: 'nl', now: NOW }))
		file.check(`summary:en:${v}`, summarySentence(SUMMARY, values, undefined, { locale: 'en', now: NOW }))
	}
	file.check('summary:none', summarySentence(null, {}))
	file.save()
})
