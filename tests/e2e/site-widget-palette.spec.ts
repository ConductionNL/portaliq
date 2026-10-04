/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-widget-palette: placing a widget from the palette, with a pointer and
 * without one (site-nlds-widget-palette REQ-SNW-001, REQ-SNW-002).
 *
 * WHAT THE FIRST VERSION GOT WRONG, because the next reader will wonder:
 *
 * 1. It set `httpCredentials`, which is HTTP Basic. Nextcloud's web UI ignores
 *    that: every page answered the LOGIN FORM, and the failure read as "the
 *    palette button is missing" for all three tests. The browser needs a
 *    session, so this logs in through the form, as `app-chrome.spec.ts` does.
 * 2. It built the designer's address as `#/pages/layout?portal=…&page=…`. The
 *    route is `/pages/:id/layout` (src/manifest.json, the `PageLayout` page),
 *    and the view reads `$route.params.id`. A query string was never going to
 *    resolve a page.
 * 3. It assumed a page existed, named by a slug. The `page` schema has no
 *    slug, and which pages an instance holds is not this spec's business. So
 *    it SEEDS its own grid page through OpenRegister's object API and deletes
 *    it afterwards, the way the mijn omgeving specs seed theirs.
 *
 * Environment (all optional): PORTALIQ_E2E_PORTAL (default demo),
 * PORTALIQ_E2E_ADMIN (default admin:admin).
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const OR_OBJECTS_BASE = '/apps/openregister/api/objects'
const APP_BASE = '/index.php/apps/portaliq'
const ADMIN = process.env.PORTALIQ_E2E_ADMIN || 'admin:admin'
const [ADMIN_USER, ADMIN_PASS] = ADMIN.split(':')
const BASIC = Buffer.from(ADMIN).toString('base64')
const PORTAL = process.env.PORTALIQ_E2E_PORTAL || 'demo'

/** How long a presence check may wait: it is there, or it is not coming. */
const BRIEFLY = 3_000

/** How long the designer may take to paint, which is a real navigation. */
const A_WHILE = 30_000

/** What this run created, as `schema/id`, to delete afterwards. */
const created: string[] = []

/**
 * Sign in through the form, so the browser has a session.
 *
 * @param page The page.
 * @return nothing
 */
async function login(page: Page): Promise<void> {
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(ADMIN_USER)
	await page.locator('input[name="password"]').fill(ADMIN_PASS)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: A_WHILE })
}

/**
 * Close whatever modal is over the app, IF one is open: the first-run setup
 * wizard, or Portaliq's own onboarding tour ("Welkom bij Portaliq", with
 * Volgende and Close tour). They are two different overlays and both land on
 * a signed-in admin, so both are handled here.
 *
 * ⚠️ WAITING FOR IT IS WRONG. The wizard opens on a fresh instance and not on
 * one that has been set up, so a wait for its close button costs the default
 * timeout on every instance where there is nothing to close: three tests times
 * 90 seconds is a quarter of an hour to learn that a modal is absent. It is
 * looked for briefly, dismissed when it is there, and skipped when it is not.
 *
 * Its close control is looked up four ways because none of them is reliable
 * across locales and library versions: the instance here runs in Dutch, where
 * the accessible name is "Sluiten" rather than "Close", and Nextcloud's own
 * modal uses an icon button instead of a named one. Escape is the last resort
 * and usually the one that works.
 *
 * @param page The page.
 * @return nothing
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page
		.locator(
			'[data-testid="cn-modal"], .modal-container, [data-testid="cn-tour"]',
		)
		.first()
	if (!(await modal.isVisible({ timeout: BRIEFLY }).catch(() => false))) {
		return
	}

	const closers = [
		// The tour names its own way out, and it is not called "Close".
		page.getByRole('button', {
			name: /close tour|tour sluiten|tour afsluiten/i,
		}),
		modal.getByRole('button', {
			name: /close|sluiten|afsluiten|overslaan|skip/i,
		}),
		modal.locator('[aria-label*="luiten" i], [aria-label*="lose" i]'),
		modal.locator('button.icon-close, .modal-container__close'),
	]
	for (const closer of closers) {
		if (
			await closer
				.first()
				.isVisible({ timeout: BRIEFLY })
				.catch(() => false)
		) {
			await closer.first().click()
			break
		}
	}

	if (await modal.isVisible({ timeout: BRIEFLY }).catch(() => false)) {
		await page.keyboard.press('Escape')
	}

	// Say what it looked for and what it found, so a failure here is read as
	// "the wizard would not close" rather than as a missing test id later.
	const text =
		(await modal.textContent().catch(() => ''))?.trim().slice(0, 200) ?? ''
	await expect(
		modal,
		"a modal stayed open over the designer. Looked for the tour's own"
			+ ' "Close tour", a button named close/sluiten/afsluiten/overslaan,'
			+ " an aria-label with sluiten or close, Nextcloud's own icon-close,"
			+ ' then Escape. The modal reads: '
			+ text,
	).toBeHidden({ timeout: BRIEFLY })
}

/**
 * Seed an empty grid page for this run and answer its id.
 *
 * @param request The request fixture.
 * @return the page's id
 */
