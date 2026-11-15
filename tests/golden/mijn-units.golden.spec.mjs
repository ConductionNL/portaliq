#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-units.golden.spec.mjs: pins the signed-in navigation, the deadline
// badge and the case card over a spread of inputs
// (tests/Fixtures/Golden/mijn-units.json).
//
// Usage:
//   node --test tests/golden/mijn-units.golden.spec.mjs

import { test } from 'node:test'
import { buildNav } from '../../src/shared/portalNav.js'
import { caseCard } from '../../src/site/components/mijn/cases.js'
import { deadlineBadge } from '../../src/site/components/mijn/rows.js'
import { golden } from '../support/golden.mjs'

const t = (key, vars = {}) => `[${key}]${Object.entries(vars).map(([k, v]) => `${k}=${v}`).join(',')}`

const CONTRIBUTIONS = [
	{ app: 'dossiq', pages: [{ id: 'mijnZaken', label: 'Mijn zaken', icon: 'Folder' }, { id: 'bare' }] },
	{ app: 'learniq' },
	{ app: 'shillinq', pages: [{ id: 'invoices', label: '' }], collections: [{ id: 'invoices' }] },
]
const ENABLED = [
	undefined,
	{},
	{ cases: true },
	{ tasks: true, messages: true, news: true },
	{ cases: true, tasks: true, messages: true, news: true, access: true, pages: [{ special: 'samenwerken', label: 'Work together', icon: 'Account' }], themes: [{ slug: 'parkeren', title: 'Parkeren' }] },
	{ access: true, stepUp: [{ app: 'dossiq', collection: 'mijnZaken', level: 'midden' }, { app: 'filinq', collection: 'secret', level: 'hoog', label: 'Geheim' }] },
	{ cases: false, tasks: 'yes', access: 1 },
]

const TODAY = new Date(2026, 9, 2, 12, 0, 0)
const DUES = [null, '', 'geen datum', '2026-09-30', '2026-10-02T23:00:00', '2026-10-03', '2026-10-05', '2026-10-09', '2026-10-10', '2027-01-15', new Date(2026, 9, 4)]

const ZAKEN = {
	id: 'mijnZaken',
	kind: 'cases',
	closedField: 'isFinalStatus',
	dueField: 'deadline',
	turnField: 'portalTurn',
	fieldConfigs: { portalTurn: { valueLabels: { applicant: 'Wacht op u', us: 'De gemeente is aan zet' } } },
}
const ROWS = [
	{ id: 'c-82', identifier: '2026-0082', title: 'Verlichting', statusPublicLabel: 'Ontvangen', deadline: '2026-11-01', portalTurn: 'us', _caseTypeName: 'Woo-verzoek' },
	{ id: 'c-83', reference: 'Z-1', identifier: 'X', title: 'Z-1', portalTurn: 'applicant', isFinalStatus: true },
	{ id: 'c-84', identifier: '  ', title: 'Zonder nummer', deadline: '2026-10-03' },
	{},
	null,
]
const STEPS = [null, [], [{ label: 'Ontvangen', state: 'done' }, { label: 'In behandeling', state: 'current' }, { label: 'Besluit', state: 'todo' }]]

test('the mijn helpers answer as pinned', () => {
	const file = golden('mijn-units')
	for (const [e, enabled] of ENABLED.entries()) {
		file.check(`buildNav:${e}`, buildNav(CONTRIBUTIONS, t, enabled))
		file.check(`buildNav:none:${e}`, buildNav(null, t, enabled))
	}
	for (const due of DUES) {
		const key = due instanceof Date ? due.toISOString() : String(due)
		file.check(`deadline:${key}`, deadlineBadge(due, TODAY, t, 'nl'))
		file.check(`deadline:en:${key}`, deadlineBadge(due, TODAY, t, 'en'))
		file.check(`deadline:default:${key}`, deadlineBadge(due, TODAY, t))
	}
	file.check('deadline:noToday', deadlineBadge('2999-01-01', null, t) !== null)
	for (const [r, row] of ROWS.entries()) {
		for (const [s, steps] of STEPS.entries()) {
			file.check(`caseCard:${r}:${s}`, caseCard(row, ZAKEN, { tr: t, locale: 'nl', today: TODAY, steps, yourTurn: ['applicant'] }))
			file.check(`caseCard:noCollection:${r}:${s}`, caseCard(row, null, { tr: t, locale: 'en', today: TODAY, steps }))
		}
		file.check(`caseCard:turnNotList:${r}`, caseCard(row, ZAKEN, { tr: t, locale: 'nl', today: TODAY, yourTurn: 'applicant' }))
	}
	file.save()
})
