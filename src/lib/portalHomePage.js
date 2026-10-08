// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The logic behind the "Home page" report on a portal's own page
// (portaliq-cms): where it reads, which of the three states the server
// answered, how severe that is, and which page it can offer to open.
//
// A home page is a page of this portal whose route is `/` and whose status is
// `published`. The portal root stays a CMS page slot and still answers not
// found, so this report is the only thing standing between a portal in that
// state and a visitor discovering it.
//
// It imports nothing, so node tests cover it.

/** The route a portal's home page is served at. */
export const ROOT_ROUTE = '/'

/** The three states the server answers, worst first. */
export const HOME_PAGE_STATES = ['missing', 'draft', 'published']

/**
 * The admin route for one portal's home-page report.
 *
 * @param {string} slug The portal slug.
 * @param {(path: string) => string} generateUrl Nextcloud's URL generator.
 * @return {string} The URL.
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */
export function homePageUrl(slug, generateUrl) {
	return generateUrl(
		`/apps/portaliq/api/portals/${encodeURIComponent(slug)}/home-page`,
	)
}

/**
 * The state the answer names, or 'missing' for anything unreadable.
 *
 * An answer this app does not recognise is read as the worst of the three,
 * never as a pass: a report that falls silent on a shape it did not expect is
 * the same as no report at all.
 *
 * @param {object|null} answer The controller's `homePage` object.
 * @return {string} One of `HOME_PAGE_STATES`.
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */
export function homePageState(answer) {
	const state = String(answer?.state || '')
	return HOME_PAGE_STATES.includes(state) ? state : 'missing'
}

/**
 * Whether the state is a configuration error an administrator must act on.
 *
 * @param {string} state One of `HOME_PAGE_STATES`.
 * @return {boolean}
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */
export function isConfigurationError(state) {
	return state !== 'published'
}

/**
 * The note card type for one state: an absent home page is an error, a draft
 * one is a warning, and a published one is confirmed rather than silent.
 *
 * @param {string} state One of `HOME_PAGE_STATES`.
 * @return {string} An NcNoteCard type.
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */
export function noteType(state) {
	if (state === 'published') {
		return 'success'
	}

	return state === 'draft' ? 'warning' : 'error'
}

/**
 * The route this report can offer to open, or null.
 *
 * A draft at the root is a page that exists, so the report opens it. An
 * absent one has nothing to open, so the report sends the administrator to
 * the portal's pages instead. Both names are manifest page ids, which the
 * router uses as route names.
 *
 * @param {object|null} answer The controller's `homePage` object.
 * @return {{name: string, params: object}|null} A vue-router target.
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */
export function homePageTarget(answer) {
	const id = String(answer?.pageId || '')
	if (homePageState(answer) === 'published') {
		return null
	}

	if (id !== '') {
		return { name: 'PageDetail', params: { id } }
	}

	return { name: 'Pages', params: {} }
}
