/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * tasks-reminders-after-the-deadline: an `overdue` row in openregister's
 * delivery ledger reaches the resident's inbox worded as overdue.
 *
 * OpenRegister does not record `overdue` rows yet (its reminder listener
 * turns only a preBreach rung into a party delivery), so the row is seeded
 * straight into the ledger through its own mapper inside the container. The
 * job then runs once through `occ background-job:execute`. Needs
 * E2E_CONTAINER; skips without it. Body and mail wording are pinned by
 * PortalTaskDeliveryJobTest.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { CONTAINER, occ, runJob } from './lib/traffic.ts'

const API_BASE = '/apps/portaliq/portal/api'

const STAFF_HEADERS = {
	Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
	'OCS-APIRequest': 'true',
	requesttoken: 'x',
}

/**
 * A signed-in resident, returning their subject reference and bearer.
 *
 * @param request The request fixture.
 * @param stamp A unique suffix.
 * @return The subject reference and the bearer.
 */
async function signedInResident(
	request: APIRequestContext,
	stamp: number,
): Promise<{ subjectRef: string; token: string }> {
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: STAFF_HEADERS,
		data: {
			audience: 'client',
			organisation: 'dev-org',
			identityType: 'digid',
			identityRef: `bsn-${stamp}`,
			displayName: 'Inwoner Jansen',
		},
	})
	expect(provisioned.ok()).toBeTruthy()
	const { subjectRef } = await provisioned.json()

	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'client', organisation: 'dev-org' },
	})
	expect(
		login.ok(),
		'dev-login must be enabled (see tests/e2e/ci-seed.sh)',
	).toBeTruthy()
	const { token } = await login.json()
	return { subjectRef, token }
}

/**
 * Seed one pending `overdue` row for the party through openregister's own
 * mapper, the way the ledger writes it.
 *
 * @param subjectRef The party's subject reference.
 * @param stamp A unique suffix.
 */
function seedOverdueRow(subjectRef: string, stamp: number): void {
	const php = `
require '/var/www/html/lib/base.php';
$row = new \\OCA\\OpenRegister\\Db\\PortalTaskDelivery();
$row->setUuid('e2e-overdue-${stamp}');
$row->setTaskUuid('e2e-task-${stamp}');
$row->setPartyReference('party:' . ${JSON.stringify(subjectRef)});
$row->setChannel('portal-inbox');
$row->setKind('overdue');
$row->setState('pending');
$row->setMessage(['taskUuid' => 'e2e-task-${stamp}', 'title' => 'Send your latest payslip', 'dueAt' => '2026-09-01T12:00:00+00:00']);
$row->setRequestedAt(new \\DateTime());
$row->setCreated(new \\DateTime());
\\OCP\\Server::get(\\OCA\\OpenRegister\\Db\\PortalTaskDeliveryMapper::class)->insert($row);
`
	execFileSync('docker', ['exec', '-u', 'www-data', CONTAINER, 'php', '-r', php], {
		encoding: 'utf8',
		timeout: 60_000,
	})
}

test.describe('tasks-reminders-after-the-deadline', () => {
	test.skip(
		CONTAINER === '',
		'needs E2E_CONTAINER to seed the ledger and run the job',
	)

	test('a missed deadline reaches the inbox worded as overdue', async ({
		request,
	}) => {
		expect(occ('app:list', '--output=json')).toContain('openregister')
		const stamp = Date.now()
		const { subjectRef, token } = await signedInResident(request, stamp)

		seedOverdueRow(subjectRef, stamp)
		runJob('PortalTaskDeliveryJob')

		const inbox = await request.get(`${API_BASE}/inbox`, {
			headers: { Authorization: `Bearer ${token}` },
		})
		expect(inbox.ok()).toBeTruthy()
		const { messages } = await inbox.json()
		const overdue = (messages as Array<Record<string, unknown>>).find(
			(message) => message.deliveryUuid === `e2e-overdue-${stamp}`,
		)
		expect(overdue, 'the overdue message was written').toBeTruthy()
		expect(String(overdue!.subject)).toContain('Send your latest payslip')
		expect(String(overdue!.subject)).toContain('Your task is overdue')
		expect(String(overdue!.body)).toContain('01-09-2026')
		expect(overdue!.taskUuid).toBe(`e2e-task-${stamp}`)
	})
})
