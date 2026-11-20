/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end for nldesign-theme-integration: an administrator reads the house
 * styles a portal can wear, each with a readability verdict; a hard-to-read set
 * is saved only once confirmed; an unknown id is refused and a portal naming
 * one renders without a token layer; no dark variant is linked while the site
 * has no token-driven surfaces; and neither an anonymous caller nor a signed-in
 * non-admin reaches the catalogue. Requires a seeded instance with the theme
 * app installed and the non-admin user of tests/e2e/ci-seed.sh. Run against
 * the instance named by PLAYWRIGHT_BASE_URL (see tests/e2e/base-url.ts):
 *
 *     PLAYWRIGHT_BASE_URL=http://<instance> npx playwright test nldesign-theme-integration
 *
 * The verdict arithmetic and the confirm-before-saving rule are pinned by
 * tests/Unit/Service/Theme/PortalThemeChoiceTest.php and
 * tests/portal-theme-choice.spec.mjs; what a visitor can read on a rendered
 * page by tests/site-surfaces.spec.mjs.
 *
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'

const ADMIN_USER = process.env.PORTALIQ_E2E_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.PORTALIQ_E2E_ADMIN_PASS ?? 'admin'
const E2E_USER = process.env.PORTALIQ_E2E_USER ?? 'e2e-reader'
const E2E_PASS = process.env.PORTALIQ_E2E_PASS ?? 'e2e-reader-pw-2026'
const OR_OBJECTS_BASE = '/index.php/apps/openregister/api/objects'

/**
 * The theme route of one portal.
 *
 * @param slug The portal.
 * @return The path.
 */
function THEME_API(slug: string): string {
	return `/index.php/apps/portaliq/api/portals/${slug}/theme`
}

/**
 * The rendered site of one portal.
 *
 * @param slug The portal.
 * @return The path.
 */
function SITE(slug: string): string {
	return `/index.php/apps/portaliq/site?portal=${slug}`
}

type Identity = { user: string; pass: string } | 'anonymous'

type ThemeSet = {
	id: string
	custom?: boolean
	refusal?: string
	verdict?: { measured: number; passes: boolean; findings: unknown[] }
}

/**
 * A fresh request context with its own, empty cookie jar.
 *
 * `storageState` is named explicitly so a context never inherits a session
 * from the config, which would turn every refusal below green for the wrong
 * reason.
 *
 * @param identity Credentials, or 'anonymous' for none.
 * @return An isolated context; the caller disposes it.
 */
