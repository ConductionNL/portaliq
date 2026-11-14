#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// maint-units-golden.spec.mjs: the federated-search request URL, the lines
// of the accessibility statement and the inline error of one form field,
// pinned in a golden file before each was split into named steps.
//
// Usage:
//   node --test tests/maint-units-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/maint-units-golden.spec.mjs

import { test } from 'node:test'
import { fieldErrors } from '../src/site/components/c/forms.js'
import { buildRequestUrl } from '../src/site/lib/federatedSearch.js'
import { statementLines } from '../src/site/lib/statementLines.js'
import { golden } from './support/golden.mjs'

const BASE = { endpoint: '/api/publications', origin: 'https://portal.example', pageSize: 20, page: 1 }

test('federated search request urls', () => {
	const file = golden('maint-units')
	file.check('buildRequestUrl', [
		{},
		{ query: 'parkeren' },
		{ query: 'parkeren', searchInsideDocuments: false },
		{ query: '', sort: 'title:ASC' },
		{ facetField: 'organisation', selectedFacets: ['Gemeente A', 'Gemeente B'] },
		{ facetFields: ['themes', 'category', '', 7], facets: { themes: ['wonen'], category: ['besluit', 'advies'] } },
		{ facetFields: ['themes', 'organisation'], facets: { themes: ['wonen'] }, lockedFilters: { organisation: 'org-1' } },
		{ lockedFilters: { organisation: ['org-1', 'org-2'], themes: 'x' }, facetFields: ['themes'] },
		{ periodFrom: '2026-01-01', periodTo: '2026-02-28' },
		{ periodField: 'modified', periodFrom: '2026-02-31', periodTo: 'yesterday' },
		{ periodFrom: '2026-03-01' },
		{ sort: 'publicationDate' },
		{ sort: ':DESC' },
		{ sort: '_relevance:DESC', query: 'a b', page: 3, pageSize: 50 },
		{ endpoint: 'https://other.example/api/search?x=1', query: 'q' },
	].map((state) => buildRequestUrl({ ...BASE, ...state })))
	file.save()
})

test('accessibility statement lines', () => {
	const file = golden('maint-units-statement')
	const calls = []
	const t = (key, vars) => {
		calls.push(key)
		return vars ? `${key} ${JSON.stringify(vars)}` : key
	}
	const formatDate = (iso) => `[${iso}]`
	file.check('statementLines', [
		null,
		{},
		{ status: 'A', automatedOnly: false },
		{ status: 'C', auditExpired: true, registerUrl: 'https://register.example/1' },
		{ status: 'Z', automatedOnly: true },
		{
			status: 'B',
			measurement: { measuredAt: '2026-05-01', axeVersion: '4.10', pagesMeasured: 12, pagesNotMeasured: 2 },
			audit: { party: 'Audits BV', date: '2026-04-01', reportUrl: 'https://audit.example/r.pdf' },
			issues: [
				{ rule: 'color-contrast', sentence: 'Text has too little contrast.', impact: 'serious', pages: 4, helpUrl: 'https://help/1' },
				{ rule: 'label', impact: 'critical' },
				{ impact: 'unknown', pages: 1 },
			],
			notMeasured: [{ url: '/a', reason: 'login' }, {}],
			contact: { email: 'a11y@example.org', phone: '0123' },
		},
		{ measurement: {}, audit: { party: 'P' }, contact: {} },
	].map((statement) => statementLines(statement, t, formatDate)))
	file.check('statementLines calls', calls)
	file.save()
})

test('inline field errors', () => {
	const file = golden('maint-units-fields')
	const t = (key, vars) => (vars ? `${key} ${JSON.stringify(vars)}` : key)
	const action = {
		fields: ['name', 'doc', 'optionalDoc', 'when', 'count', 'amount', 'agree', 'note', 'own', 'blankOwn', 'kind', 7],
		fieldConfigs: {
			name: { required: true, label: 'Name' },
			doc: { type: 'file', required: true, label: 'Document' },
			optionalDoc: { type: 'file' },
			when: { input: 'date', required: true },
			count: { widget: 'count' },
			amount: { input: 'number' },
			agree: { valueType: 'boolean' },
			note: { size: 'large' },
			own: { required: true, requiredMessage: 'Fill in your own words.' },
			blankOwn: { required: true, requiredMessage: '   ' },
			kind: { valueType: 'integer', label: '' },
		},
		optionsProviders: { kind: { type: 'static' } },
	}
	file.check('fieldErrors', [
		[{}, {}],
		[{ name: '  ', own: null, blankOwn: undefined }, { doc: [] }],
		[{ name: 'Ada', when: '2026-02-31', count: '3', amount: '1,5', agree: 'ja', note: 'x', own: 'y', blankOwn: 'z', kind: '2' }, { doc: [{ name: 'a.pdf' }] }],
		[{ name: 'Ada', when: '1 3 2026', count: '3.5', amount: 'veel', agree: 'misschien', kind: 'two' }, { doc: [{}] }],
		[{ when: '2026-03-01', count: 0, amount: -2, agree: false, kind: 4 }, null],
	].map(([values, files]) => fieldErrors(action, values, files, t)))
	file.check('fieldErrors without translator', fieldErrors(action, {}, {}, undefined))
	file.check('fieldErrors without fields', fieldErrors({}, {}, {}, t))
	file.save()
})
