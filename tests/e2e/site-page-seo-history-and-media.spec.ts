/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for site-page-seo-history-and-media part 1: a page's search
 * title, description and noindex reach the served HTML without JavaScript,
 * and a draft lends nothing to the head. Requires the seeded instance
 * tests/e2e/ci-seed.sh provisions. Written, run manually:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 npx playwright test site-page-seo
 *
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const ADMIN = Buffer.from('admin:admin').toString('base64')
const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const HEADERS = { Authorization: `Basic ${ADMIN}`, 'OCS-APIRequest': 'true' }

/**
 * Create one object in portaliq's register.
 *
 * @param request The API context.
 * @param schema The schema slug.
 * @param data The object.
 */
async function seed(
	request: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<void> {
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/${schema}`, {
		headers: HEADERS,
		data,
	})
	expect(res.ok(), `seed ${schema}`).toBeTruthy()
}

test.describe('site-page-seo-history-and-media', () => {
	test('the served HTML carries the page head, and a draft lends nothing', async ({
		request,
	}) => {
		const slug = `seo-${Date.now()}`
		await seed(request, 'portal', {
			slug,
			title: 'Gemeente Voorbeeld',
			status: 'published',
		})
		await seed(request, 'page', {
			portal: slug,
			route: '/afval',
			title: 'Afval',
			status: 'published',
			seoTitle: 'Afval en recycling',
			seoDescription: 'Wanneer de container wordt geleegd.',
			body: { type: 'markdown', markdown: 'Tekst' },
		})
		await seed(request, 'page', {
			portal: slug,
			route: '/concept',
			title: 'Geheim concept',
			status: 'draft',
			body: { type: 'markdown', markdown: 'Nog niet af' },
		})

		const live = await (
			await request.get(
				`/index.php/apps/portaliq/site?portal=${slug}&route=/afval`,
			)
		).text()
		expect(live).toContain(
			'<title>Afval en recycling - Gemeente Voorbeeld</title>',
		)
		expect(live).toContain(
			'<meta name="description" content="Wanneer de container wordt geleegd.">',
		)
		expect(live).toContain('<meta name="robots" content="index, follow">')

		const draft = await (
			await request.get(
				`/index.php/apps/portaliq/site?portal=${slug}&route=/concept`,
			)
		).text()
		expect(draft).toContain('<title>Gemeente Voorbeeld</title>')
		expect(draft).toContain('<meta name="robots" content="noindex">')
		expect(draft).not.toContain('Geheim concept')
	})
})
