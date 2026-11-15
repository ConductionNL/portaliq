/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Old portal links land on the site. The React portal retired, and
 * `GET /apps/portaliq/portal` answers 302 to `/apps/portaliq/site` with the
 * same query string, so a bookmark, a mail link or a home-screen icon from
 * before keeps working. A browser carries the fragment over the redirect, so
 * a `#open=` record link still reaches the site, which keeps its target for
 * after the sign-in.
 *
 * Uses the `open-tilburg` portal tests/e2e/fixtures/seed-cms.sh seeds.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
 */

import { expect, test } from '@playwright/test'
import { SITE_PORTAL } from './portal-nav.ts'

const OLD_PORTAL = `/index.php/apps/portaliq/portal?portal=${SITE_PORTAL}`

test.describe('old portal links land on the site (REQ-SRP-048)', () => {
	// @e2e site-portal-parity::a-bookmarked-portal-address
	test('a bookmarked portal address answers 302 to the site with the same query', async ({
		request,
	}) => {
		const answer = await request.get(OLD_PORTAL, { maxRedirects: 0 })

		expect(answer.status()).toBe(302)
		expect(answer.headers().location).toMatch(
			new RegExp(`/apps/portaliq/site\\?portal=${SITE_PORTAL}$`),
		)
	})

	test('a record link on the old address opens the site and keeps its target', async ({
		page,
	}) => {
		await page.goto(`${OLD_PORTAL}#open=a/b/c`)

		await expect(page).toHaveURL(/\/apps\/portaliq\/site/)
		await expect(page.getByTestId('site-root')).toBeVisible()
		// The site takes the fragment off the address and keeps the record for
		// after the sign-in (src/shared/openRecord.js).
		await expect(page).not.toHaveURL(/#open=/)
		await expect
			.poll(() =>
				page.evaluate(() =>
					window.sessionStorage.getItem('portaliq.openRecord'),
				),
			)
			.toBe(JSON.stringify({ app: 'a', collection: 'b', id: 'c' }))
	})
})
