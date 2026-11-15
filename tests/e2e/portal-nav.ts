/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Opening the resident's signed-in area on the site, and selecting a KNOWN
 * page in it, instead of trusting where it lands.
 *
 * The React portal is gone: its old address only redirects to the site
 * (REQ-SRP-048). The signed-in area lives on the site under the `/mijn/...`
 * routes (src/shared/portalNav.js `routeForNav`), reached through the
 * `?route=` query parameter. Its navigation is the menu beside the content,
 * "Mijn omgeving" / "My area" (ResidentMenu.vue, site-resident-menu), whose
 * items are LINKS, not the buttons the React portal rendered. The blue bar
 * holds the website's pages only.
 *
 * The signed-in navigation is built by iterating every installed app's
 * contribution in `IAppManager::getInstalledApps()` order. Measured on a dev
 * instance with dossiq installed, the supplier navigation is:
 *
 *     dossiq   Aanbestedingen   <- the signed-in area opens here
 *     dossiq   Contracten
 *     dossiq   Facturen
 *     dossiq   Berichten
 *     portaliq Voorbeeld        <- what the specs actually want
 *     portaliq Berichten
 *     shillinq Purchase orders
 *     shillinq My invoices
 *
 * Landing on the first contribution is correct behaviour, and a test that
 * depends on being the only app in the fleet is the same fleet-of-one
 * assumption that let a whole app go dark unnoticed in the first place. A spec
 * that wants a particular page should say which page it wants.
 *
 * `Voorbeeld` is not a hard-coded English or Dutch string chosen here: it is the
 * label on the seeded `portalPage` row in `lib/Settings/portaliq_register.json`,
 * the same seed that provides the `Onderwerp` and `Aanmaken` labels these specs
 * already use. It travels with the fixture, so it is identical in CI.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect } from '@playwright/test'

/** The site renderer's address (pretty URL, no `index.php`). */
export const SITE_PATH = '/apps/portaliq/site'

/**
 * The portal the specs serve the site as: `open-tilburg`, seeded by
 * tests/e2e/fixtures/seed-cms.sh (organisation `dev-org`, domain `localhost`).
 * PORTALIQ_E2E_PORTAL serves another, e.g. `wilgenboom` on a demo instance.
 */
export const SITE_PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'open-tilburg'

/** The portal auth edge; these API routes stay where they were. */
export const PORTAL_API = '/apps/portaliq/portal/api'

/** Where the site keeps the resident's bearer (src/site/lib/authApi.js). */
export const SITE_TOKEN_KEY = 'portaliq.session.token'

/** The in-site route of the signed-in area (src/shared/portalNav.js). */
export const ACCOUNT_ROUTE = '/mijn'

/** The label of Portaliq's own demo page, from the seeded `portalPage` row. */
export const PORTALIQ_DEMO_PAGE = 'Voorbeeld'

/**
 * The site address for one in-site route of the seeded portal.
 *
 * @param route the in-site route, `/mijn` (the signed-in area) by default
 * @return the path, relative to the base URL
 */
export function siteAddress(route: string = ACCOUNT_ROUTE): string {
	const params = new URLSearchParams({ portal: SITE_PORTAL })
	if (route && route !== '/') {
		params.set('route', route)
	}
	return `${SITE_PATH}?${params.toString()}`
}

/**
 * Mint a dev session and say what happened when it does not work.
 *
 * A bare `expect(login.ok(), 'dev-login must be enabled')` sent the last
 * reader after the wrong thing: the real answer was HTTP 429, because
 * Nextcloud's brute-force protection had blocked the address after a run's
 * repeated logins, and dev-login WAS enabled. So the message carries the
 * status and the body, and a 429 is waited out twice before it is reported,
 * since the throttle lifts on its own.
 *
 * @param request the request fixture
 * @param subjectRef the subject to mint for
 * @param audience the audience to mint for
 * @param organisation the tenant
 * @return the bearer
 */
