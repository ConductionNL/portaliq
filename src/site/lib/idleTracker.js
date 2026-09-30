/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The site renderer's idle window (signin-session-idle-warning-and-sso T06).
 * The same rules as the portal SPA, from the same helpers: activity in the
 * second half of the window refreshes the bearer, idling opens the warning,
 * expiry ends the session. The site keeps its bearer per tab in
 * sessionStorage, so each tab times out on its own (design D4).
 */

import {
	ACTIVITY_EVENTS,
	shouldRefresh,
	warningDelayMs,
} from '../../portal/lib/idleSession.js'

/**
 * The current unix time in seconds.
 *
 * @return {number}
 */
function nowSeconds() {
	return Math.floor(Date.now() / 1000)
}

/**
 * The session times from a session or refresh answer, or null.
 *
 * @param {object|null} answer The answer.
 * @return {{expiresAt: number, hardExpiresAt: number, idleTimeout: number}|null}
 */
export function timesOf(answer) {
	if (!answer || !(Number(answer.expiresAt) > 0)) {
		return null
	}
	return {
		expiresAt: Number(answer.expiresAt),
		hardExpiresAt: Number(answer.hardExpiresAt),
		idleTimeout: Number(answer.idleTimeout),
	}
}

/**
 * A tracker for one tab's session.
 *
 * @param {object} callbacks Callbacks.
 * @param {() => Promise<object|null>} callbacks.refresh Refresh the bearer; resolves the answer or null.
 * @param {(times: object) => void} callbacks.onTimes New session times (after a refresh).
 * @param {() => void} callbacks.onWarn The warning is due.
 * @param {() => void} callbacks.onEnd The window ran out.
 * @return {{start: (times: object) => void, stop: () => void, extend: () => Promise<void>}} The tracker.
 * @spec openspec/changes/signin-session-idle-warning-and-sso/tasks.md#T06
 */
export function createIdleTracker({ refresh, onTimes, onWarn, onEnd }) {
	let times = null
	let warned = false
	let lastActivity = 0
	let lastRefresh = 0
	let refreshing = false
	let timers = []

	const clearTimers = () => {
		timers.forEach((id) => clearTimeout(id))
		timers = []
	}

	const schedule = () => {
		clearTimers()
		warned = false
		timers.push(
			setTimeout(
				() => {
					warned = true
					onWarn()
				},
				warningDelayMs(times, nowSeconds()),
			),
		)
		timers.push(
			setTimeout(onEnd, Math.max(0, times.expiresAt - nowSeconds()) * 1000),
		)
	}

	const extend = async () => {
		if (refreshing) {
			return
		}
		refreshing = true
		const next = timesOf(await refresh())
		refreshing = false
		if (next) {
			times = next
			lastRefresh = nowSeconds()
			schedule()
			onTimes(next)
		}
	}

	const onActivity = () => {
		lastActivity = nowSeconds()
		if (
			!warned
			&& shouldRefresh(times, nowSeconds(), lastActivity, lastRefresh)
		) {
			extend()
		}
	}

	return {
		start(initial) {
			this.stop()
			times = timesOf(initial)
			if (!times) {
				return
			}
			lastRefresh = nowSeconds()
			schedule()
			ACTIVITY_EVENTS.forEach((name) =>
				window.addEventListener(name, onActivity, {
					capture: true,
					passive: true,
				}),
			)
		},
		stop() {
			clearTimers()
			ACTIVITY_EVENTS.forEach((name) =>
				window.removeEventListener(name, onActivity, { capture: true }),
			)
			times = null
		},
		extend,
	}
}
