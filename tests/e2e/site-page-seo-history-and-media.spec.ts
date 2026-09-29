/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for site-page-seo-history-and-media: a page's search title,
 * description and noindex reach the served HTML without JavaScript, a draft
 * lends nothing to the head, and a restored version waits in the draft.
 * Requires the seeded instance tests/e2e/ci-seed.sh provisions. Written, run manually:
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

	test('restoring Monday puts it in the draft; the live page keeps Friday until published', async ({
		request,
	}) => {
		const slug = `hist-${Date.now()}`
		await seed(request, 'portal', {
			slug,
			title: 'Gemeente Voorbeeld',
			status: 'published',
		})
		const monday = {
			type: 'grid',
			widgets: [
				{
					id: 'w1',
					widgetKey: 'text',
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 2,
					props: { text: 'Monday' },
				},
			],
		}
		const friday = {
			type: 'grid',
			widgets: [
				{
					id: 'w1',
					widgetKey: 'text',
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 2,
					props: { text: 'Friday' },
				},
			],
		}
		const created = await request.post(`${OR_OBJECTS_BASE}/portaliq/page`, {
			headers: HEADERS,
			data: {
				portal: slug,
				route: '/contact',
				title: 'Contact',
				status: 'published',
				body: monday,
			},
		})
		const page = await created.json()
		const id = page.id ?? page['@self']?.id
		await request.put(`${OR_OBJECTS_BASE}/portaliq/page/${id}`, {
			headers: HEADERS,
			data: {
				portal: slug,
				route: '/contact',
				title: 'Contact',
				status: 'published',
				body: friday,
			},
		})

		const history = await (
			await request.get(`/index.php/apps/portaliq/api/pages/${id}/history`, {
				headers: HEADERS,
			})
		).json()
		expect(history.versions.length).toBeGreaterThanOrEqual(2)
		expect(history.versions[0].body.widgets[0].props.text).toBe('Friday')
		const mondayVersion = history.versions.find(
			(v: { body?: { widgets: Array<{ props: { text: string } }> } }) =>
				v.body?.widgets[0].props.text === 'Monday',
		)
		expect(mondayVersion?.restorable).toBe(true)
		expect(mondayVersion?.by).not.toBe('')

		// What the designer's restore writes: the version as the draft.
		await request.put(`${OR_OBJECTS_BASE}/portaliq/page/${id}`, {
			headers: HEADERS,
			data: {
				portal: slug,
				route: '/contact',
				title: 'Contact',
				status: 'published',
				body: friday,
				draftBody: mondayVersion.body,
			},
		})
		const live = await (
			await request.get(
				`/index.php/apps/portaliq/api/content/page?portal=${slug}&route=/contact`,
			)
		).text()
		expect(live).toContain('Friday')
		expect(live).not.toContain('Monday')
	})

	test('a draft library item stays private, and a used image is not deleted', async ({
		request,
	}) => {
		const slug = `media-${Date.now()}`
		await seed(request, 'portal', {
			slug,
			title: 'Gemeente Voorbeeld',
			status: 'published',
		})
		const draft = await (
			await request.post(`${OR_OBJECTS_BASE}/portaliq/media`, {
				headers: HEADERS,
				data: {
					portal: slug,
					title: 'Concept',
					kind: 'file',
					status: 'draft',
				},
			})
		).json()
		const hidden = await request.get(
			`/index.php/apps/portaliq/api/content/media/${draft.id}?portal=${slug}`,
		)
		expect(hidden.status()).toBe(404)

		const noAlt = await request.post(`${OR_OBJECTS_BASE}/portaliq/media`, {
			headers: HEADERS,
			data: {
				portal: slug,
				title: 'Zonder tekst',
				kind: 'image',
				status: 'published',
			},
		})
		expect(noAlt.ok()).toBeFalsy()

		const image = await (
			await request.post(`${OR_OBJECTS_BASE}/portaliq/media`, {
				headers: HEADERS,
				data: {
					portal: slug,
					title: 'Stadhuis',
					kind: 'image',
					status: 'published',
					alt: 'Het stadhuis aan de Markt',
				},
			})
		).json()
		await seed(request, 'page', {
			portal: slug,
			route: '/contact',
			title: 'Contact',
			status: 'published',
			heroImage: `media:${image.id}`,
			body: { type: 'markdown', markdown: 'Tekst' },
		})
		const page = await (
			await request.get(
				`/index.php/apps/portaliq/api/content/page?portal=${slug}&route=/contact`,
			)
		).json()
		expect(page.hero.alt).toBe('Het stadhuis aan de Markt')

		const refused = await request.delete(
			`${OR_OBJECTS_BASE}/portaliq/media/${image.id}`,
			{ headers: HEADERS },
		)
		expect(refused.ok()).toBeFalsy()
		expect(await refused.text()).toContain('/contact')
	})
})
