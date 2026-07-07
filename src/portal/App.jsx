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

// Fetch the aggregated portal contributions the subject may see. The endpoint is
// guarded server-side (PortalAuthMiddleware); an unauthenticated call returns 401.
async function fetchContributions(config, token) {
	try {
		const res = await fetch(`${config.apiBase}/contributions`, {
			headers: { Accept: 'application/json', ...authHeaders(token) },
		})
		if (!res.ok) {
			return null
		}
		return await res.json()
	} catch (e) {
		return null
	}
}

export default function App({ config }) {
	const [token, setToken] = useState(() => window.localStorage.getItem(TOKEN_KEY) || null)
	const [state, setState] = useState({ loading: true, session: null, contributions: null, devError: null })
	const [collectionData, setCollectionData] = useState({})

	// Load one collection's objects (subject-scoped, authorised server-side).
	// Keyed by the collection's own `id` and disambiguated on the wire with a
	// `collection` param, so two collections that share a register+schema
	// (a direct view and a scopeClaim/via view) never collide.
	async function loadCollection(col) {
		const key = col.id
		setCollectionData((d) => ({ ...d, [key]: { loading: true, objects: [] } }))
		try {
			const url = `${config.apiBase}/collections/${encodeURIComponent(col.register)}/${encodeURIComponent(col.schema)}?collection=${encodeURIComponent(col.id)}`
			const res = await fetch(url, {
				headers: { Accept: 'application/json', ...authHeaders(token) },
			})
			const objects = res.ok ? ((await res.json()).objects || []) : []
			setCollectionData((d) => ({ ...d, [key]: { loading: false, objects } }))
		} catch (e) {
			setCollectionData((d) => ({ ...d, [key]: { loading: false, objects: [] } }))
		}
	}

	// Perform a declared `create` action: collect the whitelisted fields and POST
	// them; the server stamps ownership. Then reload that collection.
	async function createInCollection(action) {
		const body = {}
		for (const field of (action.fields || [])) {
			const value = window.prompt(`${field}?`, 'Nieuw document')
			if (value === null) {
				return
			}
			body[field] = value
		}
		try {
			await fetch(`${config.apiBase}/collections/${encodeURIComponent(action.register)}/${encodeURIComponent(action.schema)}`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(token) },
				body: JSON.stringify(body),
			})
		} catch (e) {
			// Best-effort; the reload reflects whatever landed.
		}
		// Reload any loaded collection that reads the schema we just wrote to.
		for (const c of (state.contributions?.contributions || [])) {
			for (const col of (c.collections || [])) {
				if (col.register === action.register && col.schema === action.schema) {
					loadCollection(col)
				}
			}
		}
	}

	const refresh = useCallback(async (tok) => {
		setState((s) => ({ ...s, loading: true }))
		const session = await resolveSession(config, tok)
		const contributions = session ? await fetchContributions(config, tok) : null
		setState({ loading: false, session, contributions, devError: null })
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

						{/* Contribution manifest: which collections + actions each app
						    contributes to this subject. Reading the objects in each
						    collection via OpenRegister is the next slice (T05). */}
						{(state.contributions?.contributions || []).length === 0 && (
							<p>Nog geen bijdragen om weer te geven.</p>
						)}
						{(state.contributions?.contributions || []).map((c) => (
							<article key={c.app} className="portaliq-contribution">
								<h2>{c.label || c.app}</h2>
								<ul className="portaliq-collections">
									{(c.collections || []).map((col) => {
										const key = col.id
										const loaded = collectionData[key]
										return (
											<li key={col.id}>
												<button type="button" onClick={() => loadCollection(col)}>
													{col.label || col.schema}
												</button>
												{loaded?.loading && <span> …</span>}
												{loaded && !loaded.loading && (
													<ul className={col.kind === 'inbox' ? 'portaliq-inbox' : 'portaliq-objects'}>
														{loaded.objects.length === 0 && <li><em>{col.kind === 'inbox' ? 'Geen berichten.' : 'Geen items.'}</em></li>}
														{loaded.objects.map((o, i) => (
															col.kind === 'inbox'
																	? (
																		<li key={o.id || i} className={o.read ? 'read' : 'unread'}>
																			<strong>{o.read ? '' : '● '}{o.subject || '(geen onderwerp)'}</strong>
																			{o.body && <div className="portaliq-msg-body">{o.body}</div>}
																		</li>
																	)
																	: <li key={o.id || o['@self']?.id || i}>{o.title || o.name || o.id || '—'}</li>
														))}
													</ul>
												)}
											</li>
										)
									})}
								</ul>
								{(c.actions || []).length > 0 && (
									<div className="portaliq-actions">
										{(c.actions || []).map((a) => (
											a.type === 'create'
												? <button key={a.id} type="button" onClick={() => createInCollection(a)}>{a.label || a.id}</button>
												: <button key={a.id} type="button" disabled={!a.endpoint}>{a.label || a.id}</button>
										))}
									</div>
								)}
							</article>
						))}
						{/* TODO (supplier-portal T07): unified inbox over the OR notification engine. */}
					</section>
				)}
			</main>
		</div>
	)
}
