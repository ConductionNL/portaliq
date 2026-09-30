// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The portal SPA's idle window (signin-session-idle-warning-and-sso D2-D4).
// Activity (a key, a pointer press, a touch) in the second half of the window
// refreshes the bearer; without activity the warning opens before expiry and
// the session ends at expiry. The bearer lives in localStorage, shared by the
// tabs: a refresh in one tab reschedules the others through the `storage`
// event, and a sign-out in one tab ends the others.

import { useCallback, useEffect, useRef, useState } from 'react'
import { ACTIVITY_EVENTS, shouldRefresh, warningDelayMs } from './idleSession.js'

const TOKEN_STORAGE_KEY = 'portaliq_token'

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
function timesOf(answer) {
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
 * Keep a signed-in session alive while the resident is active, warn before it
 * ends, and end it when the window runs out.
 *
 * @param {object} options Options.
 * @param {object|null} options.session The resolved session, or null.
 * @param {object} options.api The portal api (refreshSession, getSession).
 * @param {(reason: string) => void} options.onEnded Called with `idle` at expiry, or `elsewhere` when another tab signed out.
 * @return {{times: object|null, warning: boolean, extend: () => Promise<void>}} The state.
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T03
 */
export default function useIdleSession({ session, api, onEnded }) {
	const [times, setTimes] = useState(null)
	const [warning, setWarning] = useState(false)
	const lastActivity = useRef(0)
	const lastRefresh = useRef(0)
	const refreshing = useRef(false)

	useEffect(() => {
		setTimes(timesOf(session))
		setWarning(false)
		lastRefresh.current = nowSeconds()
	}, [session])

	const extend = useCallback(async () => {
		if (refreshing.current) {
			return
		}
		refreshing.current = true
		const answer = await api.refreshSession()
		refreshing.current = false
		const next = timesOf(answer)
		if (next) {
			lastRefresh.current = nowSeconds()
			setTimes(next)
			setWarning(false)
		}
	}, [api])

	// Activity refreshes, but only in the second half of the window, and not
	// while the warning is open: there the resident chooses.
	useEffect(() => {
		if (!times || warning) {
			return undefined
		}
		const onActivity = () => {
			lastActivity.current = nowSeconds()
			if (
				shouldRefresh(
					times,
					nowSeconds(),
					lastActivity.current,
					lastRefresh.current,
				)
			) {
				extend()
			}
		}
		for (const name of ACTIVITY_EVENTS) {
			window.addEventListener(name, onActivity, {
				capture: true,
				passive: true,
			})
		}
		return () => {
			for (const name of ACTIVITY_EVENTS) {
				window.removeEventListener(name, onActivity, { capture: true })
			}
		}
	}, [times, warning, extend])

	// The warning, then the end of the session.
	useEffect(() => {
		if (!times) {
			return undefined
		}
		const warn = setTimeout(
			() => setWarning(true),
			warningDelayMs(times, nowSeconds()),
		)
		const end = setTimeout(
			() => onEnded('idle'),
			Math.max(0, times.expiresAt - nowSeconds()) * 1000,
		)
		return () => {
			clearTimeout(warn)
			clearTimeout(end)
		}
	}, [times, onEnded])

	// Another tab refreshed (new bearer) or signed out (bearer removed).
	useEffect(() => {
		if (!times) {
			return undefined
		}
		const onStorage = async (event) => {
			if (event.key !== TOKEN_STORAGE_KEY) {
				return
			}
			if (!event.newValue) {
				onEnded('elsewhere')
				return
			}
			const next = timesOf(await api.getSession())
			if (next) {
				lastRefresh.current = nowSeconds()
				setTimes(next)
				setWarning(false)
			}
		}
		window.addEventListener('storage', onStorage)
		return () => window.removeEventListener('storage', onStorage)
	}, [times, api, onEnded])

	return { times, warning, extend }
}
