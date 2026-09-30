// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The idle window in the browser (signin-session-idle-warning-and-sso). The
// server ends a bearer one idle window after it was minted; these helpers
// decide when the page refreshes it (only after the resident did something),
// when the warning opens, whether "Stay signed in" can still work, and they
// keep silent sign-in to one attempt per browser session. No framework code:
// the portal SPA (React) and the site renderer (Vue) share them.

import { loginStartUrl } from './signinRoute.js'

/**
 * The events that count as activity: a key press, a pointer press, a touch.
 * Scrolling and mouse movement do not count, so a page left open on a screen
 * still times out (design D2).
 */
export const ACTIVITY_EVENTS = ['keydown', 'pointerdown', 'touchstart']

/**
 * Every string the warning, its cap variant and the login screen use.
 */
export const IDLE_WARNING_STRINGS = [
	'You will be signed out soon',
	'You will be signed out in {time} because you have been inactive.',
	'Your session ends in {time}. Sign in again to keep going.',
	'Stay signed in',
	'Sign out',
	'Sign in again',
	'You were signed out because you were inactive.',
	'{count} minutes',
	'1 minute',
	'{count} seconds',
]

const IDLE_SIGNOUT_KEY = 'portaliq.signedOutIdle'
const SILENT_TRIED_KEY = 'portaliq.silentSignInTried'

/**
 * How many seconds before expiry the warning opens: two minutes, or 40
 * percent of a window shorter than five minutes (design D3).
 *
 * @param {number} idleTimeout The idle window in seconds.
 * @return {number} Seconds.
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T04
 */
export function warningLeadSeconds(idleTimeout) {
	if (Number(idleTimeout) > 0 && Number(idleTimeout) < 300) {
		return Math.round(Number(idleTimeout) * 0.4)
	}
	return 120
}

/**
 * Milliseconds from now until the warning opens, 0 when it is already due.
 *
 * @param {{expiresAt: number, idleTimeout: number}} times The session times.
 * @param {number} nowSeconds The current unix time in seconds.
 * @return {number} Milliseconds.
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T04
 */
export function warningDelayMs(times, nowSeconds) {
	const due = Number(times.expiresAt) - warningLeadSeconds(times.idleTimeout)
	return Math.max(0, due - nowSeconds) * 1000
}

/**
 * Whether the page should refresh the bearer now: there was activity since
 * the last refresh, less than half the window is left, and the absolute cap
 * has not passed (the server would refuse then).
 *
 * @param {{expiresAt: number, hardExpiresAt: number, idleTimeout: number}|null} times The session times.
 * @param {number} nowSeconds The current unix time in seconds.
 * @param {number} lastActivity When the resident last did something, unix seconds.
 * @param {number} lastRefresh When the bearer was last minted or refreshed, unix seconds.
 * @return {boolean}
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T03
 */
export function shouldRefresh(times, nowSeconds, lastActivity, lastRefresh) {
	if (!times || !(Number(times.expiresAt) > 0)) {
		return false
	}
	if (lastActivity <= lastRefresh || nowSeconds >= Number(times.hardExpiresAt)) {
		return false
	}
	return Number(times.expiresAt) - nowSeconds < Number(times.idleTimeout) / 2
}

/**
 * Whether "Stay signed in" can still work: the absolute cap has not passed.
 *
 * @param {{hardExpiresAt: number}} times The session times.
 * @param {number} nowSeconds The current unix time in seconds.
 * @return {boolean}
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T05
 */
export function canExtend(times, nowSeconds) {
	return nowSeconds < Number(times.hardExpiresAt)
}

/**
 * The remaining time in words: whole minutes (rounded up), then seconds.
 *
 * @param {number} seconds Seconds left.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {string}
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T04
 */
export function remainingText(seconds, t) {
	const left = Math.max(0, Math.round(seconds))
	if (left < 60) {
		return t('{count} seconds', { count: left })
	}
	const minutes = Math.ceil(left / 60)
	return minutes === 1 ? t('1 minute') : t('{count} minutes', { count: minutes })
}

/**
 * Remember that the session ended for inactivity, for the login screen.
 *
 * @param {Storage|null} store sessionStorage, or null.
 * @return {void}
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T05
 */
export function markIdleSignOut(store) {
	try {
		store?.setItem(IDLE_SIGNOUT_KEY, '1')
	} catch {
		// No storage: the login screen then shows no reason.
	}
}

/**
 * Whether the last session ended for inactivity; read once.
 *
 * @param {Storage|null} store sessionStorage, or null.
 * @return {boolean}
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T05
 */
export function takeIdleSignOut(store) {
	try {
		if (!store || store.getItem(IDLE_SIGNOUT_KEY) !== '1') {
			return false
		}
		store.removeItem(IDLE_SIGNOUT_KEY)
		return true
	} catch {
		return false
	}
}

/**
 * The silent sign-in address to try on load, or '' when the organisation did
 * not turn it on or this browser session already tried. The attempt is
 * recorded before it is made, so a refused attempt never loops; without
 * storage there is no attempt at all for the same reason.
 *
 * @param {{apiBase: string, organisationSlug: string, silentSignIn: string}} config The runtime config.
 * @param {Storage|null} store sessionStorage, or null.
 * @return {string}
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T09
 */
export function silentSignInUrl(config, store) {
	const provider = String(config?.silentSignIn || '')
	if (provider === '' || !store) {
		return ''
	}
	try {
		if (store.getItem(SILENT_TRIED_KEY) === '1') {
			return ''
		}
		store.setItem(SILENT_TRIED_KEY, '1')
	} catch {
		return ''
	}
	return `${loginStartUrl(config.apiBase, config.organisationSlug, provider, 'oidc')}&silent=1`
}

/**
 * The broker sign-out address from a sign-out answer, or ''. Only an
 * http(s) address is followed.
 *
 * @param {{logoutUrl?: string}|null} answer The DELETE /session answer.
 * @return {string}
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T11
 */
export function logoutTarget(answer) {
	const url = String(answer?.logoutUrl || '')
	return /^https?:\/\//i.test(url) ? url : ''
}
