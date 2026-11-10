#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// public-record-address.spec.mjs: the address a public record list and one
// record are read from, with every part encoded, pinned before both reads
// took the record's address as one object.
//
// Usage:
//   node --test tests/public-record-address.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fetchRecord, fetchRecordList } from '../src/site/lib/publicRecordsApi.js'

/**
 * A fetch that answers 200 with one body and records each address.
 *
 * @param {object} body The body.
 * @return {Function} The fetch, with `urls`.
 */
function recording(body) {
	const urls = []
	const send = async (url) => {
		urls.push(url)
		return { ok: true, status: 200, json: async () => body }
	}
	send.urls = urls
	return send
}

test('a list and a record are read from their encoded address', async () => {
	const send = recording({ entries: [{ id: 1 }], record: { id: 1 } })
	assert.deepEqual(await fetchRecordList('/api/content/', 'deci diq', 'list/1', send), [{ id: 1 }])
	await fetchRecord('/apps/portaliq/api/content', 'decidiq', 'l', 'r/1 2', send)
	assert.deepEqual(send.urls, [
		'/api/public-records/deci%20diq/list%2F1',
		'/apps/portaliq/api/public-records/decidiq/l/r%2F1%202',
	])
})
