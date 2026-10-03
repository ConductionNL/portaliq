/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * site-multi-step-forms wave 1: the shared field layer on a phone width,
 * keyboard only. The landing page form of tests/e2e/fixtures/seed-cms.sh
 * (/campagne/e2e-form-test, two required fields) stands in for the absence
 * form: every site form renders through the same FieldShell and
 * ErrorSummary (src/site/components/forms), and this fixture needs no
 * signed-in guardian. The node tests in tests/site-form-fields.spec.mjs cover
 * the date group and the optional suffix on all three renderers.
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const BASE = resolveBaseURL()
const SITE = `${BASE}/index.php/apps/portaliq/site`
const ROUTE = '/campagne/e2e-form-test'

test.describe('site forms: the error summary, keyboard only, on a phone', () => {
	test.use({ viewport: { width: 375, height: 740 } })

	// @e2e site-forms::a-required-field-is-announced-as-required
	// @e2e site-forms::a-guardian-forgets-the-last-day
	test('an empty required field: the summary takes focus and its link leads to the field', async ({
		page,
	}) => {
		await page.goto(`${SITE}?route=${ROUTE}`)
		const form = page.getByTestId('site-form')
		await expect(form).toBeVisible()

		const name = form.getByTestId('form-field-name')
		await expect(name).toHaveAttribute('aria-required', 'true')
		await expect(name).not.toHaveAttribute('required', /.*/)
		await expect(form.locator('label')).not.toContainText(['*'])

		await form.getByTestId('form-submit').focus()
		await page.keyboard.press('Enter')

		const heading = page.getByTestId('error-summary-heading')
		await expect(heading).toHaveText('Er ontbreekt nog iets')
		await expect(heading).toBeFocused()
		await expect(page).toHaveTitle(/^Fout: /)

		const link = page.getByTestId('error-summary-link-name')
		await expect(link).toHaveText('Naam is verplicht.')
		await page.keyboard.press('Tab')
		await expect(link).toBeFocused()
		await page.keyboard.press('Enter')
		await expect(name).toBeFocused()
		await expect(page.getByTestId('form-field-error-name')).toHaveText(
			'Naam is verplicht.',
		)
	})
})
