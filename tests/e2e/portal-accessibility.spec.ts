/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * portal-spa-nl-design-system-styling: the signed-in resident portal meets
 * WCAG 2.2 AA, not only the public site (TenderNed 410320, Eis 257: "Het
 * klantportaal voldoet aan de WCAG 2.2 eisen"). axe-core runs against the
 * signed-in shell on each page a resident reaches from the navigation, and a
 * keyboard-only walk opens the inbox and its notification settings without a
 * mouse.
 *
 * axe-core is injected from node_modules, as in site-accessibility.spec.ts.
 * Scoped to the portal shell, so Nextcloud's own public chrome is not
 * reported as a portaliq defect.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'

const AXE_SOURCE = readFileSync(require.resolve('axe-core'), 'utf8')
// eslint-disable-next-line @typescript-eslint/no-require-imports
const AXE_VERSION = require('axe-core/package.json').version as string

const PORTAL_PATH = '/apps/portaliq/portal?org=dev-org'
const API_BASE = '/apps/portaliq/portal/api'

const ADMIN = Buffer.from('admin:admin').toString('base64')

interface AxeViolation {
	id: string
	impact: string | null
	help: string
	nodes: { target: string[] }[]
}

/**
 * Provision a resident, mint a dev session and seed it before the SPA boots.
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
	await page.addInitScript((t) => {
		window.localStorage.setItem('portaliq_token', t)
	}, token)
}

/**
 * The serious and critical WCAG 2.2 AA violations inside the portal shell.
 *
 * @param page The page.
 * @return The violations.
 */
async function seriousViolations(page: Page): Promise<AxeViolation[]> {
	await page.addScriptTag({ content: AXE_SOURCE })
	const results = await page.evaluate<{ violations: AxeViolation[] }>(`
		window.axe.run('.portaliq-shell', {
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

test.describe('signed-in portal: accessibility', () => {
	// @e2e supplier-portal::the-signed-in-portal-has-no-serious-wcag-22-aa-violation
	// @e2e supplier-portal::theme-actually-renders
	test('every page in the navigation has no serious or critical axe violation', async ({
		page,
		request,
	}) => {
		console.log(`axe-core ${AXE_VERSION}`)
		await signIn(request, page)
		await page.goto(PORTAL_PATH)
		const shell = page.locator('.portaliq-shell')
		await expect(shell).toBeVisible()
		// Theme actually renders: the shell carries the resolved theme class.
		await expect(shell).toHaveClass(/theme-/)

		const navItems = page.locator('.portaliq-nav-item')
		const count = await navItems.count()
		expect(count).toBeGreaterThan(0)
		for (let i = 0; i < count; i++) {
			await navItems.nth(i).click()
			await expect(page.locator('.portaliq-loading')).toHaveCount(0)
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
		await page.goto(PORTAL_PATH)
		await expect(page.locator('.portaliq-shell')).toBeVisible()

		// Tab until the inbox entry in the navigation has focus, then Enter.
		let reached = false
		for (let i = 0; i < 40 && !reached; i++) {
			await page.keyboard.press('Tab')
			reached = await page.evaluate(() => {
				const el = document.activeElement
				return (
					!!el
					&& el.classList.contains('portaliq-nav-item')
					&& /inbox|postvak|berichten/i.test(el.textContent || '')
				)
			})
		}
		expect(reached, 'the inbox entry is reachable with Tab').toBe(true)
		await page.keyboard.press('Enter')

		const summary = page.locator('.portaliq-notification-settings summary')
		await expect(summary).toBeVisible()
		await summary.focus()
		await page.keyboard.press('Enter')
		await expect(
			page.locator('.portaliq-notification-settings'),
		).toHaveAttribute('open', '')
		await page.keyboard.press('Tab')
		const focusedIsCheckbox = await page.evaluate(
			() =>
				(document.activeElement as HTMLInputElement | null)?.type
				=== 'checkbox',
		)
		expect(focusedIsCheckbox, 'the first choice is the next Tab stop').toBe(true)
	})
})
