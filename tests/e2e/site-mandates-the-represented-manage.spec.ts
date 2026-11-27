/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-mandates-the-represented-manage: a party invites someone by email, the
 * invitee accepts from their own account, and either side ends the mandate.
 * Driven over the same HTTP the "Machtigingen" page (MandatesGiven.vue,
 * MandatesHeld.vue, MandateInviteModal.vue) calls.
 *
 * The invitation secret is only ever mailed (REQ-SMR-003), so the accept half
 * reads it from a mail catcher: set E2E_MAILPIT_URL to the Mailpit the target
 * instance sends to (e.g. http://localhost:8025). Without it those tests skip
 * with that reason; the refusals that need no mail still run.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API_BASE = '/apps/portaliq/portal/api'

const MAILPIT = (process.env.E2E_MAILPIT_URL || '').replace(/\/+$/, '')

const ADMIN = Buffer.from('admin:admin').toString('base64')

const STAFF_HEADERS = {
	Authorization: `Basic ${ADMIN}`,
	'OCS-APIRequest': 'true',
	requesttoken: 'x',
}

type Account = { token: string, subjectRef: string }

/**
 * A provisioned resident signed in through dev-login.
 *
 * @param request The request fixture.
 * @param identityRef A unique identity reference.
 * @param displayName The name the inviter's list shows.
 * @param email The account's email address.
 * @return The bearer and subject reference.
 */
async function signedInResident(
	request: APIRequestContext,
	identityRef: string,
	displayName: string,
	email: string,
): Promise<Account> {
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: STAFF_HEADERS,
		data: {
			audience: 'citizen',
			organisation: 'dev-org',
			identityType: 'digid',
			identityRef,
			displayName,
			email,
		},
	})
	expect(provisioned.ok(), 'staff can provision a portal account').toBeTruthy()
	const { subjectRef } = await provisioned.json()

	const login = await request.post(`${API_BASE}/session/dev-login`, {
		data: { subjectRef, audience: 'citizen', organisation: 'dev-org' },
	})
	expect(login.ok(), 'dev-login must be enabled (see tests/e2e/ci-seed.sh)').toBeTruthy()
	const { token } = await login.json()
	return { token, subjectRef }
}

/**
 * The bearer header of one account.
 *
 * @param account The account.
 * @return The headers.
 */
function as(account: Account): Record<string, string> {
	return { Authorization: `Bearer ${account.token}` }
}

/**
 * Send an invitation and assert the secret is not answered.
 *
 * @param request The request fixture.
 * @param inviter The inviting party's account.
 * @param email The invitee's address.
 * @param label The scope's words.
 * @param expiresAt An optional end day (YYYY-MM-DD).
 */
async function invite(
	request: APIRequestContext,
	inviter: Account,
	email: string,
	label: string,
	expiresAt = '',
): Promise<void> {
	const res = await request.post(`${API_BASE}/mandates/invitations`, {
		headers: as(inviter),
		data: { email, caseTypes: [], label, expiresAt },
	})
	expect(res.status()).toBe(200)
	const body = await res.json()
	expect(body).toEqual({ sent: true })
	expect(JSON.stringify(body)).not.toMatch(/token/i)
}

/**
 * The invitation secret from the newest mail to one address, read from Mailpit.
 *
 * @param request The request fixture.
 * @param email The invitee's address.
 * @return The secret from the `#mandate-invitation=` link.
 */
