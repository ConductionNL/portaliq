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
	for (const attempt of lintAttempts()) {
		const result = runAttempt(attempt)
		if (result !== null) {
			return result
		}
	}

	return null
}

/**
 * The two ways to run the linter, in the order they are tried.
 *
 * @return {Array<{how: string, cmd: string, args: string[]}>} the runners
 */
function lintAttempts() {
	return [
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
}

/**
 * Run one runner: its output, null when it is not installed, or exit when it
 * could not be started for another reason.
 *
 * @param {{how: string, cmd: string, args: string[]}} attempt - the runner
 * @return {?{stdout: string, stderr: string, how: string}} captured output
 */
function runAttempt(attempt) {
	const result = spawnSync(attempt.cmd, attempt.args, {
		cwd: REPO_ROOT,
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
	})
	// ENOENT means this runner has no such binary; fall through to the next.
	// Any other failure is the linter itself talking, and `reuse lint` exits
	// non-zero on a non-compliant tree, which is the expected state here.
	if (result.error && result.error.code === 'ENOENT') return null
	if (result.error) {
		console.error(`Could not run ${attempt.how}: ${result.error.message}`)
		process.exit(1)
	}
	return { stdout: result.stdout, stderr: result.stderr, how: attempt.how }
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
 * Run the linter and read its report, or exit when there is none to read.
 *
 * @return {{report: object, stderr: string, how: string}} the parsed report,
 *   the captured stderr and which runner produced them
 */
function readReport() {
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

	assertReadable(report, how)
	return { report, stderr, how }
}

/**
 * Exit unless the report has the blocks every assertion reads.
 *
 * REFUSE TO PASS ON A REPORT THIS SCRIPT CANNOT READ.
 *
 * Defaulting a missing block to `{}` would make every assertion below
 * vacuous and exit 0 — the same failure this guard exists to prevent, one
 * layer down. It is reachable without a bug in `reuse`: the PATH binary is
 * resolved by the runner, not pinned like the image, so a different major
 * with a different --json shape is exactly the drift the pin defends
 * against on the other path.
 *
 * @param {object} report - the parsed report
 * @param {string} how - which runner produced it
 * @return {void}
 */
function assertReadable(report, how) {
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
}

/**
 * The findings this guard asserts on, read from one linter run.
 *
 * @return {{nonCompliant: object, summary: object, missingLicensing: string[],
 *   missingCopyright: string[], errors: string[], how: string}} the findings
 */
function readFindings() {
	const { report, stderr, how } = readReport()
	const nonCompliant = report.non_compliant
	return {
		nonCompliant,
		summary: report.summary,
		missingLicensing: nonCompliant.missing_licensing_info || [],
		missingCopyright: nonCompliant.missing_copyright_info || [],
		errors: errorLines(stderr),
		how,
	}
}

/**
 * Print the files without licensing information and the stderr ERROR lines.
 *
 * @param {object} findings - the findings
 * @return {void}
 */
function printList(findings) {
	const { missingLicensing, errors } = findings
	console.log(`Files with no licensing information (${missingLicensing.length}):`)
	for (const file of [...missingLicensing].sort()) console.log(`  ${file}`)
	if (errors.length) {
		console.log('')
		console.log(`stderr ERROR lines (${errors.length}):`)
		for (const line of errors) console.log(`  ${line}`)
	}
}

/**
 * 1. stderr, absolute: report the ERROR lines the linter wrote.
 *
 * @param {string[]} errors - the stderr ERROR lines
 * @return {boolean} true when there were any
 */
function stderrFailed(errors) {
	if (errors.length === 0) return false
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
	return true
}

/**
 * The hygiene lists, in the order they are reported.
 *
 * @type {string[]}
 */
const HYGIENE_KEYS = [
	'unused_licenses',
	'bad_licenses',
	'deprecated_licenses',
	'missing_licenses',
	'read_errors',
]

/**
 * What to add under a hygiene list that is not empty, by list.
 *
 * @type {Object<string, string[]>}
 */
const HYGIENE_NOTES = {
	unused_licenses: [
		'A licence text in LICENSES/ that nothing references is itself a',
		'REUSE failure. Changing an override orphans the licence it used',
		'to name — remove that file in the same commit.',
	],
	read_errors: [
		'An unreadable file is EXCLUDED from files_total, so the coverage',
		'sets below cannot see it. Hence the absolute check.',
	],
}

/**
 * 2. licence hygiene, absolute. An unused licence is itself a REUSE failure.
 *
 * @param {object} nonCompliant - the report's non_compliant block
 * @return {boolean} true when any hygiene list is not empty
 */
function hygieneFailed(nonCompliant) {
	let failed = false
	for (const key of HYGIENE_KEYS) {
		if (hygieneKeyFailed(key, nonCompliant[key]) === true) {
			failed = true
		}
	}
	return failed
}

/**
 * Report one hygiene list when it is not empty.
 *
 * @param {string} key - the list's name in the report
 * @param {Array|object|undefined} value - the list, or a map keyed by entry
 * @return {boolean} true when it is not empty
 */
function hygieneKeyFailed(key, value) {
	const entries = Array.isArray(value) ? value : Object.keys(value || {})
	if (entries.length === 0) return false
	console.error('')
	console.error(`${key}: ${entries.join(', ')}`)
	for (const line of HYGIENE_NOTES[key] || []) console.error(line)
	return true
}

/**
 * Write the baseline from the findings, unless an absolute check failed.
 *
 * @param {object} findings - the findings
 * @param {boolean} failed - whether an absolute check failed
 * @return {void}
 */
function writeBaseline(findings, failed) {
	const { missingLicensing, missingCopyright } = findings
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
}

/**
 * The committed baseline, or exit when there is none.
 *
 * @return {object} the baseline
 */
function readBaseline() {
	if (!fs.existsSync(BASELINE)) {
		console.error(
			'No baseline. Run `npm run check:reuse -- --update` and commit it.',
		)
		process.exit(1)
	}
	return JSON.parse(fs.readFileSync(BASELINE, 'utf8'))
}

/**
 * 3. coverage, ratcheted BY PATH rather than by count.
 *
 * A count lets a net-zero swap through: delete one of the twelve known
 * fonts, add one source file with an extension REUSE.toml does not list,
 * and the total is unchanged while a genuinely new uncovered file has
 * entered the tree -- the exact hole this guard is here to close.
 * check:schema-l10n can only count because its baseline is 30k opaque
 * strings; here the baseline is a short list of stable paths, so it names
 * them. Shrinking the list is always fine.
 *
 * @param {object} findings - the findings
 * @param {object} baseline - the committed baseline
 * @return {boolean} true when the baseline is malformed or a file is new
 */
function ratchetFailed(findings, baseline) {
	let failed = false
	for (const entry of [
		['missing_licensing_info', findings.missingLicensing],
		['missing_copyright_info', findings.missingCopyright],
	]) {
		if (ratchetKeyFailed(entry, baseline) === true) {
			failed = true
		}
	}
	return failed
}

/**
 * The ratchet for one list: the baseline must hold a list, and nothing found
 * may be missing from it.
 *
 * @param {[string, string[]]} entry - the list's key and the files found
 * @param {object} baseline - the committed baseline
 * @return {boolean} true when the baseline list is malformed or a file is new
 */
function ratchetKeyFailed(entry, baseline) {
	const [key, found] = entry
	const allowed = baseline[key]
	if (!Array.isArray(allowed)) {
		console.error('')
		console.error(`Baseline key \`${key}\` is not a list of paths.`)
		console.error('Regenerate it with `npm run check:reuse -- --update`.')
		return true
	}
	const known = new Set(allowed)
	const added = found.filter((file) => !known.has(file)).sort()
	if (added.length === 0) return false
	reportNewFiles(key, added)
	return true
}

/**
 * Name the new files without licensing or copyright information.
 *
 * @param {string} key - the list's key
 * @param {string[]} added - the new files, sorted
 * @return {void}
 */
function reportNewFiles(key, added) {
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
}

/**
 * Check the findings against the committed baseline and exit 1 when the
 * ratchet or an absolute check failed; say how to lower a baseline that is
 * higher than it needs to be.
 *
 * @param {object} findings - the findings
 * @param {boolean} failed - whether an absolute check failed
 * @return {void}
 */
function checkAgainstBaseline(findings, failed) {
	const baseline = readBaseline()
	const { summary, missingLicensing, errors, how } = findings

	console.log(
		`${summary.files_total} file(s) checked via ${how}; `
			+ `${missingLicensing.length} without licensing info `
			+ `(baseline ${(baseline.missing_licensing_info || []).length}), `
			+ `${errors.length} stderr ERROR line(s)`,
	)

	if (ratchetFailed(findings, baseline) || failed) process.exit(1)

	const baselineCount = (baseline.missing_licensing_info || []).length
	if (missingLicensing.length < baselineCount) {
		console.log(
			`${baselineCount - missingLicensing.length} fewer than the baseline — lower it with:`,
		)
		console.log('  npm run check:reuse -- --update')
	}
}

/**
 * Run the guard: list, write the baseline, or check against it.
 *
 * @return {void}
 */
function main() {
	const update = process.argv.includes('--update')
	const list = process.argv.includes('--list')

	const findings = readFindings()
	if (list) {
		printList(findings)
		return
	}

	// The absolute assertions run BEFORE the --update branch. Writing a
	// baseline over a tree with unparseable headers or an orphaned licence
	// would exit 0 and defer those failures to the next CI run, which is the
	// wrong moment to learn about them.
	const stderrFailure = stderrFailed(findings.errors)
	const hygieneFailure = hygieneFailed(findings.nonCompliant)
	const failed = stderrFailure || hygieneFailure

	if (update) {
		writeBaseline(findings, failed)
		return
	}

	checkAgainstBaseline(findings, failed)
}

main()
