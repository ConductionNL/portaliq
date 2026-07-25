/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression e2e for portal-oidc-broker-login (T13). Closes the `@e2e exclude`
 * markers in openspec/specs/supplier-portal/spec.md for:
 *   - "Start on an unconfigured org/provider fails closed"
 *   - "Every validation failure is an identical generic error" (the
 *     state/CSRF-replay guard, exercised end-to-end against the running API)
 *   - "A configured provider is offered; an unconfigured one is not" (the
 *     SPA's login-button rendering, driven through a real browser)
 *
 * Two groups of tests:
 *
 * 1. FAIL-CLOSED tests (always run, no setup): `oidc/start` on an org/provider
 *    with no configured broker returns the generic error and issues no
 *    redirect; `oidc/callback` with a missing/unknown `state` returns the
 *    SAME generic error. These require nothing beyond the app being
 *    installed — no broker, no org config.
 *
 * 2. HAPPY-PATH test (skipped unless explicitly enabled): drives a REAL
 *    start→callback round trip against a tiny local stub OIDC broker (plain
 *    Node `http`, an ephemeral RSA keypair via `node:crypto` — no new
 *    dependency) standing in for a broker's acceptance environment
 *    (design.md: "built and fully tested against a broker's acceptance
 *    environment"), then asserts the SPA lands authenticated and the login
 *    button for the configured provider renders (and an unconfigured one
 *    does not).
 *
 *    This group needs the target Organisation's OIDC broker config written
 *    BEFORE the run (this change does not add an admin UI for that — it is
 *    operational config via `IAppConfig`, same as the pre-existing
 *    `org_presentation_<uuid>` white-label overrides). From the Nextcloud
 *    container:
 *
 *      OCC="php occ" # or `docker exec <container> php occ`
 *      ORG_UUID=<the e2e org's OpenRegister Organisation uuid>
 *      $OCC config:app:set portaliq "org_presentation_${ORG_UUID}" --value='{
 *        "oidc": {
 *          "eherkenning": {
 *            "issuer": "http://host.docker.internal:4180",
 *            "clientId": "e2e-rp-client"
 *          }
 *        }
 *      }'
 *      $OCC config:app:set portaliq "oidc_secret_${ORG_UUID}_eherkenning" \
 *        --value="e2e-stub-secret" --sensitive
 *
 *    Then run with the stub broker's env wired in:
 *
 *      NEXTCLOUD_URL=http://localhost:8080 \
 *      OIDC_E2E_LIVE=1 \
 *      OIDC_E2E_ORG_SLUG=e2e-org \
 *      OIDC_E2E_ISSUER=http://host.docker.internal:4180 \
 *      OIDC_E2E_CLIENT_ID=e2e-rp-client \
 *      npx playwright test portal-oidc-broker-login
 *
 * NOT RUN as part of this change's local verification (the apply pass has no
 * live Nextcloud instance with an Organisation + broker config wired up) —
 * stated honestly, matching tests/e2e/portal-session-refresh.spec.ts's own
 * disclosure. Group 1 (fail-closed) needs no such setup and is safe to run
 * against any instance with the app installed.
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T13
 * @spec openspec/specs/supplier-portal/spec.md#start-on-an-unconfigured-orgprovider-fails-closed
 * @spec openspec/specs/supplier-portal/spec.md#every-validation-failure-is-an-identical-generic-error
 * @spec openspec/specs/supplier-portal/spec.md#a-configured-provider-is-offered-an-unconfigured-one-is-not
 */

import { test, expect } from '@playwright/test'
import * as http from 'node:http'
import * as crypto from 'node:crypto'

const API_BASE = '/apps/portaliq/portal/api'
const LIVE = process.env.OIDC_E2E_LIVE === '1'

test.describe('portal-oidc-broker-login — fail-closed (no setup required)', () => {
	test('start on an unconfigured org/provider fails closed with no redirect', async ({ request }) => {
		const res = await request.get(`${API_BASE}/session/oidc/start`, {
			params: { org: `no-such-org-${Date.now()}`, provider: 'eherkenning' },
			maxRedirects: 0,
		})

		// A 3xx here would mean it tried to redirect to a broker anyway.
		expect(res.status()).toBe(400)
		const body = await res.json()
		expect(body.error).toBe('oidc_failed')
	})

	test('start with an unrecognised provider string fails closed identically', async ({ request }) => {
		const res = await request.get(`${API_BASE}/session/oidc/start`, {
			params: { org: 'any-org', provider: 'not-a-real-provider' },
			maxRedirects: 0,
		})

		expect(res.status()).toBe(400)
		expect((await res.json()).error).toBe('oidc_failed')
	})

	test('callback with a missing state fails closed with the SAME generic error', async ({ request }) => {
		const res = await request.get(`${API_BASE}/session/oidc/callback`, {
			params: { code: 'some-code' },
		})

		expect(res.status()).toBe(400)
		expect((await res.json()).error).toBe('oidc_failed')
	})

	test('callback with an unknown/never-issued state fails closed identically (CSRF/replay guard)', async ({ request }) => {
		const res = await request.get(`${API_BASE}/session/oidc/callback`, {
			params: { state: `never-issued-${Date.now()}`, code: 'some-code' },
		})

		expect(res.status()).toBe(400)
		expect((await res.json()).error).toBe('oidc_failed')
	})

	test('callback carrying the broker\'s own error param fails closed identically', async ({ request }) => {
		const res = await request.get(`${API_BASE}/session/oidc/callback`, {
			params: { state: 's', code: 'c', error: 'access_denied' },
		})

		expect(res.status()).toBe(400)
		expect((await res.json()).error).toBe('oidc_failed')
	})
})

