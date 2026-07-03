// SPDX-License-Identifier: EUPL-1.2
//
// Portaliq public portal shell (skeleton).
//
// Role-based white-label shell for the two external audiences (client / supplier).
// This slice wires the auth edge: it resolves the stored bearer against
// /portal/api/session (fail-closed), and — when the backend's debug-gated
// dev-login is open — lets you mint a test session without a real IdP. The
// eHerkenning / DigiD handshake, contribution rendering, and inbox land in later
// slices of openspec/changes/supplier-portal.

import React, { useCallback, useEffect, useState } from 'react'

const TOKEN_KEY = 'portaliq_token'

function authHeaders(token) {
	return token ? { Authorization: `Bearer ${token}` } : {}
}

/**
 * Resolve the current portal session. MUST fail closed — any non-200 is treated
 * as "not authenticated". The subjectRef/audience/organisation are derived by
 * the server from the bearer, never sent by the client.
 */
async function resolveSession(config, token) {
	try {
		const res = await fetch(`${config.apiBase}/session`, {
			headers: { Accept: 'application/json', ...authHeaders(token) },
		})
		if (!res.ok) {
			return null
		}
		const body = await res.json()
		return body.authenticated ? body : null
	} catch (e) {
		return null
	}
}

export default function App({ config }) {
	const [token, setToken] = useState(() => window.localStorage.getItem(TOKEN_KEY) || null)
	const [state, setState] = useState({ loading: true, session: null, devError: null })

	const refresh = useCallback(async (tok) => {
		setState((s) => ({ ...s, loading: true }))
		const session = await resolveSession(config, tok)
		setState({ loading: false, session, devError: null })
	}, [config])

	useEffect(() => {
		refresh(token)
	}, [refresh, token])

	// Debug-only helper: mint a session via the backend's gated dev-login.
	// Returns 404 in production, so this button simply won't work there.
	async function devLogin() {
		try {
			const res = await fetch(`${config.apiBase}/session/dev-login`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify({ audience: config.audience || 'supplier' }),
			})
			if (!res.ok) {
				setState((s) => ({ ...s, devError: 'Dev-login is disabled on this environment.' }))
				return
			}
			const body = await res.json()
			window.localStorage.setItem(TOKEN_KEY, body.token)
			setToken(body.token)
		} catch (e) {
			setState((s) => ({ ...s, devError: 'Dev-login request failed.' }))
		}
	}

	async function logout() {
		try {
			await fetch(`${config.apiBase}/session`, { method: 'DELETE', headers: authHeaders(token) })
		} catch (e) {
			// Best-effort; the client token is dropped regardless.
		}
		window.localStorage.removeItem(TOKEN_KEY)
		setToken(null)
	}

	return (
		<div className={`portaliq-shell theme-${config.theme}`}>
			<header className="portaliq-header">
				<span className="portaliq-org">{config.organisationName}</span>
				{state.session && (
					<button type="button" className="portaliq-logout" onClick={logout}>Uitloggen</button>
				)}
			</header>

			<main className="portaliq-main">
				{state.loading && <p>…</p>}

				{!state.loading && !state.session && (
					<section className="portaliq-login">
						<h1>Welkom</h1>
						<p>Log in om uw gegevens te bekijken.</p>
						{/* TODO (supplier-portal T02/T12): real eHerkenning (supplier) / DigiD (client)
						    handshake, driven by config.audience + config.idp. Dormant until OpenConnector. */}
						<button type="button" disabled>
							{config.audience === 'supplier' ? 'Inloggen met eHerkenning' : 'Inloggen met DigiD'}
						</button>
						<button type="button" className="portaliq-devlogin" onClick={devLogin}>
							Dev-login (test)
						</button>
						{state.devError && <p className="portaliq-error">{state.devError}</p>}
					</section>
				)}

				{!state.loading && state.session && (
					<section className="portaliq-home">
						<h1>Mijn overzicht</h1>
						<p>
							Ingelogd als <strong>{state.session.subjectRef}</strong>
							{' '}({state.session.audience} · {state.session.organisation})
						</p>
						{/* TODO (supplier-portal T04–T07): render registered portal contributions
						    (collections + actions) read via OpenRegister, plus the unified inbox. */}
						<p>Nog geen bijdragen om weer te geven.</p>
					</section>
				)}
			</main>
		</div>
	)
}
