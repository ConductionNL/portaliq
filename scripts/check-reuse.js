#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-reuse.js — the REUSE guard: an absolute assertion on the linter's
// stderr, and a RATCHET on files that carry no licensing information.
//
// WHY THIS EXISTS
//
//   The shared quality workflow runs `fsfe/reuse-action@v5` with
//   `continue-on-error: ${{ !inputs.reuse-blocking }}`, and this app does not
//   set `reuse-blocking`, so it takes the fleet default of `false`. The job
//   therefore passes whether or not `reuse lint` is compliant; the finding
//   surfaces only as the `REUSE` row in the Quality Report comment, which
//   nothing reads on a green PR. That flag is ON since 2026-09-17 (WOO-575),
//   so a non-compliant tree now fails the REUSE job itself. This guard holds
//   the two lines that job still cannot see.
//
//   1. STDERR ERRORS, asserted at zero. `reuse lint` writes parse failures to
//      stderr and still exits on the compliance verdict alone, so a document
//      whose own header cannot be read is invisible in the exit code. Five
//      openspec documents were in exactly that state: each wrote the tag and
//      its value inside one code span, the extractor captured a value with a
//      trailing backtick and could not parse it, and ten ERROR lines said so
//      where nobody was looking. They are fixed (`.license` companions), and
//      the next document written the same way must not be able to reintroduce
//      them quietly. Asked for by Remko Huisman in review on #553, in the
//      spirit of hydra#657's pin guard.
//
//   2. FILES WITHOUT LICENSING INFO, ratcheted by path. REUSE.toml carries a
//      `path = "**"` blanket since 2026-09-17, so nothing should fall through
//      any more — this set is expected to stay EMPTY, and the ratchet exists
//      so that a regression to the pre-blanket state (a removed table, a
//      `precedence = "override"` gone wrong) is named file by file rather
//      than read off a `compliant: false` that says nothing about which.
//
//   The three licence-hygiene lists are absolute rather than ratcheted, and
//   that is deliberate: an UNUSED licence in LICENSES/ is itself a REUSE
//   failure, so swapping an override without removing the licence text it
//   orphaned turns one finding into two. That trap is live here — #553 removed
//   LICENSES/CC-BY-SA-4.0.txt for exactly this reason when the MaxMind
//   override was corrected.
//
// HOW IT RUNS THE LINTER
//
//   `reuse` on PATH if present, otherwise the pinned container image, which is
//   what CI and every measurement on #521 and #553 used:
//
//     docker run --rm -v "$PWD":/data fsfe/reuse:5 lint --json
//
//   Docker runs as root, so this script only ever READS through it — a
//   `reuse download` through the same image leaves root-owned files in
//   LICENSES/.
//
// Usage:
//   node scripts/check-reuse.js            (npm run check:reuse)
//   node scripts/check-reuse.js --update   rewrite the baseline
//   node scripts/check-reuse.js --list     print what is uncovered
//
// Exit codes:
//   0 — stderr is clean, counts are at or below the baseline
//   1 — an ERROR line appeared, a count grew, a licence list is dirty,
//       the baseline file is missing, or the linter could not be run

'use strict'

const { spawnSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const BASELINE = path.join(REPO_ROOT, '.reuse-baseline.json')

// Pinned to the same major the workflow's action resolves and every
// measurement in #521/#553 used. A floating tag would let an upstream
// extractor change move this repo's numbers with no commit here.
const IMAGE = 'fsfe/reuse:5'

/**
 * Run `reuse lint --json`, preferring a local install over the container.
 *
 * @return {?{stdout: string, stderr: string, how: string}} captured output, or
 *   null when neither runner is installed — the caller decides what that means,
 *   so this helper does not own half the process lifecycle.
 */
function runLint() {
	const attempts = [
		{ how: 'reuse (PATH)', cmd: 'reuse', args: ['lint', '--json'] },
		{
			how: `docker ${IMAGE}`,
			cmd: 'docker',
			args: [
				'run',
				'--rm',
				'-v',
				`${REPO_ROOT}:/data`,
				IMAGE,
				'lint',
				'--json',
			],
		},
	]

	for (const attempt of attempts) {
		const result = spawnSync(attempt.cmd, attempt.args, {
			cwd: REPO_ROOT,
			encoding: 'utf8',
			maxBuffer: 64 * 1024 * 1024,
		})
		// ENOENT means this runner has no such binary; fall through to the next.
		// Any other failure is the linter itself talking, and `reuse lint` exits
		// non-zero on a non-compliant tree, which is the expected state here.
		if (result.error && result.error.code === 'ENOENT') continue
		if (result.error) {
			console.error(`Could not run ${attempt.how}: ${result.error.message}`)
			process.exit(1)
		}
		return { stdout: result.stdout, stderr: result.stderr, how: attempt.how }
	}

	return null
}

/**
 * ERROR lines the linter wrote to stderr.
 *
 * @param {string} stderr - captured stderr
 * @return {string[]} the matching lines
 */
function errorLines(stderr) {
	return stderr
		.split('\n')
		.filter((line) => line.includes('ERROR'))
		.map((line) => line.trim())
}

/**
 *
 */
function main() {
	const update = process.argv.includes('--update')
	const list = process.argv.includes('--list')

	const runner = runLint()
	if (runner === null) {
		console.error('Neither `reuse` nor `docker` is available, so REUSE was not')
		console.error('checked. This guard fails rather than passes silently — a')
		console.error('skipped licence check reads exactly like a clean one.')
		console.error('')
		console.error('Install one of:')
		console.error('  pipx install reuse')
		console.error(`  docker pull ${IMAGE}`)
		process.exit(1)
	}
	const { stdout, stderr, how } = runner

	let report
	try {
		report = JSON.parse(stdout)
	} catch (e) {
		console.error(`Could not parse the JSON report from ${how}: ${e.message}`)
		console.error(stdout.slice(0, 400))
		process.exit(1)
	}

	// REFUSE TO PASS ON A REPORT THIS SCRIPT CANNOT READ.
	//
	// Defaulting a missing block to `{}` would make every assertion below
	// vacuous and exit 0 — the same failure this guard exists to prevent, one
	// layer down. It is reachable without a bug in `reuse`: the PATH binary is
	// resolved by the runner, not pinned like the image, so a different major
	// with a different --json shape is exactly the drift the pin defends
	// against on the other path.
	if (
		typeof report.summary !== 'object'
		|| report.summary === null
		|| typeof report.summary.files_total !== 'number'
		|| typeof report.non_compliant !== 'object'
		|| report.non_compliant === null
	) {
		console.error(`Unexpected report shape from ${how}.`)
		console.error('')
		console.error('Expected `summary.files_total` (number) and `non_compliant`')
		console.error(
			`(object); got tool version ${report.reuse_tool_version || 'unknown'}.`,
		)
		console.error('')
		console.error(
			'Failing rather than passing: a report that cannot be read says',
		)
		console.error('nothing about the tree, and green would claim otherwise.')
		process.exit(1)
	}

	const nonCompliant = report.non_compliant
	const summary = report.summary
	const missingLicensing = nonCompliant.missing_licensing_info || []
	const missingCopyright = nonCompliant.missing_copyright_info || []
	const errors = errorLines(stderr)

	if (list) {
		console.log(
			`Files with no licensing information (${missingLicensing.length}):`,
		)
		for (const file of [...missingLicensing].sort()) console.log(`  ${file}`)
		if (errors.length) {
			console.log('')
			console.log(`stderr ERROR lines (${errors.length}):`)
			for (const line of errors) console.log(`  ${line}`)
		}
		return
	}

	// The absolute assertions run BEFORE the --update branch. Writing a
	// baseline over a tree with unparseable headers or an orphaned licence
	// would exit 0 and defer those failures to the next CI run, which is the
	// wrong moment to learn about them.
	let failed = false

	// 1. stderr, absolute.
	if (errors.length > 0) {
		console.error('')
		console.error(
			`${errors.length} ERROR line(s) on stderr — a file's own licence header`,
		)
		console.error('could not be parsed, and `reuse lint` does not fail on that:')
		console.error('')
		for (const line of errors.slice(0, 10)) console.error(`  ${line}`)
		console.error('')
		console.error('A markdown document that writes the tag and its value inside')
		console.error('ONE code span is the known cause. Give it a `.license`')
		console.error('companion rather than rewriting the prose around the tag.')
		failed = true
	}

	// 2. licence hygiene, absolute. An unused licence is itself a REUSE failure.
	for (const key of [
		'unused_licenses',
		'bad_licenses',
		'deprecated_licenses',
		'missing_licenses',
		'read_errors',
	]) {
		const value = nonCompliant[key]
		const entries = Array.isArray(value) ? value : Object.keys(value || {})
		if (entries.length > 0) {
			console.error('')
			console.error(`${key}: ${entries.join(', ')}`)
			if (key === 'unused_licenses') {
				console.error(
					'A licence text in LICENSES/ that nothing references is itself a',
				)
				console.error(
					'REUSE failure. Changing an override orphans the licence it used',
				)
				console.error('to name — remove that file in the same commit.')
			}
			if (key === 'read_errors') {
				console.error(
					'An unreadable file is EXCLUDED from files_total, so the coverage',
				)
				console.error('sets below cannot see it. Hence the absolute check.')
			}
			failed = true
		}
	}

	if (update) {
		if (failed) {
			console.error('')
			console.error('Baseline NOT written — fix the failures above first.')
			process.exit(1)
		}
		fs.writeFileSync(
			BASELINE,
			JSON.stringify(
				{
					missing_licensing_info: [...missingLicensing].sort(),
					missing_copyright_info: [...missingCopyright].sort(),
				},
				null,
				2,
			) + '\n',
		)
		console.log(
			`baseline written: ${missingLicensing.length} file(s) without licensing info, `
				+ `${missingCopyright.length} without copyright info`,
		)
		return
	}

	if (!fs.existsSync(BASELINE)) {
		console.error(
			'No baseline. Run `npm run check:reuse -- --update` and commit it.',
		)
		process.exit(1)
	}
	const baseline = JSON.parse(fs.readFileSync(BASELINE, 'utf8'))

	console.log(
		`${summary.files_total} file(s) checked via ${how}; `
			+ `${missingLicensing.length} without licensing info `
			+ `(baseline ${(baseline.missing_licensing_info || []).length}), `
			+ `${errors.length} stderr ERROR line(s)`,
	)

	// 3. coverage, ratcheted BY PATH rather than by count.
	//
	// A count lets a net-zero swap through: delete one of the twelve known
	// fonts, add one source file with an extension REUSE.toml does not list,
	// and the total is unchanged while a genuinely new uncovered file has
	// entered the tree -- the exact hole this guard is here to close.
	// check:schema-l10n can only count because its baseline is 30k opaque
	// strings; here the baseline is a short list of stable paths, so it names
	// them. Shrinking the list is always fine.
	for (const [key, found] of [
		['missing_licensing_info', missingLicensing],
		['missing_copyright_info', missingCopyright],
	]) {
		const allowed = baseline[key]
		if (!Array.isArray(allowed)) {
			console.error('')
			console.error(`Baseline key \`${key}\` is not a list of paths.`)
			console.error('Regenerate it with `npm run check:reuse -- --update`.')
			failed = true
			continue
		}
		const known = new Set(allowed)
		const added = found.filter((file) => !known.has(file)).sort()
		if (added.length > 0) {
			const what = key.replace('missing_', '').replace(/_/g, ' ')
			console.error('')
			console.error(`${added.length} new file(s) with no ${what}:`)
			for (const file of added) console.error(`  ${file}`)
			console.error('')
			console.error(
				'The likely cause is an extension REUSE.toml does not list —',
			)
			console.error('it lists extensions rather than using a blanket, so an')
			console.error('unlisted one falls through silently.')
			console.error('')
			console.error('Add it to the appropriate block in REUSE.toml.')
			failed = true
		}
	}

	if (failed) process.exit(1)

	const baselineCount = (baseline.missing_licensing_info || []).length
	if (missingLicensing.length < baselineCount) {
		console.log(
			`${baselineCount - missingLicensing.length} fewer than the baseline — lower it with:`,
		)
		console.log('  npm run check:reuse -- --update')
	}
}

main()
