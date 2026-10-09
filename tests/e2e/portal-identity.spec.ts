/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-identity-from-the-admin: an anonymous visitor's site page carries one
 * tab icon, and that icon loads without a session.
 *
 * @e2e REQ-PIA-002
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-site-head-and-the-hero-use-the-portals-images-req-pia-002
 */

import { expect, test } from '@playwright/test'
import { siteAddress } from './portal-nav.ts'

test('an anonymous visitor gets one tab icon that loads without a session', async ({ browser }) => {
	// A fresh context: no cookies, no Nextcloud session.
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto(siteAddress('/'))

	const icons = page.locator('head link[rel="icon"]')
	await expect(icons).toHaveCount(1)
	const href = await icons.first().getAttribute('href')
	expect(href).toBeTruthy()

	const answer = await context.request.get(new URL(String(href), page.url()).toString())
	expect(answer.status()).toBe(200)

	await context.close()
})
