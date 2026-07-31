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

/**
 * The ONE place the target instance is decided.
 *
 * There is deliberately NO `?? 'http://localhost:8080'` fallback. That default
 * is how two apps in this fleet were found running their suites — including
 * their WRITE paths — against the SHARED dev container: the suite looked
 * green, created fixtures in other people's environment, and one session's
 * numbers had to be retracted. An unset variable must stop the run, not
 * silently retarget it.
 *
 * `PLAYWRIGHT_BASE_URL` wins over the older `NEXTCLOUD_URL` so a caller who
 * sets the standard Playwright variable is never ignored.
 */
const baseURL = process.env.PLAYWRIGHT_BASE_URL || process.env.NEXTCLOUD_URL
if (!baseURL) {
	throw new Error(
		'Refusing to run: set PLAYWRIGHT_BASE_URL (or NEXTCLOUD_URL) to the target Nextcloud.\n'
		+ 'Never point it at the shared dev instance on :8080 — spin up a disposable one:\n'
		+ '  APP_SRC=<worktree> spin-up-e2e-instance.sh portaliq <free-port> openregister',
	)
}

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 180_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 1,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
		['list'],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL,
		trace: 'on-first-retry',
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
			testIgnore: ['**/docs-screenshots.spec.ts'],
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
