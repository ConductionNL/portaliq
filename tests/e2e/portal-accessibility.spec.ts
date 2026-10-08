/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-spa-nl-design-system-styling: the signed-in resident portal meets
 * WCAG 2.2 AA, not only the public site (TenderNed 410320, Eis 257: "Het
 * klantportaal voldoet aan de WCAG 2.2 eisen"). axe-core runs against the
 * signed-in area of the site on each page a resident reaches from the
 * signed-in menu, and a keyboard-only walk opens the inbox and its
 * notification settings without a mouse.
 *
 * axe-core is injected from node_modules, as in site-accessibility.spec.ts.
 * Scoped to the site root, so Nextcloud's own public chrome is not reported
 * as a portaliq defect.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { oneOf, PORTAL_API, seedSiteSession, siteAddress } from './portal-nav.ts'

const AXE_SOURCE = readFileSync(require.resolve('axe-core'), 'utf8')
// eslint-disable-next-line @typescript-eslint/no-require-imports
const AXE_VERSION = require('axe-core/package.json').version as string

const API_BASE = PORTAL_API

const ADMIN = Buffer.from('admin:admin').toString('base64')

interface AxeViolation {
	id: string
	impact: string | null
	help: string
	nodes: { target: string[] }[]
}

/**
 * Provision a resident, mint a dev session and seed it before the site boots.
 *
 * @param request The request fixture.
 * @param page The page.
 * @return Nothing.
 */
async function signIn(request: APIRequestContext, page: Page): Promise<void> {
	const provisioned = await request.post('/apps/portaliq/api/accounts/provision', {
		headers: {
			Authorization: `Basic ${ADMIN}`,
			'OCS-APIRequest': 'true',
			requesttoken: 'x',
		},
		data: {
			audience: 'client',
			organisation: 'dev-org',
			identityType: 'digid',
			identityRef: `bsn-a11y-${Date.now()}`,
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
	await seedSiteSession(page, token)
}

/**
 * The serious and critical WCAG 2.2 AA violations inside the site root.
 *
 * @param page The page.
 * @return The violations.
 */
async function seriousViolations(page: Page): Promise<AxeViolation[]> {
	await page.addScriptTag({ content: AXE_SOURCE })
	const results = await page.evaluate<{ violations: AxeViolation[] }>(`
		window.axe.run('[data-testid="site-root"]', {
			runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
		})
	`)
	return results.violations.filter(
		(v) => v.impact === 'serious' || v.impact === 'critical',
	)
}

/**
 * A failure message naming each rule and element.
 *
 * @param violations The violations.
 * @return The message.
 */
function describe(violations: AxeViolation[]): string {
	return violations
		.map(
			(v) =>
				`${v.id} (${v.impact}): ${v.help}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`,
		)
		.join('\n')
}

/**
 * The resident's own menu beside the content ("Mijn omgeving" / "My area",
 * site-resident-menu REQ-SRM-002).
 *
 * @param page The page.
 * @return The menu.
 */
function accountMenu(page: Page) {
	return page.getByRole('navigation', {
		name: oneOf('Mijn omgeving', 'My area'),
	})
}

test.describe('signed-in portal: accessibility', () => {
	// @e2e supplier-portal::the-signed-in-portal-has-no-serious-wcag-22-aa-violation
	// @e2e supplier-portal::theme-actually-renders
	test('every page in the navigation has no serious or critical axe violation', async ({
		page,
		request,
	}) => {
		console.log(`axe-core ${AXE_VERSION}`)
		await signIn(request, page)
		await page.goto(siteAddress())
		const shell = page.getByTestId('site-root')
		await expect(shell).toBeVisible()
		// Theme actually renders: the site root carries the resolved theme class.
		await expect(shell).toHaveClass(/-theme\b/)

		const navItems = accountMenu(page).getByRole('link')
		await expect(navItems.first()).toBeVisible()
		const count = await navItems.count()
		expect(count).toBeGreaterThan(0)
		for (let i = 0; i < count; i++) {
			await navItems.nth(i).click()
			await expect(
				page
					.getByTestId('site-root')
					.getByRole('status')
					.filter({ hasText: oneOf('Laden…', 'Loading…') }),
			).toHaveCount(0)
			const violations = await seriousViolations(page)
			expect(violations, `page ${i + 1}: ${describe(violations)}`).toEqual([])
		}
	})

	// @e2e supplier-portal::a-keyboard-user-reaches-the-inbox-and-its-settings
	test('a keyboard user opens the inbox and its notification settings', async ({
		page,
		request,
	}) => {
		await signIn(request, page)
		await page.goto(siteAddress())
		await expect(accountMenu(page)).toBeVisible()

		// Tab until the inbox link in the signed-in menu has focus, then Enter.
		// Addressed by its route: in Dutch the inbox and Portaliq's contributed
		// messages page are both "Berichten".
		const inboxRoute = encodeURIComponent('/mijn/inbox')
		let reached = false
		for (let i = 0; i < 80 && !reached; i++) {
			await page.keyboard.press('Tab')
			reached = await page.evaluate((route) => {
				const el = document.activeElement
				return (
					!!el
					&& el.tagName === 'A'
					&& !!el.closest('[data-testid="site-resident-menu"]')
					&& (el.getAttribute('href') || '').includes(`route=${route}`)
				)
			}, inboxRoute)
		}
		expect(reached, 'the inbox entry is reachable with Tab').toBe(true)
		await page.keyboard.press('Enter')

		const summary = page.locator('.pq-notification-settings summary')
		await expect(summary).toBeVisible()
		await summary.focus()
		await page.keyboard.press('Enter')
		await expect(page.locator('.pq-notification-settings')).toHaveAttribute(
			'open',
			'',
		)
		await page.keyboard.press('Tab')
		const focusedIsCheckbox = await page.evaluate(
			() =>
				(document.activeElement as HTMLInputElement | null)?.type
				=== 'checkbox',
		)
		expect(focusedIsCheckbox, 'the first choice is the next Tab stop').toBe(true)
	})
})
