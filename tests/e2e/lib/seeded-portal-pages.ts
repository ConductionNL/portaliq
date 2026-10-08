/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The portalPage rows one spec file seeded, removed again after each test.
 *
 * WHY. The built-in provider serves ONE portalPage row per audience. With
 * several active rows for the same audience it sorts them by row id and takes
 * the first, logging "multiple active portalPage objects for one audience —
 * picking the first, not merging" (lib/Portal/PortalContributionProvider.php).
 * Row ids are UUIDs, so which page wins is a coin toss. A spec that seeds a
 * page for `client` and leaves it behind therefore replaces the page of every
 * spec that runs after it, on the same instance, in the same CI run: the
 * menu shows somebody else's pages and the assertion reads as broken UI.
 * Removing what a test seeded once it is done leaves the next test one page
 * for its audience, its own.
 *
 * site-mijn-switching.spec.ts and site-mijn-omgeving-live.spec.ts solve the
 * same collision with a per-run audience; a spec whose fixture names the
 * audience in several places (writable sets, forms, mandates) uses this
 * instead, so the audience it asserts on stays the one the product uses.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect } from '@playwright/test'

const PORTAL_PAGES = '/apps/openregister/api/objects/portaliq/portalPage'

const ADMIN = Buffer.from('admin:admin').toString('base64')

export class SeededPortalPages {
	private readonly ids: string[] = []

	/**
	 * Remember a created object when it is a portalPage row.
	 *
	 * @param schema The schema the object was created in.
	 * @param created OpenRegister's create response body.
	 */
	track(schema: string, created: Record<string, unknown>): void {
		if (schema !== 'portalPage') {
			return
		}
		const self = (created['@self'] ?? {}) as Record<string, unknown>
		const id = (created.id ?? self.id) as string | undefined
		if (typeof id === 'string' && id !== '') {
			this.ids.push(id)
		}
	}

	/**
	 * Delete every remembered row. A row that is already gone is fine; any
	 * other answer fails the test, because a page left behind would decide
	 * the next spec's contribution.
	 *
	 * @param request The request fixture.
	 */
	async removeAll(request: APIRequestContext): Promise<void> {
		while (this.ids.length > 0) {
			const id = this.ids.pop() as string
			const res = await request.delete(`${PORTAL_PAGES}/${id}`, {
				headers: { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' },
			})
			expect(
				res.ok() || res.status() === 404,
				`the seeded portalPage ${id} must be removed (HTTP ${res.status()})`,
			).toBeTruthy()
		}
	}
}
