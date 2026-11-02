// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A golden file for node tests: each case's value is compared, as JSON, with
// the value pinned in tests/Fixtures/Golden/<name>.json. Run with
// GOLDEN_RECORD=1 to (re)write the file from the code as it stands, one case
// per line.

import assert from 'node:assert/strict'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const DIR = join(dirname(fileURLToPath(import.meta.url)), '..', 'Fixtures', 'Golden')

/**
 * A golden file to check values against, or to record them into.
 *
 * @param {string} name The file's name, without `.json`.
 * @return {{check: (key: string, value: any) => void, save: () => void}} The file.
 */
export function golden(name) {
	const path = join(DIR, `${name}.json`)
	const record = process.env.GOLDEN_RECORD === '1'
	const pinned = !record && existsSync(path) ? JSON.parse(readFileSync(path, 'utf8')) : {}
	const seen = {}
	return {
		check(key, value) {
			const plain = JSON.parse(JSON.stringify(value === undefined ? null : value))
			seen[key] = plain
			if (!record) {
				assert.ok(Object.hasOwn(pinned, key), `golden ${name} pins ${key}`)
				assert.deepEqual(plain, pinned[key], `golden ${name}: ${key}`)
			}
		},
		save() {
			if (record) {
				const lines = Object.entries(seen).map(([key, value]) => `\t${JSON.stringify(key)}: ${JSON.stringify(value)}`)
				writeFileSync(path, `{\n${lines.join(',\n')}\n}\n`)
			}
		},
	}
}

/**
 * A fetch that answers from a list and records every call.
 *
 * @param {Array<{status?: number, body?: any, throws?: boolean}>} answers The answers, in order; the last repeats.
 * @return {{fetch: (url: string, init?: object) => Promise<object>, calls: Array<object>}} The recorder.
 */
export function recordingFetch(answers) {
	const calls = []
	const list = Array.isArray(answers) && answers.length > 0 ? answers : [{}]
	return {
		calls,
		async fetch(url, init = {}) {
			const answer = list[Math.min(calls.length, list.length - 1)]
			calls.push({ url: String(url), init: describeInit(init) })
			if (answer.throws) {
				throw new Error('network')
			}
			const status = answer.status ?? 200
			return {
				ok: status >= 200 && status < 300,
				status,
				headers: { get: (header) => answer.headers?.[header.toLowerCase()] ?? null },
				json: async () => {
					if (answer.body === undefined) {
						throw new Error('no json')
					}
					return JSON.parse(JSON.stringify(answer.body))
				},
				blob: async () => ({ blob: true }),
				text: async () => JSON.stringify(answer.body ?? ''),
			}
		},
	}
}

/**
 * A request's init as plain data: a FormData body as its entries.
 *
 * @param {object} init The init.
 * @return {object} The plain init.
 */
function describeInit(init) {
	const out = { ...init }
	if (typeof FormData !== 'undefined' && init.body instanceof FormData) {
		out.body = { formData: [...init.body.entries()].map(([key, value]) => [key, typeof value === 'string' ? value : `<${value?.name || 'blob'}>`]) }
	}
	return out
}

/**
 * The outcome of a call: its value, or the error it threw.
 *
 * @param {() => any} run The call.
 * @return {Promise<{value?: any, error?: object}>} The outcome.
 */
export async function outcomeOf(run) {
	try {
		return { value: await run() }
	} catch (error) {
		return { error: { message: String(error?.message || error), status: error?.status ?? null } }
	}
}
