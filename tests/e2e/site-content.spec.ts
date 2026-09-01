/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Scenarios S1–S3 — the built-in site renderer reads the headless content API
 * and renders navigation, a grid page and a markdown page.
 *
 * See tests/e2e/scenarios/portal-phase-two.md.
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const API = `${BASE}/index.php/apps/portaliq/api/content`

test.describe('site renderer — content', () => {
	// @e2e portaliq-cms::a-grid-page-renders-on-the-shared-12-column-geometry
	test('S1: renders the site title, menu and grid page from the API', async ({
		page,
	}) => {
		await page.goto(SITE)

		// The title comes from the portal object, not from a constant in the
		// bundle. Asserting the literal seeded value is the point: a renderer
		// that fell back to a product name would still show *a* title.
		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')

		const menu = page.getByTestId('site-menu')
		await expect(menu.getByRole('link', { name: 'Home' })).toBeVisible()
		await expect(menu.getByRole('link', { name: 'Over ons' })).toBeVisible()
		await expect(menu.getByRole('link', { name: 'Begrippen' })).toBeVisible()

		// Two levels, one nested under 'Over ons' — and the second level is a
		// DROPDOWN, so it is present but hidden until its parent is opened.
		//
		// This assertion used to be a bare `toBeVisible()` on 'Contact', which
		// passed for the wrong reason: the child list was tagged with the
		// top-level bar class, so it rendered as an in-flow second row and the
		// navigation bar came out 110px against the reference's 55. The link
		// was permanently visible because the submenu was permanently open.
		const parent = menu.locator('.ac-c-navigation__li', {
			has: page.getByTestId('site-menu-dropdown'),
		})
		await expect(menu.getByRole('link', { name: 'Contact' })).toBeHidden()
		await parent.hover()
		await expect(menu.getByRole('link', { name: 'Contact' })).toBeVisible()

		// The home page is a GRID body: three markdown widgets in declared cells.
		const grid = page.getByTestId('widget-grid')
		await expect(grid).toBeVisible()
		await expect(page.getByTestId('widget-intro')).toContainText(
			'Welkom bij Open Tilburg',
		)
		await expect(page.getByTestId('widget-side')).toContainText('Actueel')
		await expect(page.getByTestId('widget-help')).toContainText('Hulp nodig?')

		// Grid geometry is the manifest's, not a portal variant: the two
		// half-width widgets sit side by side, so their vertical positions
		// match and their horizontal ones do not.
		const side = await page.getByTestId('widget-side').boundingBox()
		const help = await page.getByTestId('widget-help').boundingBox()
		expect(side).not.toBeNull()
		expect(help).not.toBeNull()
		expect(Math.abs(side!.y - help!.y)).toBeLessThan(4)
		expect(help!.x).toBeGreaterThan(side!.x)
	})

	// @e2e portaliq-cms::markdown-is-served-as-source
	test('S2: markdown is served as source and rendered with its structure intact', async ({
		page,
		request,
	}) => {
		const response = await request.get(`${API}/page?route=/over-ons`)
		expect(response.status()).toBe(200)
		const body = await response.json()

		expect(body.body.type).toBe('markdown')
		const markdown: string = body.body.markdown
		// SOURCE, not HTML. A consumer that renders markdown itself — a
		// Docusaurus build, most obviously — must not have to undo an HTML
		// conversion the API did on its behalf.
		expect(markdown).toContain('```')
		expect(markdown).toContain('| Kolom | Waarde |')
		expect(markdown).not.toContain('<p>')
		expect(markdown).not.toContain('<table')

		// And the renderer turns that same source into real structure.
		await page.goto(`${SITE}?route=/over-ons`)
		await expect(page.getByTestId('page-title')).toHaveText('Over ons')
		await expect(page.locator('#pq-main pre')).toBeVisible()
		await expect(page.locator('#pq-main table')).toBeVisible()
		await expect(page.locator('#pq-main table td').first()).toBeVisible()
	})

	test('S3: in-site navigation updates the page and marks the current item', async ({
		page,
	}) => {
		await page.goto(SITE)
		await expect(page.getByTestId('page-title')).toHaveText('Welkom')

		// Pin that this is a client-side transition: a full document
		// navigation would reset this marker.
		await page.evaluate(() => {
			;(window as unknown as Record<string, unknown>).__pqNoReload = true
		})

		await page
			.getByTestId('site-menu')
			.getByRole('link', { name: 'Over ons' })
			.click()

		await expect(page.getByTestId('page-title')).toHaveText('Over ons')
		await expect(
			page.getByTestId('site-menu').getByRole('link', { name: 'Over ons' }),
		).toHaveAttribute('aria-current', 'page')

		const survived = await page.evaluate(
			() =>
				(window as unknown as Record<string, unknown>).__pqNoReload === true,
		)
		expect(survived).toBe(true)
	})

	test('S1b: the glossary is reachable and rendered', async ({ page }) => {
		await page.goto(`${SITE}?route=/begrippen`)

		// LOCATED BY THE BLOCK'S OWN CLASSES, not by test hooks.
		//
		// This spec used to look for `data-testid="site-glossary"` and
		// `glossary-term`, which the renderer emitted back when the glossary
		// was a hard-coded <section>. It is now `CnSiteGlossary` from the
		// shared public entry point, and that library ships semantic classes
		// rather than another consumer's test attributes.
		const glossary = page.locator('.ac-glossary')
		await expect(glossary.locator('.ac-glossary__term').first()).toBeVisible()

		// The term AND its synonym. Someone searching for the old name has only
		// the old name; a glossary that renders "Woo-verzoek" alone tells them
		// the concept was abolished rather than renamed.
		await expect(glossary).toContainText('Woo-verzoek')
		await expect(glossary).toContainText('Wob-verzoek')

		// A description list, because that is what makes a screen reader
		// announce term/definition pairs instead of a run of text — and a
		// stack of divs looks identical on screen.
		await expect(glossary.locator('dl dt').first()).toBeVisible()
		await expect(glossary.locator('dl dd').first()).toBeVisible()
	})

	test('S18: the site bundle stays inside the public first-load budget', async ({
		page,
	}) => {
		// Measured on TRANSFERRED bytes for an empty cache, not on the build's
		// own report of what it emitted. Those differ whenever compression or
		// code-splitting is involved, and the one a visitor pays is this one.
		//
		// The path is matched loosely on purpose. An earlier version matched
		// `/custom_apps/portaliq/js/` — the layout of one developer rig — and
		// scored 0 bytes in CI, where the app installs under `/apps/`. It then
		// failed on `0 > 0`, which says nothing about what went wrong. Matching
		// the app segment alone survives either layout, and the miss case below
		// says what happened rather than asserting a number.
		const transferred: Record<string, number> = {}
		page.on('response', async (response) => {
			const url = response.url()
			if (!/\/portaliq\/js\/.*\.js/.test(url)) {
				return
			}
			try {
				transferred[url.split('/').pop() as string] = (
					await response.body()
				).length
			} catch {
				// A body no longer available is reported as absent rather than
				// failing the measurement.
			}
		})

		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toBeVisible()

		const total = Object.values(transferred).reduce((a, b) => a + b, 0)

		console.log(
			'site bundle bytes:',
			JSON.stringify(transferred),
			'total',
			total,
		)

		expect(
			total,
			'no portaliq js was transferred — the URL filter no longer matches how '
				+ 'this instance serves the app, so nothing was measured',
		).toBeGreaterThan(0)

		// A public, first-visit, mobile-visited surface. This is a failure and
		// not a warning: a budget nobody fails is a budget nobody keeps.
		expect(total).toBeLessThan(400 * 1024)
	})

	// @e2e portaliq-cms::the-subject-scoped-aggregate-is-never-served-publicly
	test('S19: contributed surfaces reach the portal over the public contract', async ({
		page,
		request,
	}) => {
		// The API half. `anonymous: true` on every entry is the load-bearing
		// assertion: this endpoint is publicly cacheable, so anything
		// subject-scoped appearing here would be pooled across visitors at a
		// CDN — a leak that happens at the edge, where our logs never look.
		const response = await request.get(
			`${API}/contributions?portal=open-tilburg`,
		)
		expect(response.status()).toBe(200)
		expect(
			response.headers()['cache-control'],
			'the response must really be the publicly cacheable kind',
		).toContain('public')

		const body = await response.json()
		expect(
			Array.isArray(body.contributions),
			'the bridge must answer with a list, not an object or an error',
		).toBe(true)

		// The renderer half. A section that the API feeds but the page never
		// shows is a bridge that is only half built.
		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toBeVisible()

		if (body.contributions.length > 0) {
			await expect(
				page.getByTestId('site-contributions'),
				'the API returned contributions, so the page must render them',
			).toBeVisible()
		}
	})

	// @e2e portaliq-cms::a-signed-out-visitor-still-gets-the-portal
	test('S21: the portal renders signed-out, and offers no login it cannot honour', async ({
		page,
	}) => {
		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')

		// The seeded portals declare `modes: ['public']`. A public-only portal
		// must show NO sign-in affordance: an inert login button on a portal
		// with no accounts is a support ticket from every visitor who presses
		// it, and it is exactly what a naive `modes.length > 0` check renders.
		await expect(page.getByTestId('site-signin')).toHaveCount(0)
		await expect(page.getByTestId('site-signout')).toHaveCount(0)

		// And the content is all there anyway — the session lookup must never
		// be able to cost a visitor the public page.
		await expect(page.getByTestId('site-page')).toBeVisible()
	})

	test('S22: the browser tab carries the PORTAL name, not the platform name', async ({
		page,
	}) => {
		// Found by comparing against the portal this replaces, which titles its
		// tab properly while this one said "Nextcloud" — the hosting platform,
		// on a white-label portal whose whole purpose is that a visitor never
		// learns what it runs on.
		//
		// The tab title is the bookmark name, the history entry, the
		// window-switcher label and the search-result heading. None of those
		// appear in a screenshot, which is why this went unnoticed.
		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')

		await expect(page).toHaveTitle(/Open Tilburg/)
		await expect(page).not.toHaveTitle(/Nextcloud/)
	})

	// @e2e portaliq-cms::an-anonymous-visitor-is-not-an-error
	test('S24: an anonymous visitor is the ordinary state, not a console error', async ({
		page,
		request,
	}) => {
		// Every public page load probes the auth edge once. It used to answer
		// 401, so a correctly-working public portal logged a red console error
		// on every visit. The renderer never cared — it reads the
		// `authenticated` FLAG, not the status.
		const anon = await request.get(
			`${BASE}/index.php/apps/portaliq/portal/api/session`,
		)
		expect(
			anon.status(),
			'no credential offered is the ordinary anonymous state, not a failure',
		).toBe(200)
		expect((await anon.json()).authenticated).toBe(false)

		// THE PAIR. A change that answered 200 to everything would pass the
		// assertion above while silently retiring the failure signal for an
		// expired or tampered bearer, which is the one that matters.
		const bogus = await request.get(
			`${BASE}/index.php/apps/portaliq/portal/api/session`,
			{ headers: { Authorization: 'Bearer totally-invalid-token' } },
		)
		expect(
			bogus.status(),
			'a bearer that is PRESENT and does not resolve is still a 401',
		).toBe(401)

		// And the page itself loads clean for a visitor who never signs in.
		const errors: string[] = []
		page.on('console', (m) => {
			if (m.type() === 'error') errors.push(m.text())
		})
		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')
		expect(errors, 'a working public portal must log no console errors').toEqual(
			[],
		)
	})

	// @e2e portaliq-cms::the-site-wears-no-platform-chrome
	test('S23: the site wears no platform chrome — no Nextcloud header', async ({
		page,
	}) => {
		// S22's sibling, found the same way and hidden the same way. The site
		// rendered with `RENDER_AS_PUBLIC`, and `layout.public.php` emits
		// `<header id="header">` carrying `header-appname`, the Nextcloud logo
		// and a `header-info` title. It was VISIBLE — 108x33 at the very top of
		// an anonymous viewport — so a municipality's portal displayed another
		// product's brand above its own, to visitors who never logged in.
		//
		// It survived every earlier check because those all inspected the
		// CONTENT area, where the portal renders perfectly. Nothing looked at
		// the bar above it.
		await page.goto(SITE)
		await expect(page.getByTestId('site-title')).toHaveText('Open Tilburg')

		await expect(page.locator('#header, header.header')).toHaveCount(0)

		// Assert the ABSENCE of the word, not just of the element: a future
		// layout could reintroduce the branding under a different selector and
		// this test would still pass on the element check alone.
		await expect(page.locator('body')).not.toContainText('Nextcloud')

		// And the portal must still be whole — `RENDER_AS_BLANK` would also
		// satisfy both assertions above while shipping no CSS or initial state
		// at all, i.e. by breaking the page rather than by fixing the leak.
		await expect(page.getByTestId('site-page')).toBeVisible()
		await expect(page.locator('nav a').first()).toBeVisible()
	})
})
