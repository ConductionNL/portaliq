/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. Whichever config it picks, EVERY project
 * in it runs. The root `playwright.config.ts` declares two:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). It re-shoots every
 *                  tutorial screenshot into `docs/static/screenshots/…` and is
 *                  driven deliberately by `npm run test:e2e:docs` and by the
 *                  shared workflow's dedicated `Journeydoc Capture` job, which
 *                  passes `--project docs-capture` explicitly.
 *
 * Letting the root config be picked would therefore make every PR re-shoot the
 * documentation screenshots as a side effect of running the regression suite.
 * Rather than delete or weaken the docs project, `playwright-test-path:
 * tests/e2e` in the caller makes the workflow's FIRST lookup hit this file,
 * which declares only the regression project.
 *
 * ⚠️ The ROOT config must stay exactly as it is. The shared workflow's
 * `Journeydoc Capture (screenshots)` job hard-fails unless the ROOT
 * `playwright.config.ts` literally contains a project named `docs-capture`
 * AND `tests/e2e/docs-screenshots.spec.ts` exists. This file is an ADDITION,
 * never a replacement.
 *
 * ⚠️ `testIgnore` HAS TO BE REPEATED AT PROJECT LEVEL.
 * A project-level `testIgnore` REPLACES the top-level one, it does not merge
 * with it. Both lists below therefore carry the same patterns, so a future
 * reader cannot delete one of them and silently start collecting
 * `docs-screenshots.spec.ts` (which would reshoot the docs) or `base-url.ts`
 * (which exports a helper, not tests — Playwright errors with "no tests found
 * in file").
 *
 * NO globalSetup / storageState
 * -----------------------------
 * Unlike the planix reference, this suite does NOT log into Nextcloud once and
 * share a cookie jar. Portaliq's specs authenticate THEMSELVES, and they have
 * to: the surface under test is the PUBLIC portal (`#[PublicPage]`) behind its
 * own bearer auth edge, not an authenticated Nextcloud app page. Each spec
 * mints its own portal bearer via `POST /portal/api/session/dev-login` and
 * seeds it into the SPA's localStorage; the one place a Nextcloud identity is
 * needed (seeding a `portalMessage` row through OpenRegister's admin object
 * API) uses explicit HTTP Basic. Bolting a globalSetup on top would invent a
 * second auth path that disagrees with the one every spec already uses.
 *
 * ARTIFACT PATHS
 * --------------
 * Report and traces stay under `tests/e2e/…`, matching the root config and the
 * paths already listed in the repo's .gitignore. The shared workflow's upload
 * steps accept `server/apps/<app>/tests/e2e/playwright-report/` alongside the
 * app-root paths, so the report is still downloadable from the run.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { BASE_URL } from './base-url'

const IGNORE = [
	// Owned by the `docs-capture` project in the root config / the shared
	// workflow's Journeydoc Capture job. Never part of a regression run.
	'**/docs-screenshots.spec.ts',
	// Helper modules, not specs.
	'**/base-url.ts',
]

/*
 * ── THE ONE THING THIS GATE DOES NOT RUN ─────────────────────────────────
 *
 * `portal-document-download` › `a subject downloads a file on a row they own`
 * fails against a PRODUCT BUG, not a harness fault: a portal subject can never
 * attach a file on an instance whose OpenRegister register folder has not
 * already been materialised by an authenticated Nextcloud user.
 *
 *   Portaliq: OR file attach failed —
 *   Failed to create file …: Access denied: You do not have permission to
 *   update register entities.
 *
 * `FileService::addFile()` has no `_rbac` parameter, so Portaliq's usual
 * `_rbac: false` trusted-intermediary bypass cannot be applied to it; folder
 * materialisation reaches `RegisterMapper::update()`, which denies a web
 * request that carries no Nextcloud user session. The fix belongs in
 * OpenRegister and widens a security boundary on a file-write path, so it gets
 * its own change rather than riding along with CI enablement.
 *
 *     → ConductionNL/portaliq#31
 *
 * The exclusion is deliberately a TITLE filter, not a file one: the sibling
 * test in the same spec — `a foreign or absent file 404s identically — no
 * existence oracle` — is a passing security assertion and stays in the gate.
 * The test body is untouched: nothing is skipped, no assertion is weakened, no
 * timeout is raised. Delete this filter when #31 closes.
 */
const GREP_INVERT = /a subject downloads a file on a row they own/

export default defineConfig({
	testDir: __dirname,
	// See the header: also repeated on the project below, because a
	// project-level testIgnore replaces rather than extends this list.
	testIgnore: IGNORE,
	// Repeated on the project below for the same reason: a project-level
	// grepInvert takes precedence over this one rather than combining with it.
	grepInvert: GREP_INVERT,
	// The root config allows 180s per test because it targets a shared dev
	// instance under variable load. A CI runner hosts its own `php -S` with
	// PHP_CLI_SERVER_WORKERS=8 and nothing else on it, so the generous
	// allowance is not needed — and a 45-minute job timeout is NO verdict at
	// all, whereas a failed test is. Tightened, not relaxed.
	timeout: 90_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: path.resolve(__dirname, 'playwright-report') }],
		['list'],
	],
	outputDir: path.resolve(__dirname, 'test-results'),

	use: {
		// Single source of truth — see tests/e2e/base-url.ts.
		baseURL: BASE_URL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		navigationTimeout: 60_000,
		actionTimeout: 30_000,
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: IGNORE,
			grepInvert: GREP_INVERT,
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
