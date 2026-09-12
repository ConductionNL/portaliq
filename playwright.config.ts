/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright config for the Nextcloud App Template.
 *
 * Scaffolded by /journeydoc-init (ADR-030). Two projects:
 *
 *   - `chromium`     — the default regression project. Excludes the
 *                      docs capture spec so PR pipelines don't reshoot
 *                      screenshots on every push. Add regression specs
 *                      under `tests/e2e/` and they run here.
 *   - `docs-capture` — the journeydoc screenshot capture project.
 *                      Opt-in: `npx playwright test --project docs-capture`.
 *                      Output lands in
 *                      `docs/static/screenshots/tutorials/{user,admin}/`.
 *
 * Point at a running Nextcloud with PLAYWRIGHT_BASE_URL (or the older
 * NEXTCLOUD_URL). There is no default — see the note on `baseURL` below.
 */

import { defineConfig, devices } from '@playwright/test'
import { resolveBaseURL } from './tests/e2e/base-url.ts'

/**
 * The target instance, decided in one place for the whole repo.
 *
 * This used to be its own `process.env.PLAYWRIGHT_BASE_URL ||
 * process.env.NEXTCLOUD_URL` read, which made it a SECOND entrance next to
 * `tests/e2e/base-url.ts` and the one a plain `npx playwright test` from the
 * repo root actually uses. It kept the no-default rule, but it could not know
 * about anything the resolver learned afterwards, and a guard wired into the
 * resolver would have covered nothing here.
 *
 * `resolveBaseURL()` keeps the rule that an unset target stops the run outside
 * CI, adds the `BASE_URL` / `NC_BASE_URL` names the shared quality workflow
 * exports, and refuses the shared development instance unless the run names it
 * in `PORTALIQ_E2E_ALLOW_SHARED_INSTANCE`. See `tests/e2e/shared-instance.ts`.
 */
const baseURL = resolveBaseURL()

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 180_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 1,
	workers: 1,
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. This matters
	// more here than elsewhere: `timeout` above is 180s per test, so a stalled
	// suite reaches the 45m cap far sooner than one on the 30s default.
	// Measured overhead before `Run Playwright tests` starts is 2.0-2.4 min and
	// the uploads after it take seconds, so 38m keeps ~7 min of margin while
	// guaranteeing both a tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: [
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
		['list'],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL,
		// `on-first-retry` writes a trace only for the SECOND attempt, so a
		// failure that does NOT reproduce on retry — precisely the one worth a
		// trace, and the likely shape against a shared instance under variable
		// load — leaves no record of the attempt that actually failed. It also
		// ties the trace artifact to `retries`, which several repos in this
		// fleet set to 0, giving them zero traces ever. `retain-on-failure`
		// traces every attempt and keeps the ones that failed: strictly more
		// informative, and independent of the retry count.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		// The target is a shared Nextcloud dev instance under variable load; give
		// navigation/actions headroom so a busy-instance page load is not read as
		// a portal failure.
		navigationTimeout: 90_000,
		actionTimeout: 90_000,
	},

	projects: [
		// Default regression project. Excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			// `tests/e2e/visual/` is excluded for the same reason as the docs
			// capture spec: those specs write review artifacts against a live
			// instance and carry no assertion a PR should gate on. The one
			// assertion that IS worth gating — the public first-load budget —
			// lives in site-content.spec.ts (S18) instead, so excluding these
			// does not quietly drop it.
			testIgnore: ['**/docs-screenshots.spec.ts', '**/visual/**'],
			use: { ...devices['Desktop Chrome'] },
		},
		// Documentation capture project (ADR-030 / journeydoc). Opt-in:
		//   npx playwright test --project docs-capture
		// Output lands in `docs/static/screenshots/tutorials/{user,admin}/`.
		{
			name: 'docs-capture',
			testMatch: /docs-screenshots\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
			},
			timeout: 90_000,
		},
	],
})
