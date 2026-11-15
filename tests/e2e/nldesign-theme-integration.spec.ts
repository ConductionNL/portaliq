/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for nldesign-theme-integration part 1: an administrator reads
 * the house styles a portal can wear, each with a readability verdict, and an
 * anonymous caller is refused. Requires a seeded instance with thematiq
 * installed (tests/e2e/ci-seed.sh). Written, run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test nldesign-theme-integration
 *
 * The verdict arithmetic and the confirm-before-saving rule are pinned by
 * tests/Unit/Service/Theme/PortalThemeChoiceTest.php and
 * tests/portal-theme-choice.spec.mjs.
 *
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */

import { expect, test } from '@playwright/test'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'

test.describe('nldesign-theme-integration', () => {
	test('an administrator reads the sets a portal can wear, an anonymous caller cannot', async ({
		request,
	}) => {
		const slug = `theme-${Date.now()}`
		const created = await request.post(`${OR_OBJECTS_BASE}/portaliq/portal`, {
			headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
			data: { slug, title: 'Thema-test', status: 'draft' },
		})
		expect(created.ok()).toBeTruthy()

		const listed = await request.get(
			`/apps/portaliq/api/portals/${slug}/theme`,
			{
				headers: {
					Authorization: `Basic ${ADMIN}`,
					'OCS-APIRequest': 'true',
				},
			},
		)
		expect(listed.ok()).toBeTruthy()
		const body = await listed.json()
		expect(Array.isArray(body.sets)).toBeTruthy()
		for (const set of body.sets) {
			expect(typeof set.verdict.measured).toBe('number')
		}

		const anonymous = await request.get(
			`/apps/portaliq/api/portals/${slug}/theme`,
		)
		expect(anonymous.ok()).toBeFalsy()
	})
})
