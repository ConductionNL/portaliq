/*
 * SPDX-FileCopyrightText: 2026 Nextcloud App Template Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Documentation screenshot capture suite — portaliq.
 *
 * This spec is *not* a regression test — it drives the app's UI
 * through every flow documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default regression run via the `docs-capture`
 * project flag in `playwright.config.ts` so PR pipelines don't
 * reshoot screenshots on every push.
 *
 * The tests below are SKELETONS — selectors are TODOs the team fills
 * in once the relevant Vue components have stable `data-testid`
 * attributes. Add a story by appending a new `test(...)` block — see
 * `/journeydoc-add-story`. Add testids with `/journeydoc-instrument`.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/**
 * Sign in before capturing anything.
 *
 * THIS SPEC HAD NO LOGIN AT ALL, and the two tracks failed differently because
 * of it — one loudly, one silently:
 *
 *   - the admin track waited 90s for `#portaliq-settings .settings-section`,
 *     which an anonymous visitor can never be shown, and timed out;
 *   - the user track "passed" by screenshotting whatever `/apps/portaliq/`
 *     renders with no session — Nextcloud's LOGIN PAGE — and filing it as
 *     `01-first-launch.png`. A green test producing a documentation image of
 *     the wrong page is worse than the red one beside it, because nothing
 *     about it asks to be looked at.
 *
 * So the login is shared, and each track asserts it actually landed somewhere
 * authenticated before the shutter opens.
 */
async function signIn(page: Page): Promise<void> {
	// IDEMPOTENT ON PURPOSE. Playwright runs the outer `beforeEach` and then the
	// describe-level one, so this is called twice for the admin track. Posting
	// the login form again on an already-authenticated session does not sign in
	// twice — it re-enters a flow Nextcloud has already completed, and the
	// second pass is where the admin capture was hanging.
	await page.goto('/index.php/login')
	if (/\/login(\?|$|\/)/.test(page.url()) === false) {
		return
	}
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	// The global header renders only on authenticated pages.
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
	expect(
		/\/login(\?|$|\/)/.test(page.url()),
		`sign-in failed — still on ${page.url()}, so every capture below would `
			+ 'photograph the login page and report success',
	).toBe(false)
}

const SHOT_ROOT = path.resolve(
	__dirname,
	'..',
	'..',
	'docs',
	'static',
	'screenshots',
	'tutorials',
)

/**
 * Save a screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(
	page: Page,
	track: 'user' | 'admin',
	file: string,
): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

// Capture flows are independent — each test re-navigates from
// `/apps/portaliq/` so a selector miss on one doesn't cascade.
// Selector misses are the expected first-run failure mode (UI markup
// drifts faster than docs); failures land per-test in `test-results/`
// rather than killing the suite.
test.describe.configure({ mode: 'default' })

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
	await signIn(page)
	await page.goto('/apps/portaliq/')
	// Assert the APP mounted, not merely that a document loaded — the whole
	// point of a first-launch screenshot is that it shows the app.
	await page.locator('#content > *').first().waitFor({ state: 'visible' })
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('UN first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		/* TODO: see /journeydoc-add-story — capture each numbered step.
		   Add data-testids first via /journeydoc-instrument. */
		await shoot(page, 'user', '01-first-launch.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test.beforeEach(async ({ page }) => {
		await signIn(page)
		await page.goto('/settings/admin/portaliq', {
			waitUntil: 'domcontentloaded',
		})
		// Wait for CONTENT, not for the container. `#portaliq-settings` is the
		// empty mount div `templates/settings/admin.php` emits — it is present
		// in the very first byte of HTML, so waiting on it would be satisfied
		// before Vue has rendered anything and the capture would be a
		// screenshot of an empty page. `NcSettingsSection` renders the heading
		// only after `src/settings.js` has mounted AdminRoot.vue.
		await page
			.locator('#portaliq-settings .settings-section')
			.first()
			.waitFor({ state: 'visible' })
	})

	test('AN admin-settings', async ({ page }) => {
		// docs/tutorials/admin/01-admin-settings.md
		/* TODO: see /journeydoc-add-story — capture each numbered step.
		   Add data-testids first via /journeydoc-instrument. */
		await shoot(page, 'admin', '01-admin-settings.png')
	})
})
