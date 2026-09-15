/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from portaliq's `lib/Settings/connections.json`, with `app` equal to
 * `portaliq`. Portaliq writes no row: a geography save asks integriq to
 * resolve again and then reports what the saved settings say, and integriq
 * decides the status. So this spec needs integriq installed and synced, and
 * reads the rows from
 * `/apps/openregister/api/objects/integriq/app_connection?app=portaliq`.
 *
 * `app` is a BARE filter key. The objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * WHAT A RED HERE USUALLY MEANS. An empty list in the first test means
 * integriq has not synced the declaration, or refused it whole.
 *
 * The only write is portaliq's own geography provider, read first and put
 * back in a `finally`. Provider `none` fetches nothing and stores no region,
 * so the few seconds it holds touch no visitor data.
 *
 * Written, not yet run: the CI instance needs integriq on `development`
 * (tasks.md 5.1).
 *
 * Locale: nothing forces the E2E language, so statuses are read from the API
 * and rows are found by their declared titles, which are not translated.
 *
 * @e2e openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#the-page-lists-only-the-rows-of-portaliq
 * @e2e openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#add-integration-goes-to-integriq
 * @e2e openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#a-geography-save-shows-on-the-page
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'

/** Nextcloud admin, as exported by the shared quality.yml Playwright step. */
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/** Portaliq's app root, relative to the base URL. */
const APP = '/index.php/apps/portaliq'

/** Integriq's objects endpoint for portaliq's connection rows. */
const CONNECTIONS_API = '/index.php/apps/openregister/api/objects/integriq/app_connection?app=portaliq&_limit=50'

/** Portaliq's settings endpoint, admin only, which carries the geography block. */
const SETTINGS_API = `${APP}/api/settings`

/** The declared keys and titles, in declared order. */
const DECLARED = [
	{ key: 'geo-db', title: 'Visitor geography database' },
	{ key: 'oidc', title: 'Login brokers' },
]

/**
 * An admin API context over Basic auth, with an empty cookie jar.
 *
 * `OCS-APIRequest` lets the write verbs pass Nextcloud's CSRF check. The empty
 * jar is asked for explicitly, for the reason tests/e2e/app-shell-and-admin.spec.ts
 * gives: an inherited session cookie would outrank the Basic header.
 *
 * @return The request context.
 */
async function adminApi(): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL: BASE_URL,
		extraHTTPHeaders: {
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
			Authorization: `Basic ${Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')}`,
		},
		storageState: { cookies: [], origins: [] },
	})
}

/**
 * Portaliq's connection rows, keyed by connection key.
 *
 * @param api An admin request context.
 * @return The rows by key.
 */
async function rowsByKey(api: APIRequestContext): Promise<Record<string, Record<string, unknown>>> {
	const res = await api.get(CONNECTIONS_API)
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, Record<string, unknown>> = {}
	for (const row of (body.results ?? []) as Record<string, unknown>[]) {
		// A row from another app here means the bare filter was dropped.
		expect(String(row.app), 'a connection row from another app').toBe('portaliq')
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * The geography row's status and message as one string, or '' when it cannot be read.
 *
 * Reads without asserting: a throw inside `expect.poll` ends the poll instead
 * of retrying it.
 *
 * @param api An admin request context.
 * @return `{status} {statusMessage}`.
 */
async function geoRow(api: APIRequestContext): Promise<string> {
	const list = await api.get(CONNECTIONS_API)
	const rows = list.ok() ? ((await list.json()).results ?? []) : []
	const row = rows.find((r: Record<string, unknown>) => r.key === 'geo-db' && r.app === 'portaliq')
	return `${String(row?.status ?? '')} ${String(row?.statusMessage ?? '')}`
}

/**
 * Log into Nextcloud through the real login form.
 *
 * @param page The page to authenticate.
 */
async function loginAsAdmin(page: Page): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 60_000 })
}

/**
 * Open the Integrations page the way its menu entry does, with the preset.
 *
 * @param page The Playwright page, signed in as admin.
 */
async function openIntegrations(page: Page): Promise<void> {
	await page.goto(`${APP}/settings/integrations?app=portaliq`, { timeout: 60_000 })
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Integrations over the connection registry', () => {
	test('lists the two declared connections, all of them portaliq\'s', async ({ page }) => {
		const api = await adminApi()
		try {
			const byKey = await rowsByKey(api)
			expect(Object.keys(byKey).sort()).toEqual(DECLARED.map((d) => d.key).sort())
			expect(String(byKey['geo-db']?.settingsUrl ?? '')).toBe('/settings/admin/portaliq#section-visitor-geography')
			expect(String(byKey.oidc?.settingsUrl ?? '')).toBe('/settings/admin/portaliq#section-portal-auth-edge')
		} finally {
			await api.dispose()
		}

		await loginAsAdmin(page)
		await openIntegrations(page)
		for (const { title } of DECLARED) {
			await expect(page.getByRole('row', { name: new RegExp(title, 'i') })).toHaveCount(1)
		}
	})

	test('reads the switched-off message once provider none is saved', async () => {
		const api = await adminApi()
		try {
			const before = await api.get(SETTINGS_API)
			expect(before.ok(), `settings read -> ${before.status()}`).toBeTruthy()
			const previous = String((await before.json())?.traffic_geo?.provider ?? 'dbip')
			test.skip(previous === 'none', 'This instance already runs without geography, so there is no change to observe.')

			try {
				// The save sends ConnectionRefreshRequestedEvent, then a report
				// that geography is switched off.
				const saved = await api.put(SETTINGS_API, { data: { traffic_geo: { provider: 'none' } } })
				expect(saved.ok(), `settings save -> ${saved.status()}`).toBeTruthy()

				await expect
					.poll(() => geoRow(api), { timeout: 15_000 })
					.toBe('unconfigured Geography is switched off. No database is fetched and no region is stored.')
			} finally {
				// Put the VALUE back. The restore is a save too, so it refreshes the row again.
				await api.put(SETTINGS_API, { data: { traffic_geo: { provider: previous } } })
			}

			await expect.poll(() => geoRow(api), { timeout: 15_000 }).not.toMatch(/switched off/)
		} finally {
			await api.dispose()
		}
	})

	test('sends Add integration to integriq instead of offering a form', async ({ page }) => {
		await loginAsAdmin(page)
		await openIntegrations(page)

		// No generic Add button: a row nothing declared has nothing to check.
		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		// The action lives in the overflow menu. English and Dutch are the two
		// catalogues this change ships, and nothing forces the E2E locale.
		await page.locator('[data-testid="cn-actions"] button').first().click()
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=portaliq&link=1$/, { timeout: 30_000 }),
			page.getByRole('menuitem', { name: /Add integration|Integratie toevoegen/i }).click(),
		])
	})
})
