#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-reuse-golden.spec.mjs: a golden record of scripts/check-reuse.js. Each
// scenario runs the script against a fake `reuse` on PATH in a scratch copy of
// the repository root, and records its exit code, stdout, stderr and the
// baseline it leaves behind. PORTALIQ_GOLDEN_RECORD=1 rewrites the record.
//
// Usage:
//   node --test tests/check-reuse-golden.spec.mjs

import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import {
	chmodSync,
	copyFileSync,
	existsSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const GOLDEN = join(ROOT, 'tests/fixtures/check-reuse-golden.json')

const CLEAN = {
	summary: { files_total: 12 },
	non_compliant: { missing_licensing_info: [], missing_copyright_info: [] },
}

const SCENARIOS = {
	'a clean tree': {
		report: CLEAN,
		baseline: { missing_licensing_info: [], missing_copyright_info: [] },
	},
	'below the baseline': {
		report: CLEAN,
		baseline: { missing_licensing_info: ['a.woff'], missing_copyright_info: [] },
	},
	'a new file without licensing': {
		report: {
			...CLEAN,
			non_compliant: {
				missing_licensing_info: ['new.xyz', 'a.woff'],
				missing_copyright_info: ['new.xyz'],
			},
		},
		baseline: { missing_licensing_info: ['a.woff'], missing_copyright_info: [] },
	},
	'a baseline key that is not a list': {
		report: CLEAN,
		baseline: { missing_licensing_info: 'x', missing_copyright_info: [] },
	},
	'no baseline': { report: CLEAN },
	'stderr errors': {
		report: CLEAN,
		stderr: 'INFO fine\nERROR could not parse a.md\n  ERROR  second\n',
		baseline: { missing_licensing_info: [], missing_copyright_info: [] },
	},
	'one stderr error': {
		report: CLEAN,
		stderr: 'ERROR only one\n',
		baseline: { missing_licensing_info: [], missing_copyright_info: [] },
	},
	'new files out of order': {
		report: {
			...CLEAN,
			non_compliant: {
				missing_licensing_info: ['zeta.x', 'alpha.y'],
				missing_copyright_info: [],
			},
		},
		baseline: { missing_licensing_info: [], missing_copyright_info: [] },
	},
	'licence hygiene': {
		report: {
			...CLEAN,
			non_compliant: {
				unused_licenses: ['CC0-1.0'],
				bad_licenses: { 'X-1': ['f'] },
				deprecated_licenses: [],
				missing_licenses: { MIT: ['g'] },
				read_errors: ['h'],
			},
		},
		baseline: { missing_licensing_info: [], missing_copyright_info: [] },
	},
	'unparseable json': { raw: 'not json at all', baseline: {} },
	'no runner installed': { report: CLEAN, noRunner: true },
	'an unexpected shape': { report: { summary: null, reuse_tool_version: '9.0' } },
	'an unexpected shape without a version': {
		report: { summary: { files_total: 'n' }, non_compliant: {} },
	},
	'the list flag': {
		args: ['--list'],
		report: { ...CLEAN, non_compliant: { missing_licensing_info: ['z', 'b'] } },
		stderr: 'ERROR one\n',
	},
	'the list flag without errors': { args: ['--list'], report: CLEAN },
	'update over a clean tree': {
		args: ['--update'],
		report: {
			...CLEAN,
			non_compliant: {
				missing_licensing_info: ['z', 'b'],
				missing_copyright_info: ['c'],
			},
		},
	},
	'update over a failing tree': {
		args: ['--update'],
		report: { ...CLEAN, non_compliant: { unused_licenses: ['CC0-1.0'] } },
	},
}

/**
 * Run the script once in a scratch root with a fake `reuse` on PATH.
 *
 * @param {object} scenario The scenario.
 * @return {object} Exit code, output and the baseline left behind.
 */
function run(scenario) {
	const root = mkdtempSync(join(tmpdir(), 'pq-reuse-'))
	try {
		mkdirSync(join(root, 'scripts'))
		mkdirSync(join(root, 'bin'))
		copyFileSync(
			join(ROOT, 'scripts/check-reuse.js'),
			join(root, 'scripts/check-reuse.js'),
		)
		const stdout = scenario.raw ?? JSON.stringify(scenario.report)
		writeFileSync(join(root, 'out.txt'), stdout)
		writeFileSync(join(root, 'err.txt'), scenario.stderr ?? '')
		const fake = join(root, 'bin/reuse')
		writeFileSync(
			fake,
			`#!/bin/sh\ncat "${join(root, 'out.txt')}"\ncat "${join(root, 'err.txt')}" >&2\nexit 1\n`,
		)
		chmodSync(fake, 0o755)
		const baselineFile = join(root, '.reuse-baseline.json')
		if (scenario.baseline !== undefined) {
			writeFileSync(baselineFile, JSON.stringify(scenario.baseline))
		}
		const result = spawnSync(
			process.execPath,
			[join(root, 'scripts/check-reuse.js'), ...(scenario.args ?? [])],
			{
				cwd: root,
				encoding: 'utf8',
				env: {
					PATH: scenario.noRunner
						? join(root, 'empty')
						: join(root, 'bin') + ':/usr/bin:/bin',
				},
			},
		)
		return {
			status: result.status,
			stdout: result.stdout,
			stderr: result.stderr.replace(
				/(Could not parse the JSON report from [^:]+:).*$/m,
				'$1 <parse error>',
			),
			baseline: existsSync(baselineFile)
				? readFileSync(baselineFile, 'utf8')
				: null,
		}
	} finally {
		rmSync(root, { recursive: true, force: true })
	}
}

test('check-reuse matches the golden record', () => {
	const recorded = {}
	for (const [name, scenario] of Object.entries(SCENARIOS)) {
		recorded[name] = run(scenario)
	}
	if (process.env.PORTALIQ_GOLDEN_RECORD === '1') {
		writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
	assert.deepEqual(recorded, JSON.parse(readFileSync(GOLDEN, 'utf8')))
})
