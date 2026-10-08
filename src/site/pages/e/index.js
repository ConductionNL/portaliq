// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Slice e of site-reaches-portal-parity: "My cases", "Access to cases", "My
// details" and "My account" on the site, ported from the React portal. The
// shell (slice a) mounts a page by its key; every page is its own lazy chunk,
// because the site bundle is at its size budget. Nothing here is imported
// eagerly except this file, so it keeps its own imports dynamic too.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md

/**
 * The props every page component takes (slice contract, rule 2).
 *
 * @typedef {object} SitePageProps
 * @property {object|null} session The session as `/portal/api/session` returns it.
 * @property {object|null} portal The portal record.
 * @property {object} api The portal API adapter, `createPortalApi(config)` from
 *   src/shared/portalApi.js: the pages call `getDetails`, `setDisplayName`,
 *   `addContactAddress`, `preferContactAddress`, `removeContactAddress`,
 *   `setContactChannel`, `removeOwnAccount`, `fetchRegisteredDetails`,
 *   `requestAccess`, `fetchMyAccessRequests` and `fetchMyCases`.
 * @property {(key: string, vars?: object) => string} t The translator; keys are
 *   the English source strings in ./strings.js (and src/shared/i18n/*.json).
 * @property {(key: string, params?: object) => void} navigate Opens another
 *   section by its key, for example `navigate('__account__')`.
 */

/*
 * Extra props and events, beyond SitePageProps, that the shell may wire:
 *
 * - `__cases__` (MyCasesPage): `closedMarker` (boolean, contributions
 *   `cases.closedMarker`), `canOpen(target)`, `openCase(target, row)` and
 *   `caseRoute(target)` where `target` is `{app, collection, id}` (the shell
 *   resolves it with `navKeyFor` from src/shared/openRecord.js and opens the
 *   app's page with the row selected; `caseRoute` gives the case title a real
 *   address). Without `canOpen` a case is listed but not openable. Emits
 *   `loaded(answer)`. The mandate in effect comes from the acting-for store
 *   (src/site/components/e/actingFor.js), which the header switcher writes.
 * - `__account__` (AccountPage): emits `removed` after the account is removed;
 *   the shell signs out.
 * - `__details__`, `__access__`, `__cases__`: `locale` (string), else the
 *   page's `<html lang>`.
 */

/**
 * The pages of slice e, by the React portal's own section keys (its App.jsx).
 *
 * @type {Record<string, () => Promise<object>>}
 */
export const pages = {
	__cases__: () => import('./MyCasesPage.vue'),
	__access__: () => import('./AccessRequestsPage.vue'),
	__details__: () => import('./RegisteredDetailsPage.vue'),
	__account__: () => import('./AccountPage.vue'),
	__contacts__: () => import('./ContactsPage.vue'),
	__theme__: () => import('./ThemePage.vue'),
	__plans__: () => import('./PlansPage.vue'),
}

/**
 * Handle a `#confirm-email=<secret>` link (identity-profile-page T08). The
 * shell calls this once at boot; it needs no session. The fragment is read
 * once and stripped from the address bar, the secret is posted, and the
 * answer is the sentence to show: `{role: 'status'|'alert', text}`, or null
 * when the page was not opened from such a link.
 *
 * @param {object} options The options.
 * @param {object} options.api The portal API adapter (`confirmEmail(secret)`).
 * @param {(key: string, vars?: object) => string} options.t The translator.
 * @param {Location|object} [options.location] The window location.
 * @param {History|object} [options.history] The window history.
 * @return {Promise<{role: string, text: string}|null>} The outcome, or null.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
 */
export async function confirmEmailFromLink({
	api,
	t,
	location = globalThis.window?.location,
	history = globalThis.window?.history,
}) {
	const { consumeConfirmEmail, refusalText } =
		await import('../../../shared/account.js')
	const secret = consumeConfirmEmail(location, history)
	if (!secret) {
		return null
	}
	const answer = await api.confirmEmail(secret)
	return answer && answer.ok
		? { role: 'status', text: t('Your e-mail address is confirmed.') }
		: { role: 'alert', text: t(refusalText('link_not_valid')) }
}

/**
 * Whether the shell shows the prompt for a missing e-mail address
 * (`ContactPrompt` from src/site/components/e/index.js): the session asks for
 * it and it was not dismissed in this browser session.
 *
 * @param {object|null} session The session.
 * @param {Storage|null} [store] sessionStorage, or null.
 * @return {Promise<boolean>} Whether to show it.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
 */
export async function contactPromptWanted(session, store = defaultStore()) {
	if (!session || session.contactPrompt !== true) {
		return false
	}
	const { promptDismissed } = await import('../../../shared/account.js')
	return !promptDismissed(store)
}

/**
 * sessionStorage, or null where the browser refuses it.
 *
 * @return {Storage|null}
 */
function defaultStore() {
	try {
		return globalThis.window?.sessionStorage ?? null
	} catch {
		return null
	}
}