async function mailedSecret(request: APIRequestContext, email: string): Promise<string> {
	let secret = ''
	await expect.poll(async () => {
		const search = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:"${email}"` } })
		if (!search.ok()) {
			return ''
		}
		const [newest] = (await search.json()).messages || []
		if (!newest) {
			return ''
		}
		const message = await (await request.get(`${MAILPIT}/api/v1/message/${newest.ID}`)).json()
		const match = /#mandate-invitation=([^\s"'&<>]+)/.exec(`${message.HTML || ''} ${message.Text || ''}`)
		secret = match ? decodeURIComponent(match[1]) : ''
		return secret
	}, { message: `a mandate invitation mailed to ${email}`, timeout: 30_000 }).not.toBe('')
	return secret
}

/**
 * The party's own list: mandates it gave and open invitations.
 *
 * @param request The request fixture.
 * @param party The represented party's account.
 * @return The rows.
 */
async function given(request: APIRequestContext, party: Account): Promise<Array<Record<string, unknown>>> {
	const res = await request.get(`${API_BASE}/mandates/given`, { headers: as(party) })
	expect(res.ok()).toBeTruthy()
	return (await res.json()).items
}

/**
 * The mandates one account holds.
 *
 * @param request The request fixture.
 * @param holder The holder's account.
 * @return The rows.
 */
async function held(request: APIRequestContext, holder: Account): Promise<Array<Record<string, unknown>>> {
	const res = await request.get(`${API_BASE}/mandates/held`, { headers: as(holder) })
	expect(res.ok()).toBeTruthy()
	return (await res.json()).items
}

test.describe('site-mandates-the-represented-manage', () => {
	// @e2e portal-mandates::an-end-date-in-the-past-is-refused
	test('an end date in the past is refused and no invitation is sent', async ({ request }) => {
		const stamp = Date.now()
		const bakker = await signedInResident(request, `e2e-bakker-past-${stamp}`, 'H. Bakker', `bakker-past-${stamp}@example.nl`)

		const res = await request.post(`${API_BASE}/mandates/invitations`, {
			headers: as(bakker),
			data: { email: `linda-past-${stamp}@example.nl`, caseTypes: [], label: '', expiresAt: '2026-01-01' },
		})
		expect(res.status()).toBe(400)
		expect(await given(request, bakker)).toEqual([])
	})

	// @e2e portal-mandates::another-companys-mandate-is-not-found
	test("another party's mandate or invitation is not found", async ({ request }) => {
		const stamp = Date.now()
		const bakker = await signedInResident(request, `e2e-bakker-idor-${stamp}`, 'H. Bakker', `bakker-idor-${stamp}@example.nl`)
		const stranger = await signedInResident(request, `e2e-stranger-${stamp}`, 'Iemand Anders', `stranger-${stamp}@example.nl`)
		await invite(request, bakker, `linda-idor-${stamp}@example.nl`, '')
		const [invitation] = await given(request, bakker)
		expect(invitation.kind).toBe('invitation')

		const revoke = await request.delete(`${API_BASE}/mandates/invitations/${invitation.id}`, { headers: as(stranger) })
		expect(revoke.status()).toBe(404)
		expect((await given(request, bakker)).map((row) => row.state)).toEqual(['pending'])
	})

	// @e2e portal-mandates::the-invitation-form
	// @e2e portal-mandates::a-private-person-authorises-their-daughter
	// @e2e portal-mandates::the-inviter-cannot-accept-their-own-invitation
	// @e2e portal-mandates::pending-to-active
	// @e2e portal-mandates::active-to-revoked
	test('invite, accept as a second account, revoke', async ({ request }) => {
		test.skip(MAILPIT === '', 'set E2E_MAILPIT_URL to the mail catcher the instance sends to: the secret is only mailed')
		const stamp = Date.now()
		const lindaEmail = `linda-${stamp}@example.nl`
		const bakker = await signedInResident(request, `e2e-bakker-${stamp}`, 'H. Bakker', `bakker-${stamp}@example.nl`)
		const linda = await signedInResident(request, `e2e-linda-${stamp}`, 'Linda Bakker', lindaEmail)

		await invite(request, bakker, lindaEmail, '', '2027-06-30')
		const pending = await given(request, bakker)
		expect(pending).toHaveLength(1)
		expect(pending[0]).toMatchObject({ kind: 'invitation', state: 'pending', email: lindaEmail })

		const secret = await mailedSecret(request, lindaEmail)

		// The inviter opens their own link: nothing is written.
		const own = await request.post(`${API_BASE}/mandates/invitations/accept`, { headers: as(bakker), data: { token: secret } })
		expect(own.status()).toBe(409)
		expect((await own.json()).error).toBe('own_invitation')

		const accepted = await request.post(`${API_BASE}/mandates/invitations/accept`, { headers: as(linda), data: { token: secret } })
		expect(accepted.status()).toBe(200)
		expect((await accepted.json()).holder).toBe(`subject:${linda.subjectRef}`)

		const active = await given(request, bakker)
		expect(active).toHaveLength(1)
		expect(active[0]).toMatchObject({ kind: 'mandate', state: 'active' })
		expect(await held(request, linda)).toHaveLength(1)

		// A spent invitation cannot be accepted again.
		const again = await request.post(`${API_BASE}/mandates/invitations/accept`, { headers: as(linda), data: { token: secret } })
		expect(again.status()).toBe(404)

		const revoked = await request.post(`${API_BASE}/mandates/${active[0].id}/revoke`, { headers: as(bakker) })
		expect(revoked.status()).toBe(204)
		expect(await given(request, bakker)).toEqual([])
		expect(await held(request, linda)).toEqual([])
	})

	// @e2e portal-mandates::linda-stops-acting-for-her-father
	test('the holder stops their own mandate', async ({ request }) => {
		test.skip(MAILPIT === '', 'set E2E_MAILPIT_URL to the mail catcher the instance sends to: the secret is only mailed')
		const stamp = Date.now()
		const lindaEmail = `linda-stop-${stamp}@example.nl`
		const bakker = await signedInResident(request, `e2e-bakker-stop-${stamp}`, 'H. Bakker', `bakker-stop-${stamp}@example.nl`)
		const linda = await signedInResident(request, `e2e-linda-stop-${stamp}`, 'Linda Bakker', lindaEmail)

		await invite(request, bakker, lindaEmail, '')
		const secret = await mailedSecret(request, lindaEmail)
		const accepted = await request.post(`${API_BASE}/mandates/invitations/accept`, { headers: as(linda), data: { token: secret } })
		expect(accepted.status()).toBe(200)
		const { id } = await accepted.json()

		const stopped = await request.post(`${API_BASE}/mandates/held/${id}/stop`, { headers: as(linda) })
		expect(stopped.status()).toBe(204)
		expect(await held(request, linda)).toEqual([])
		expect(await given(request, bakker)).toEqual([])
	})
})
