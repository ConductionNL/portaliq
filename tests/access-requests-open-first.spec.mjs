#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// access-requests-open-first.spec.mjs: the access request list opens on the
// server order stateRank then newest, the schema carries the rank with a
// default for a pending request, the repair step that backfills it is
// registered, and "Gevraagde toegang" reads "Zaken van" above the
// organisation (PtToegangsverzoeken).
//
// Usage:
//   node --test tests/access-requests-open-first.spec.mjs
//
// @spec openspec/changes/access-requests-open-first/specs/portal-identity/spec.md#requirement-open-access-requests-come-first

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const REGISTER = JSON.parse(read('lib/Settings/portaliq_register.json'))
const INFO = read('appinfo/info.xml')
const LIST = MANIFEST.pages.find((page) => page.id === 'AccessRequests').config
const NL = MANIFEST.i18n.labels.nl

test('the list opens on the server order: open first, then newest', () => {
	assert.equal(LIST.defaultOrder, 'stateRank,-requestedAt')
})

test('the schema carries the rank, 0 by default', () => {
	const rank = REGISTER.components.schemas.portalAccessRequest.properties.stateRank
	assert.equal(rank.type, 'integer')
	assert.equal(rank.default, 0)
})

test('the repair step that backfills the rank runs on upgrade', () => {
	assert.match(INFO, /<step>OCA\\Portaliq\\Repair\\BackfillAccessRequestStateRank<\/step>/)
})

// @spec openspec/changes/access-requests-open-first/specs/portal-identity/spec.md#requirement-the-requested-access-reads-as-the-board-draws-it
test('the requested access reads "Zaken van" above the organisation', () => {
	const column = LIST.columns.find((entry) => entry.key === 'onBehalfOf')
	assert.equal(column.overlineText, 'Cases of')
	assert.equal(NL['Cases of'], 'Zaken van')
})