export async function devLogin(
	request: APIRequestContext,
	subjectRef: string,
	audience: string,
	organisation: string,
): Promise<string> {
	const waits = [0, 3000, 9000]
	let last = { status: 0, body: '' }
	for (const wait of waits) {
		if (wait > 0) {
			await new Promise((resolve) => setTimeout(resolve, wait))
		}
		const login = await request.post(`${PORTAL_API}/session/dev-login`, {
			data: { subjectRef, audience, organisation },
		})
		if (login.ok()) {
			const { token } = await login.json()
			expect(token, 'dev-login answered without a token').toBeTruthy()
			return token as string
		}
		last = { status: login.status(), body: (await login.text()).slice(0, 300) }
		if (last.status !== 429) {
			break
		}
	}

	const hints: Record<number, string> = {
		404: 'dev-login is disabled: set debug mode, or `occ config:app:set portaliq dev_login_enabled --value=yes`',
		429: "Nextcloud's brute-force protection blocked this address: clear `oc_bruteforce_attempts` and run fewer sign-ins",
		503: 'the auth edge has no jwt_signing_secret configured',
	}
	throw new Error(
		`dev-login answered ${last.status} for ${subjectRef}: ${last.body}`
			+ ` — ${hints[last.status] || 'see tests/e2e/ci-seed.sh'}`,
	)
}

/**
 * Put a minted bearer where the site reads it BEFORE the app boots, so the
 * site loads already signed in (the way it adopts a `#token=` hand-back).
 *
 * @param page the Playwright page, not yet navigated
 * @param token the bearer the dev login minted
 * @return nothing
 */
export async function seedSiteSession(page: Page, token: string): Promise<void> {
	await page.addInitScript(
		([key, value]) => {
			window.sessionStorage.setItem(key, value)
		},
		[SITE_TOKEN_KEY, token],
	)
}

/**
 * The bearer the site holds right now, or null.
 *
 * @param page the Playwright page, on the site
 * @return the bearer, or null
 */
export async function readSiteSession(page: Page): Promise<string | null> {
	return page.evaluate((key) => window.sessionStorage.getItem(key), SITE_TOKEN_KEY)
}

/**
 * A regular expression that matches exactly one of the given texts: the site
 * speaks the portal's language, so a spec names the Dutch and the English text.
 *
 * @param texts the accepted texts
 * @return the expression
 */
export function oneOf(...texts: string[]): RegExp {
	const escaped = texts.map((text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
	return new RegExp(`^\\s*(${escaped.join('|')})\\s*$`)
}

/**
 * The signed-in menu's link to one in-site account route: one of the shell's
 * own sections (`inbox`, `cases`, `tasks`, `messages`, `news`, `access`,
 * `details`, `account`) or a contributed page as `<app>/<page id>`
 * (src/shared/portalNav.js `routeForNav`).
 *
 * Addressed by its route rather than its label: the inbox is "Berichten" in
 * Dutch, the same label as Portaliq's own contributed "Berichten" page, and
 * "Mijn zaken" is both the shell's cases section and a seeded page.
 *
 * @param page the Playwright page, on the site and signed in
 * @param path the section key, or `<app>/<page id>`
 * @return the link (several when two contributions declare the same page)
 */
export function accountLink(page: Page, path: string): Locator {
	const route = encodeURIComponent(`${ACCOUNT_ROUTE}/${path}`)
	return page.locator(
		`[data-testid="site-resident-menu"] a[href*="route=${route}"]`,
	)
}

/**
 * The unread count shown beside a menu link (ResidentMenu.vue): the visible
 * number, without the screen-reader label beside it.
 *
 * @param link the menu link
 * @return the count's locator
 */
export function menuBadgeCount(link: Locator): Locator {
	return link.locator(
		'[data-testid="site-resident-menu-badge"] [aria-hidden="true"]',
	)
}

/**
 * Open Portaliq's own demo page, whatever else is installed and contributing.
 *
 * Waits for the create form to be visible, so a caller that goes on to fill it
 * fails here, naming the navigation, rather than later on a field timeout.
 *
 * @param page the Playwright page, already on the site and signed in
 * @return nothing
 */
export async function openPortaliqDemoPage(page: Page): Promise<void> {
	const navItem = page
		.getByTestId('site-resident-menu')
		.getByRole('link', { name: PORTALIQ_DEMO_PAGE, exact: true })
	await expect(
		navItem,
		`the signed-in menu has no "${PORTALIQ_DEMO_PAGE}" entry — Portaliq's own `
			+ 'contribution did not reach this subject, which is a discovery failure, not a UI one',
	).toBeVisible()
	await navItem.click()

	await expect(
		page.getByLabel('Onderwerp'),
		`clicked "${PORTALIQ_DEMO_PAGE}" but its create form did not render`,
	).toBeVisible()
}
