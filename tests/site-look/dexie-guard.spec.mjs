#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// dexie-guard.spec.mjs: `scripts/check-single-dexie.js` reads a development
// bundle set (dexie-check-reads-dev-bundles).
//
// A development build keeps comments, and @conduction/nextcloud-vue quotes
// Dexie's error in one. The guard took that comment for a Dexie copy without
// a version and failed a good bundle set. Each test writes a fixture `js/`
// and runs the real script against it.
//
// Usage:
//   node --test tests/site-look/dexie-guard.spec.mjs

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const LOCKED = JSON.parse(readFileSync(join(ROOT, 'package-lock.json'), 'utf8'))
	.packages['node_modules/dexie'].version

const PROSE =
	'/*\n * import every consuming app evaluated Dexie on every page, and Dexie throws\n'
	+ ' * "Two different versions of Dexie loaded in the same app" at module init\n */\n'

/**
 * A chunk that embeds Dexie the way a build does.
 *
 * @param {string} version The Dexie version.
 * @param {'dev'|'prod'} shape Unminified source constant or minified property.
 * @return {string} The chunk.
 */
function dexieChunk(version, shape) {
	const literal =
		shape === 'dev'
			? `var DEXIE_VERSION = '${version}';`
			: `semVer:"${version}",`
	return `${literal}\nthrow new Error(\`Two different versions of Dexie loaded in the same app: \${a} and \${b}\`)\n`
}

/**
 * Run the guard on a fixture `js/`.
 *
 * @param {Record<string, string>} files File name to content.
 * @return {{status: number, out: string}} Its exit code and output.
 */
function guard(files) {
	const dir = mkdtempSync(join(tmpdir(), 'dexie-guard-'))
	try {
		for (const [name, text] of Object.entries(files)) {
			writeFileSync(join(dir, name), text)
		}
		const run = spawnSync(
			process.execPath,
			[join(ROOT, 'scripts/check-single-dexie.js')],
			{
				env: { ...process.env, DEXIE_CHECK_JS_DIR: dir },
				encoding: 'utf8',
			},
		)
		return { status: run.status, out: `${run.stdout}${run.stderr}` }
	} finally {
		rmSync(dir, { recursive: true, force: true })
	}
}

test('a chunk that only quotes the error in a comment is no Dexie copy', () => {
	const { status, out } = guard({
		'portaliq-site-editor.js': PROSE,
		'portaliq-dexie.js': dexieChunk(LOCKED, 'dev'),
	})
	assert.equal(status, 0, out)
	assert.match(
		out,
		new RegExp(
			`one Dexie version \\(${LOCKED.replace(/\./g, '\\.')}\\) across 1 chunk`,
		),
	)
})

test('a production chunk still reads', () => {
	const { status, out } = guard({
		'portaliq-dexie.js': dexieChunk(LOCKED, 'prod'),
	})
	assert.equal(status, 0, out)
})

test('two versions still fail', () => {
	const { status, out } = guard({
		'a.js': dexieChunk(LOCKED, 'dev'),
		'b.js': dexieChunk('0.0.1', 'prod'),
	})
	assert.equal(status, 1, out)
	assert.match(out, /2 different Dexie versions/)
})

test('a real copy without a readable version still fails loudly', () => {
	const { status, out } = guard({
		'c.js': 'throw new Error(`Two different versions of Dexie loaded in the same app: ${a}`)\n',
	})
	assert.equal(status, 1, out)
	assert.match(out, /no version literal matched/)
})