test.describe('portal-oidc-broker-login — happy path (stub broker, requires setup)', () => {
	test.skip(!LIVE, 'set OIDC_E2E_LIVE=1 (+ OIDC_E2E_ORG_SLUG/ISSUER/CLIENT_ID) after wiring the org\'s OIDC config — see file header')

	test('start→callback against a stub broker lands authenticated in the SPA; the configured provider button renders, an unconfigured one does not', async ({ page, context }) => {
		const orgSlug = process.env.OIDC_E2E_ORG_SLUG!
		const clientId = process.env.OIDC_E2E_CLIENT_ID!

		// -- a tiny stub OIDC broker: discovery + authorize (auto-"consents")
		// + token + jwks, backed by an ephemeral RSA keypair. Stands in for a
		// broker's acceptance environment (design.md).
		const { privateKey, jwk } = generateRsaJwk()
		let issuedCode = ''
		let capturedNonce = ''
		const server = http.createServer((req, res) => {
			const url = new URL(req.url!, 'http://stub-broker')
			if (url.pathname === '/.well-known/openid-configuration') {
				respondJson(res, {
					authorization_endpoint: `${stubOrigin()}/authorize`,
					token_endpoint: `${stubOrigin()}/token`,
					jwks_uri: `${stubOrigin()}/jwks`,
				})
				return
			}

			if (url.pathname === '/authorize') {
				capturedNonce = url.searchParams.get('nonce') || ''
				issuedCode = crypto.randomBytes(16).toString('hex')
				const redirectUri = url.searchParams.get('redirect_uri')!
				const state = url.searchParams.get('state')!
				res.writeHead(302, { Location: `${redirectUri}?code=${issuedCode}&state=${encodeURIComponent(state)}` })
				res.end()
				return
			}

			if (url.pathname === '/token' && req.method === 'POST') {
				const idToken = signIdToken(privateKey, {
					iss: stubOrigin(),
					aud: clientId,
					sub: 'e2e-kvk-00000000',
					nonce: capturedNonce,
					acr: 'e2e-high-loa',
				})
				respondJson(res, { access_token: 'stub-access-token', id_token: idToken, token_type: 'Bearer' })
				return
			}

			if (url.pathname === '/jwks') {
				respondJson(res, { keys: [jwk] })
				return
			}

			res.writeHead(404)
			res.end()
		})
		await new Promise<void>((resolve) => server.listen(0, resolve))
		const port = (server.address() as { port: number }).port
		function stubOrigin() {
			return `http://127.0.0.1:${port}`
		}

		try {
			await page.goto(`/apps/portaliq/portal?org=${encodeURIComponent(orgSlug)}`)

			// The configured provider's button renders; an unconfigured one
			// (digid, in this fixture's setup) does not.
			await expect(page.getByRole('button', { name: /eHerkenning/i })).toBeVisible()
			await expect(page.getByRole('button', { name: /DigiD/i })).toHaveCount(0)

			await page.getByRole('button', { name: /eHerkenning/i }).click()

			// The stub broker's /authorize immediately redirects back with a
			// code — the browser lands on the SPA, authenticated.
			await page.waitForURL(/\/portal(#|$)/, { timeout: 15000 })
			await expect(page.locator('.portaliq-subject, .portaliq-home')).toBeVisible()
		} finally {
			server.close()
		}
	})
})

function respondJson(res: http.ServerResponse, body: unknown) {
	res.writeHead(200, { 'Content-Type': 'application/json' })
	res.end(JSON.stringify(body))
}

function b64Url(input: Buffer | string): string {
	return Buffer.from(input).toString('base64url')
}

function generateRsaJwk(): { privateKey: crypto.KeyObject; jwk: Record<string, string> } {
	const { publicKey, privateKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 })
	const jwk = publicKey.export({ format: 'jwk' }) as { n: string; e: string }
	return {
		privateKey,
		jwk: { kty: 'RSA', kid: 'e2e-stub-key', n: jwk.n, e: jwk.e },
	}
}

function signIdToken(privateKey: crypto.KeyObject, claims: Record<string, unknown>): string {
	const now = Math.floor(Date.now() / 1000)
	const header = { alg: 'RS256', typ: 'JWT', kid: 'e2e-stub-key' }
	const fullClaims = { iat: now, exp: now + 300, ...claims }
	const headerPart = b64Url(JSON.stringify(header))
	const claimsPart = b64Url(JSON.stringify(fullClaims))
	const signature = crypto.sign('RSA-SHA256', Buffer.from(`${headerPart}.${claimsPart}`), privateKey)
	return `${headerPart}.${claimsPart}.${b64Url(signature)}`
}
