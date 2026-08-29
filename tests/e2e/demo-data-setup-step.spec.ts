/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-111 — the demo-data setup step, exercised against a running instance.
 *
 * WHY THIS EXISTS. The programme that added demo data to this fleet shipped a
 * defect that every unit test passed: the import reported success and seeded
 * ZERO of the descriptor's objects. Unit tests could not see it — they mock
 * the import service, so they validate the CALL and never its effect. The
 * assertion that matters here is therefore not "the endpoint answers 200" but
 * that the response NAMES WHAT LANDED.
 *
 * WHY A FORM LOGIN AND NOT storageState. This suite's config declares no
 * storage state on purpose (see the note on loginToNextcloud in
 * app-shell-and-admin.spec.ts); adding one would change the starting context
 * of every existing portal spec. So this logs in the same way its siblings do.
 *
 * WHAT THIS DELIBERATELY DOES NOT ASSERT. That the demo-data step is FIRST
 * (ADR-111 rule 4) is a property of the bundled manifest, not of anything
 * served, so it is not observable from here. Gate 100 checks it statically.
 */
import { test, expect, type Page } from '@playwright/test'

const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

const APP_BASE = '/index.php/apps/portaliq'

/**
 * Log into Nextcloud through the real login form.
 *
 * @param page The page to authenticate.
 * @param user Username.
 * @param pass Password.
 */
async function loginToNextcloud(
	page: Page,
	user: string,
	pass: string,
): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
	expect(
		/\/login(\?|$|\/)/.test(page.url()),
		`login as ${user} failed — still on ${page.url()}`,
	).toBe(false)
}

/** One authenticated JSON call issued from inside the logged-in admin page. */
async function api(
	page: Page,
	method: string,
	apiPath: string,
): Promise<{ status: number; json: any }> {
	return await page.evaluate(
		async ({ method, apiPath }) => {
			const res = await fetch(apiPath, {
				method,
				headers: {
					'Content-Type': 'application/json',
					// eslint-disable-next-line no-undef
					requesttoken: (window as any).OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
			})
			let json: any = null
			try {
				json = await res.json()
			} catch {
				json = null
			}
			return { status: res.status, json }
		},
		{ method, apiPath },
	)
}

test.describe.configure({ mode: 'serial' })

test.describe('ADR-111 demo data', () => {
	test.beforeEach(async ({ page }) => {
		await loginToNextcloud(page, ADMIN_USER, ADMIN_PASS)
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await page.waitForFunction(() => (window as any).OC?.requestToken, null, {
			timeout: 15_000,
		})
	})

	test('setup status reports the demo-data step, so the wizard can offer it', async ({
		page,
	}) => {
		const res = await api(page, 'GET', `${APP_BASE}/api/setup/status`)

		expect(res.status, 'setup/status must answer an authenticated admin').toBe(
			200,
		)

		// A step the endpoint never MENTIONS resolves to `done: false` forever —
		// no operator action can clear it, and the wizard then covers the app in
		// every fresh browser context. Absence is the defect, not "not done".
		expect(
			Object.keys(res.json?.steps ?? {}),
			'setup/status must report a demo-data step',
		).toContain('demo-data')
	})

	test('installing the demo data reports HOW MUCH landed, not just success', async ({
		page,
	}) => {
		// A REAL IMPORT, NOT A STUB. Measured elsewhere in the fleet at 42.8s
		// (dossiq) and 49.6s (shillinq): legitimately slow, and the only check
		// that the install WROTE something.
		test.slow()

		const res = await api(
			page,
			'POST',
			`${APP_BASE}/api/setup/action/install-demo-data`,
		)

		expect(res.status, 'the action must pass the admin-only middleware').toBe(
			200,
		)
		expect(
			res.json?.success,
			`install failed: ${JSON.stringify(res.json)}`,
		).toBe(true)

		// THE COUNTS ARE THE ASSERTION. "Demo data installed" with no numbers is
		// indistinguishable from an import that wrote nothing.
		const message = String(res.json?.message ?? '')
		const numbers = (message.match(/\d+/g) ?? []).map(Number)

		expect(
			numbers.some((n) => n > 0),
			`the install message must name a non-zero object count; got: "${message}"`,
		).toBe(true)
	})

	test('re-installing is safe, because the step promises it is', async ({
		page,
	}) => {
		// The step body tells the operator it is "safe to run more than once".
		// That sentence is a contract; this asserts the server keeps it.
		const again = await api(
			page,
			'POST',
			`${APP_BASE}/api/setup/action/install-demo-data`,
		)

		expect(again.status).toBe(200)
		expect(
			again.json?.success,
			`a second install must not fail: ${JSON.stringify(again.json)}`,
		).toBe(true)
	})
})
