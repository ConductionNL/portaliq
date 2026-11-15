/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * operate-maintenance-notice: a notice written in OpenRegister shows above
 * every page of the public site while its window runs, an expired one does
 * not, and a visitor who closes one keeps it closed for the visit while a
 * second one still shows.
 *
 * Runs on the seed of seed-cms.sh (portal open-tilburg). The notices are
 * written as the administrator through OpenRegister's objects API and
 * removed afterwards. The draft case is pinned by
 * PortalNoticeReaderTest::testDraftIsNotActive; the refusal for a user
 * outside the editor groups by PageEditorServiceTest.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ADMIN_HEADERS, APP, ENABLED, OR_OBJECTS } from './lib/traffic.ts'

const created: string[] = []

/**
 * Write a notice for the portal as the administrator.
 *
 * @param request The request context.
 * @param notice The fields that differ from a running site warning.
 * @return The new notice's id.
 */
async function writeNotice(
	request: APIRequestContext,
	notice: Record<string, unknown>,
): Promise<string> {
	const hour = 3600 * 1000
	const res = await request.post(`${OR_OBJECTS}/portalNotice`, {
		headers: ADMIN_HEADERS,
		data: {
			portal: ENABLED,
			message: 'Saturday from 22:00 to 02:00 you cannot submit requests.',
			level: 'warning',
			startsAt: new Date(Date.now() - hour).toISOString(),
			endsAt: new Date(Date.now() + hour).toISOString(),
			surfaces: ['site', 'portal'],
			status: 'published',
			...notice,
		},
	})
	expect(res.ok()).toBeTruthy()
	const body = await res.json()
	const id = String(body.id ?? body['@self']?.id ?? '')
	created.push(id)
	return id
}

test.afterAll(async ({ request }) => {
	for (const id of created) {
		await request.delete(`${OR_OBJECTS}/portalNotice/${id}`, {
			headers: ADMIN_HEADERS,
		})
	}
})

test('visitors read the maintenance notice, and an expired one is gone', async ({
	page,
	request,
}) => {
	await writeNotice(request, {})
	await writeNotice(request, {
		message: 'This notice has ended.',
		startsAt: new Date(Date.now() - 7200 * 1000).toISOString(),
		endsAt: new Date(Date.now() - 3600 * 1000).toISOString(),
	})

	const site = await request.get(`${APP}/api/content/site?portal=${ENABLED}`)
	const messages = ((await site.json()).notices ?? []).map(
		(n: { message: string }) => n.message,
	)
	expect(messages).toContain(
		'Saturday from 22:00 to 02:00 you cannot submit requests.',
	)
	expect(messages).not.toContain('This notice has ended.')

	await page.goto(`${APP}/site?portal=${ENABLED}`)
	await expect(page.getByTestId('site-notices')).toContainText(
		'Saturday from 22:00 to 02:00',
	)
	await expect(page.getByTestId('site-notices')).not.toContainText(
		'This notice has ended.',
	)
})

test('a closed notice stays closed while browsing, and a second one still shows', async ({
	page,
	request,
}) => {
	await writeNotice(request, {
		message: 'The phone line is closed on Monday.',
		level: 'info',
	})

	await page.goto(`${APP}/site?portal=${ENABLED}`)
	const first = page
		.getByTestId('site-notice')
		.filter({ hasText: 'Saturday from 22:00 to 02:00' })
	await first.getByRole('button').click()
	await expect(first).toHaveCount(0)

	await page.reload()
	await expect(page.getByTestId('site-notices')).not.toContainText(
		'Saturday from 22:00 to 02:00',
	)
	await expect(page.getByTestId('site-notices')).toContainText(
		'The phone line is closed on Monday.',
	)
})

test('an end before the start is refused', async ({ request }) => {
	const res = await request.post(`${OR_OBJECTS}/portalNotice`, {
		headers: ADMIN_HEADERS,
		data: {
			portal: ENABLED,
			message: 'Backwards',
			level: 'info',
			startsAt: new Date(Date.now() + 3600 * 1000).toISOString(),
			endsAt: new Date(Date.now()).toISOString(),
			surfaces: ['site'],
			status: 'draft',
		},
	})
	expect(res.ok()).toBeFalsy()
	expect(await res.text()).toContain('The end must be after the start.')
})