async function newIdentity(identity: Identity): Promise<APIRequestContext> {
	const headers: Record<string, string> = {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
	if (identity !== 'anonymous') {
		headers.Authorization = `Basic ${Buffer.from(`${identity.user}:${identity.pass}`).toString('base64')}`
	}
	return playwrightRequest.newContext({
		baseURL: BASE_URL,
		extraHTTPHeaders: headers,
		storageState: { cookies: [], origins: [] },
	})
}

/**
 * Prove who the server thinks is calling, so a refusal means what it says.
 *
 * @param ctx      The context.
 * @param expected The uid, or null for "not signed in".
 */
async function assertCallerIs(
	ctx: APIRequestContext,
	expected: string | null,
): Promise<void> {
	const res = await ctx.get('/ocs/v2.php/cloud/user?format=json')
	if (expected === null) {
		expect(
			res.ok(),
			'an anonymous context must not resolve to a user',
		).toBeFalsy()
		return
	}
	expect(res.ok(), `the context must be signed in as ${expected}`).toBeTruthy()
	expect((await res.json()).ocs.data.id).toBe(expected)
}

/**
 * Create a portal through OpenRegister.
 *
 * @param admin  The administrator context.
 * @param fields Extra portal fields.
 * @return The slug.
 */
async function createPortal(
	admin: APIRequestContext,
	fields: Record<string, unknown>,
): Promise<string> {
	const slug = `theme-${Date.now()}-${Math.floor(Math.random() * 1000)}`
	const created = await admin.post(`${OR_OBJECTS_BASE}/portaliq/portal`, {
		data: { slug, title: 'Thema-test', status: 'draft', ...fields },
	})
	expect(created.ok(), `creating portal ${slug}`).toBeTruthy()
	return slug
}

/**
 * The sets the portal can wear, as the House style widget reads them.
 *
 * @param admin The administrator context.
 * @param slug  The portal.
 * @return The sets that are not refused.
 */
async function choosableSets(
	admin: APIRequestContext,
	slug: string,
): Promise<ThemeSet[]> {
	const listed = await admin.get(THEME_API(slug))
	expect(listed.ok()).toBeTruthy()
	const body = await listed.json()
	return (body.sets as ThemeSet[]).filter((set) => !set.refusal && set.verdict)
}

test.describe('nldesign-theme-integration', () => {
	let admin: APIRequestContext

	test.beforeAll(async () => {
		admin = await newIdentity({ user: ADMIN_USER, pass: ADMIN_PASS })
		await assertCallerIs(admin, ADMIN_USER)
	})

	test.afterAll(async () => {
		await admin.dispose()
	})

	// Scenarios: An administrator picks a theme; A set declares none of the surface tokens
	test('an administrator reads every set with a verdict, and an unmeasured set is never a pass', async () => {
		const slug = await createPortal(admin, {})
		const listed = await admin.get(THEME_API(slug))
		expect(listed.ok()).toBeTruthy()
		const body = await listed.json()
		expect(Array.isArray(body.sets)).toBeTruthy()
		for (const set of body.sets as ThemeSet[]) {
			if (set.refusal) {
				continue
			}
			expect(typeof set.verdict?.measured).toBe('number')
			if (set.verdict?.measured === 0) {
				expect(
					set.verdict.passes,
					`${set.id} measured nothing and must not pass`,
				).toBe(false)
			}
		}
	})

	// Scenario: An anonymous caller asks for the catalogue
	test('an anonymous caller and a signed-in non-admin are refused the catalogue and the save', async () => {
		const slug = await createPortal(admin, {})
		const anon = await newIdentity('anonymous')
		const reader = await newIdentity({ user: E2E_USER, pass: E2E_PASS })
		try {
			await assertCallerIs(anon, null)
			await assertCallerIs(reader, E2E_USER)
			for (const [label, ctx] of [
				['anonymous', anon],
				['non-admin', reader],
			] as const) {
				const read = await ctx.get(THEME_API(slug))
				expect(read.ok(), `${label} GET must be refused`).toBeFalsy()
				expect(await read.text()).not.toContain('"sets"')

				const write = await ctx.put(THEME_API(slug), {
					data: { theme: 'anything' },
				})
				expect(write.ok(), `${label} PUT must be refused`).toBeFalsy()
			}
		} finally {
			await anon.dispose()
			await reader.dispose()
		}
	})

	// Scenario: A hard-to-read set asks before it saves
	test('a hard-to-read set is refused with its findings until the administrator confirms', async () => {
		const slug = await createPortal(admin, {})
		const hard = (await choosableSets(admin, slug)).find(
			(set) => (set.verdict?.findings.length ?? 0) > 0,
		)
		test.skip(
			hard === undefined,
			'the instance offers no set with contrast findings',
		)

		const refused = await admin.put(THEME_API(slug), {
			data: { theme: hard!.id },
		})
		expect(refused.status()).toBe(422)
		const reason = await refused.json()
		expect(reason.error).toBe('contrast')
		expect(reason.verdict.findings.length).toBeGreaterThan(0)

		const confirmed = await admin.put(THEME_API(slug), {
			data: { theme: hard!.id, acceptFindings: true },
		})
		expect(confirmed.ok()).toBeTruthy()
		const after = await confirmed.json()
		expect(after.current).toBe(hard!.id)
		expect(after.currentResolves).toBe(true)
	})

	// Scenario: An unknown theme renders unstyled rather than branded
	test('an unknown id is refused on save, and a portal naming one renders with no token layer', async () => {
		const unknown = `no-such-set-${Date.now()}`
		const slug = await createPortal(admin, {
			status: 'published',
			theme: unknown,
		})

		const save = await admin.put(THEME_API(slug), { data: { theme: unknown } })
		expect(save.status()).toBe(422)
		expect((await save.json()).error).toBe('unknown_theme')

		const listed = await (await admin.get(THEME_API(slug))).json()
		expect(listed.current).toBe(unknown)
		expect(listed.currentResolves).toBe(false)

		const visitor = await newIdentity('anonymous')
		try {
			const page = await visitor.get(SITE(slug), {
				headers: { Accept: 'text/html' },
			})
			expect(page.ok(), 'the published portal must render').toBeTruthy()
			const html = await page.text()
			// No token set is linked at all: neither the named one nor a fallback.
			expect(html).not.toMatch(/css\/tokens\//)
			expect(html).not.toContain(`${unknown}.css`)
		} finally {
			await visitor.dispose()
		}
	})

	// Scenario: The surfaces do not read their tokens
	test('a portal wearing a catalogued set links it, and links no dark variant', async () => {
		const slug = await createPortal(admin, { status: 'published' })
		const sets = (await choosableSets(admin, slug)).filter((set) => !set.custom)
		test.skip(sets.length === 0, 'the instance offers no catalogued set')
		const chosen =
			sets.find((set) => set.verdict?.findings.length === 0) ?? sets[0]

		const saved = await admin.put(THEME_API(slug), {
			data: { theme: chosen.id, acceptFindings: true },
		})
		expect(saved.ok()).toBeTruthy()
		expect((await saved.json()).currentResolves).toBe(true)

		const visitor = await newIdentity('anonymous')
		try {
			const page = await visitor.get(SITE(slug), {
				headers: { Accept: 'text/html' },
			})
			expect(page.ok()).toBeTruthy()
			const html = await page.text()
			expect(html).toContain(`css/tokens/${chosen.id}.css`)
			// Withdrawn on measurement (task 2.1): the site has no token-driven
			// surface layer yet, so the generated dark variant stays unlinked.
			expect(html).not.toMatch(/css\/tokens\/dark\//)
		} finally {
			await visitor.dispose()
		}
	})
})
