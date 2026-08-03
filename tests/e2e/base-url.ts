/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ONE place that decides which Nextcloud the e2e suite talks to.
 *
 * The root `playwright.config.ts` already refuses to run without an explicit
 * target — deliberately, because the historic `|| 'http://localhost:8080'`
 * default is the SHARED development container on a developer box, and these
 * specs WRITE (they mint portal sessions, create `exampleDocument` rows, seed
 * `portalMessage` rows through OpenRegister's admin API). This module keeps
 * that discipline while adding the two things the shared CI workflow needs:
 *
 *  1. It accepts `BASE_URL`. That is the name
 *     `ConductionNL/.github/.github/workflows/quality.yml` actually exports to
 *     both the seed step and the test step — not `PLAYWRIGHT_BASE_URL` and not
 *     `NEXTCLOUD_URL`. openconnector adopted a `PLAYWRIGHT_BASE_URL`-only
 *     resolver and its "E2E Tests (Playwright)" job has hard-failed on every
 *     run since with "Error: PLAYWRIGHT_BASE_URL is not set." (The workflow
 *     does export all of them today, so in practice any name resolves — but a
 *     resolver that only accepts one of them is one workflow edit away from
 *     that failure, and accepting the workflow's own variable costs nothing.)
 *
 *  2. It falls back to `http://localhost:8080` ONLY on CI, where that address
 *     is the runner's own throwaway `php -S 0.0.0.0:8080` and nobody else's
 *     data is at risk. Off CI a missing target is a hard error naming the fix.
 */

const CI_DEFAULT_BASE_URL = 'http://localhost:8080'

/**
 * Resolve the Nextcloud base URL for this run.
 *
 * @return the base URL, without a trailing slash
 * @throws when no target is configured outside CI
 */
export function resolveBaseURL(): string {
	const explicit = process.env.PLAYWRIGHT_BASE_URL
		?? process.env.NEXTCLOUD_URL
		?? process.env.NC_BASE_URL
		// Exported by the shared ConductionNL/.github quality workflow.
		?? process.env.BASE_URL

	if (explicit) {
		return explicit.replace(/\/+$/, '')
	}

	if (process.env.CI || process.env.GITHUB_ACTIONS) {
		// eslint-disable-next-line no-console
		console.warn(
			'[portaliq e2e] no PLAYWRIGHT_BASE_URL / NEXTCLOUD_URL / NC_BASE_URL / BASE_URL set; '
			+ `using the CI-local default ${CI_DEFAULT_BASE_URL}.`,
		)
		return CI_DEFAULT_BASE_URL
	}

	throw new Error(
		'[portaliq e2e] No target Nextcloud configured. Set PLAYWRIGHT_BASE_URL (preferred), '
		+ 'NEXTCLOUD_URL, NC_BASE_URL or BASE_URL to the instance you want to test, e.g.\n\n'
		+ '    PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test\n\n'
		+ 'There is deliberately no default: the historic one was http://localhost:8080, the '
		+ 'SHARED development container, and this suite creates portal sessions and OpenRegister '
		+ "rows — running it there corrupts other people's environments.",
	)
}

/** The resolved base URL for this run. */
export const BASE_URL = resolveBaseURL()
