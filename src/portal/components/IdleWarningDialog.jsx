// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The warning before an inactivity sign-out (signin-session-idle-warning-and-
// sso D3, REQ-SIS-003). It counts down on screen, announces the remaining time
// once a minute through a polite live region, and puts the focus on the one
// action that keeps the resident signed in. Near the absolute cap no refresh
// can help, so the dialog then only offers to sign in again (REQ-SIS-004).

import { useEffect, useRef, useState } from 'react'
import { canExtend, remainingText } from '../../shared/idleSession.js'

/**
 * The current unix time in seconds.
 *
 * @return {number}
 */
function nowSeconds() {
	return Math.floor(Date.now() / 1000)
}

/**
 * The idle warning dialog.
 *
 * @param {object} props Props.
 * @param {{expiresAt: number, hardExpiresAt: number}} props.times The session times.
 * @param {(key: string, vars?: object) => string} props.t The translator.
 * @param {() => void} props.onStay "Stay signed in": refresh the session.
 * @param {() => void} props.onSignOut "Sign out", or "Sign in again" near the cap.
 * @return {object} The dialog.
 *
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T04
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T05
 */
export default function IdleWarningDialog({ times, t, onStay, onSignOut }) {
	const [left, setLeft] = useState(() => times.expiresAt - nowSeconds())
	const firstButton = useRef(null)
	const extendable = canExtend(times, nowSeconds())

	useEffect(() => {
		if (firstButton.current) {
			firstButton.current.focus()
		}
		const tick = setInterval(() => setLeft(times.expiresAt - nowSeconds()), 1000)
		return () => clearInterval(tick)
	}, [times])

	const time = remainingText(left, t)
	// Spoken once a minute, not every second: the whole minutes left.
	const spoken = remainingText(Math.ceil(Math.max(0, left) / 60) * 60, t)
	const titleId = 'portaliq-idle-warning-title'
	const bodyId = 'portaliq-idle-warning-body'

	return (
		<div className="portaliq-idle-backdrop">
			<section
				role="alertdialog"
				aria-modal="true"
				aria-labelledby={titleId}
				aria-describedby={bodyId}
				className="portaliq-idle-warning"
				data-testid="idle-warning">
				<h2 id={titleId}>{t('You will be signed out soon')}</h2>
				<p id={bodyId}>
					{extendable
						? t('You will be signed out in {time} because you have been inactive.', { time })
						: t('Your session ends in {time}. Sign in again to keep going.', { time })}
				</p>
				<p className="portaliq-sr-only" aria-live="polite" data-testid="idle-warning-live">
					{extendable
						? t('You will be signed out in {time} because you have been inactive.', { time: spoken })
						: t('Your session ends in {time}. Sign in again to keep going.', { time: spoken })}
				</p>
				<div className="portaliq-rowaction-buttons">
					{extendable && (
						<button ref={firstButton} type="button" className="portaliq-cta" data-testid="idle-stay" onClick={onStay}>
							{t('Stay signed in')}
						</button>
					)}
					{extendable
						? (
							<button type="button" data-testid="idle-sign-out" onClick={onSignOut}>
								{t('Sign out')}
							</button>
						)
						: (
							<button ref={firstButton} type="button" className="portaliq-cta" data-testid="idle-sign-in-again" onClick={onSignOut}>
								{t('Sign in again')}
							</button>
						)}
				</div>
			</section>
		</div>
	)
}