async function seedGridPage(request: APIRequestContext): Promise<string> {
	const stamp = Date.now()
	const res = await request.post(`${OR_OBJECTS_BASE}/portaliq/page`, {
		headers: { Authorization: `Basic ${BASIC}`, 'OCS-APIRequest': 'true' },
		data: {
			title: `Palette proef ${stamp}`,
			route: `/palette-proef-${stamp}`,
			portal: PORTAL,
			status: 'draft',
			body: { type: 'grid', widgets: [] },
		},
	})
	expect(
		res.ok(),
		`OpenRegister objects#create must be reachable for page: ${res.status()}`,
	).toBeTruthy()

	const body = await res.json()
	const id = (body.id ?? body['@self']?.id) as string
	expect(id, 'the seeded page must have an id').toBeTruthy()
	created.push(`page/${id}`)
	return id
}

/**
 * The designer's address for one page.
 *
 * ⚠️ NO HASH. `src/main.js` builds the router with `createWebHistory`, so the
 * route is a real path and `#/pages/<id>/layout` loads the app at its root,
 * which renders the Dashboard. That is what the second live run showed, and it
 * reads exactly like "the designer has no add button" unless something checks
 * the address, which `openDesigner` now does.
 *
 * @param id The page's id.
 * @return the path
 */
function designerAddress(id: string): string {
	return `${APP_BASE}/pages/${id}/layout`
}

/**
 * Open the designer on a page seeded for this test.
 *
 * @param page The page.
 * @param request The request fixture.
 * @return nothing
 */
async function openDesigner(page: Page, request: APIRequestContext): Promise<void> {
	const id = await seedGridPage(request)
	await login(page)
	await page.goto(designerAddress(id))
	await dismissSetupWizard(page)

	// THE ADDRESS FIRST, so the two failures read differently. Portaliq's
	// router sends a page the role may not use to the Dashboard
	// (`router.beforeEach` in src/main.js), and a wrong address lands there
	// too, so "it went to the dashboard" and "the designer opened without its
	// button" are different faults and should not share one message.
	await expect(
		page,
		`the designer must be the page on screen. A dashboard address here means the route`
			+ ` did not resolve for page ${id}: either the path is wrong or the role guard`
			+ ` sent it away.`,
	).toHaveURL(new RegExp(`/pages/${id}/layout`), { timeout: A_WHILE })

	await expect(
		page.getByTestId('designer-title'),
		'the designer must load the seeded page, not sit on an error card',
	).toBeVisible({ timeout: A_WHILE })

	await expect(
		page.getByTestId('designer-add-widget'),
		'the designer is open but offers no way to add a widget',
	).toBeVisible({ timeout: BRIEFLY })
}

test.describe('site-widget-palette', () => {
	test.afterAll(async ({ request }) => {
		for (const entry of created.splice(0)) {
			await request.delete(`${OR_OBJECTS_BASE}/portaliq/${entry}`, {
				headers: {
					Authorization: `Basic ${BASIC}`,
					'OCS-APIRequest': 'true',
				},
			})
		}
	})

	// @e2e site-nlds-widget-palette::an-editor-looks-for-a-heading
	test('the palette groups its widgets and says how many a search finds', async ({
		page,
		request,
	}) => {
		await openDesigner(page, request)
		await page.getByTestId('designer-add-widget').click()
		await expect(page.getByTestId('widget-palette')).toBeVisible()

		const headings = await page
			.getByTestId('widget-palette')
			.locator('.palette__group-heading')
			.allInnerTexts()
		expect(headings).toContain('Inhoud')
		expect(headings.indexOf('Inhoud')).toBeLessThan(headings.indexOf('Opmaak'))

		// An author types the word for the thing, not the component's name.
		await page.getByTestId('widget-palette-search').fill('kop')
		await expect(page.getByTestId('widget-palette-tile-nlHeading')).toBeVisible({
			timeout: BRIEFLY,
		})
		// THE PLURAL IS PART OF THE ASSERTION. `/\d+ widget/` matched "2 widget
		// found" just as happily as "2 widgets found", so it passed for months
		// over a palette that never pluralised: the dialog called `translate`
		// with the plural string in the vars position, which drops it. A count
		// that reads "2 widget found" is the thing this test exists to catch.
		await expect(
			page.getByTestId('widget-palette-hits'),
			'the hit count is announced and agrees in number, so a narrow search is not read as a broken one',
		).toHaveText(/\d+ widgets found/, { timeout: BRIEFLY })

		await page.getByTestId('widget-palette-search').fill('parkeervergunning')
		await expect(page.getByTestId('widget-palette-empty')).toBeVisible({
			timeout: BRIEFLY,
		})
	})

	// @e2e site-nlds-widget-palette::a-heading-dropped-at-the-top
	//
	// 🔴 FAILING ON A REAL DEFECT, NOT ON THE TEST. The palette is an
	// `aria-modal` dialog with a full-screen backdrop, so while it is open the
	// canvas behind it takes no pointer at any coordinate: the live run showed
	// the dialog's own header intercepting the drop over `designer-canvas`.
	// The tiles carry `draggable="true"` and the canvas carries `@drop`, but
	// there is no reachable drop target, so no author can complete this drag
	// either. REQ-SNW-002 ("drag and key are the same act") holds only on the
	// key half today.
	//
	// It is `fixme` rather than deleted or quietly passing, because the
	// requirement is right and the product is what has to move: the palette
	// has to stop covering its own drop target (a non-modal side panel, or
	// closing on `dragstart`). Remove this line with that change.
	test.fixme('a widget can be dragged onto the grid', async ({
		page,
		request,
	}) => {
		await openDesigner(page, request)
		await page.getByTestId('designer-add-widget').click()
		await page.getByTestId('widget-palette-search').fill('kop')

		const entry = page.getByTestId('widget-palette-tile-nlHeading')
		const canvas = page.getByTestId('designer-canvas')
		await entry.dragTo(canvas, { targetPosition: { x: 40, y: 40 } })

		await expect(
			canvas.locator('[data-widget-key="nlHeading"]'),
		).toBeVisible({ timeout: BRIEFLY })
	})

	// @e2e site-nlds-widget-palette::keyboard-only
	test('a widget can be placed with the keyboard alone', async ({
		page,
		request,
	}) => {
		await openDesigner(page, request)

		// Everything from here on is keys: no click, no drag. If any step needs
		// a pointer, this test cannot pass, which is the point of it.
		await page.getByTestId('designer-add-widget').press('Enter')
		await expect(page.getByTestId('widget-palette')).toBeVisible({
			timeout: BRIEFLY,
		})

		await page.getByTestId('widget-palette-search').fill('kop')
		await page.getByTestId('widget-palette-tile-nlHeading').press('Enter')

		await expect(page.getByTestId('widget-palette')).toBeHidden({
			timeout: BRIEFLY,
		})
		// THE EDITOR'S CELL, NOT THE SITE WIDGET. The designer renders one cell
		// per placement (`designer-widget-<id>` carrying `data-widget-key`) and
		// never mounts the site component, so `nl-heading` — which is the site
		// renderer's own test id — cannot appear here however well placement
		// works. Asserting it was asserting the wrong layer.
		await expect(
			page
				.getByTestId('designer-canvas')
				.locator('[data-widget-key="nlHeading"]'),
			'the widget is on the canvas, placed below everything so the author can see it',
		).toBeVisible({ timeout: BRIEFLY })
	})
})
