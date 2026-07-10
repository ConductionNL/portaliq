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
//
// UI primitives come from @utrecht/component-library-react (NL Design System)
// per the app's own "React + NL Design System" framing (webpack.portal.js) and
// company ADR-010 (CSS custom properties, WCAG AA) — see
// portal-spa-nl-design-system-styling.

import React, { useCallback, useEffect, useState } from 'react'
import { Button, Heading, Paragraph } from '@utrecht/component-library-react'
import ActionFieldsForm from './components/ActionFieldsForm.jsx'

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

export default function App({ config, t }) {
	const [token, setToken] = useState(() => window.localStorage.getItem(TOKEN_KEY) || null)
	const [state, setState] = useState({ loading: true, session: null, contributions: null, devError: null })
	const [collectionData, setCollectionData] = useState({})
	// Per-action feedback for endpoint-type actions, keyed by action id:
	// { pending, ok } — surfaced instead of silently swallowing the forward's
	// success/failure (portal-contribution-endpoint-actions, task 3.2).
	const [actionFeedback, setActionFeedback] = useState({})
	// The action currently collecting fields via the inline form (replaces
	// window.prompt() — portal-spa-nl-design-system-styling): either
	// { kind: 'create', action } or { kind: 'endpoint', appId, action }, or
	// null when no form is open.
	const [pendingAction, setPendingAction] = useState(null)

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

	// Perform a declared `create` action: POST the (already-collected) fields;
	// the server stamps ownership. Then reload that collection.
	async function createInCollection(action, values) {
		try {
			await fetch(`${config.apiBase}/collections/${encodeURIComponent(action.register)}/${encodeURIComponent(action.schema)}`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(token) },
				body: JSON.stringify(values),
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

	// Invoke a declared `endpoint`-type action (contract v2, A6 —
	// portal-contribution-endpoint-actions) with the (already-collected)
	// fields, forwarding to the authorised server-to-server route. The
	// backend stamps the subject via a signed X-Portal-Subject assertion —
	// never the client's own bearer — and relays the domain app's response
	// as-is.
	async function invokeEndpointAction(appId, action, values) {
		setActionFeedback((f) => ({ ...f, [action.id]: { pending: true } }))
		try {
			const res = await fetch(
				`${config.apiBase}/actions/${encodeURIComponent(appId)}/${encodeURIComponent(action.id)}`,
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(token) },
					body: JSON.stringify(values),
				},
			)
			setActionFeedback((f) => ({ ...f, [action.id]: { pending: false, ok: res.ok } }))
		} catch (e) {
			setActionFeedback((f) => ({ ...f, [action.id]: { pending: false, ok: false } }))
		}
	}

	// Dispatch a clicked action: a declared, non-empty `fields` whitelist opens
	// the inline collection form; otherwise (or once the form is submitted)
	// the action runs immediately with whatever values were collected (`{}`
	// when there were no fields to begin with).
	function startCreateAction(action) {
		if ((action.fields || []).length > 0) {
			setPendingAction({ kind: 'create', action })
			return
		}
		createInCollection(action, {})
	}

	function startEndpointAction(appId, action) {
		if ((action.fields || []).length > 0) {
			setPendingAction({ kind: 'endpoint', appId, action })
			return
		}
		invokeEndpointAction(appId, action, {})
	}

	function submitPendingAction(values) {
		const pending = pendingAction
		setPendingAction(null)
		if (pending?.kind === 'create') {
			createInCollection(pending.action, values)
		} else if (pending?.kind === 'endpoint') {
			invokeEndpointAction(pending.appId, pending.action, values)
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
				setState((s) => ({ ...s, devError: t('Dev-login is disabled on this environment.') }))
				return
			}
			const body = await res.json()
			window.localStorage.setItem(TOKEN_KEY, body.token)
			setToken(body.token)
		} catch (e) {
			setState((s) => ({ ...s, devError: t('Dev-login request failed.') }))
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
					<Button appearance="subtle-button" className="portaliq-logout" onClick={logout}>{t('Log out')}</Button>
				)}
			</header>

			<main className="portaliq-main">
				{state.loading && <Paragraph role="status" aria-live="polite">…</Paragraph>}

				{!state.loading && !state.session && (
					<section className="portaliq-login">
						<Heading level={1}>{t('Welcome')}</Heading>
						<Paragraph>{t('Log in to view your information.')}</Paragraph>
						{/* TODO (supplier-portal T02/T12): real eHerkenning (supplier) / DigiD (client)
						    handshake, driven by config.audience + config.idp. Dormant until OpenConnector. */}
						<Button disabled aria-describedby="portaliq-idp-unavailable">
							{config.audience === 'supplier' ? t('Log in with eHerkenning') : t('Log in with DigiD')}
						</Button>
						<Paragraph id="portaliq-idp-unavailable" appearance="small" className="portaliq-idp-hint">
							{t('Available once your organisation configures eHerkenning/DigiD.')}
						</Paragraph>
						<Button appearance="secondary-action-button" className="portaliq-devlogin" onClick={devLogin}>
							{t('Dev-login (test)')}
						</Button>
						{state.devError && <Paragraph role="alert" className="portaliq-error">{state.devError}</Paragraph>}
					</section>
				)}

				{!state.loading && state.session && (
					<section className="portaliq-home">
						<Heading level={1}>{t('My overview')}</Heading>
						<Paragraph>
							{t('Logged in as {subjectRef}', { subjectRef: state.session.subjectRef })}
							{' '}({state.session.audience} · {state.session.organisation})
						</Paragraph>

						{/* Contribution manifest: which collections + actions each app
						    contributes to this subject. Reading the objects in each
						    collection via OpenRegister is the next slice (T05). */}
						{(state.contributions?.contributions || []).length === 0 && (
							<Paragraph>{t('No contributions to show yet.')}</Paragraph>
						)}
						{(state.contributions?.contributions || []).map((c) => (
							<article key={c.app} className="portaliq-contribution">
								<Heading level={2}>{c.label || c.app}</Heading>
								<ul className="portaliq-collections">
									{(c.collections || []).map((col) => {
										const key = col.id
										const loaded = collectionData[key]
										return (
											<li key={col.id}>
												<Button appearance="subtle-button" onClick={() => loadCollection(col)}>
													{col.label || col.schema}
												</Button>
												{loaded?.loading && <span role="status" aria-live="polite"> …</span>}
												{loaded && !loaded.loading && (
													<ul className={col.kind === 'inbox' ? 'portaliq-inbox' : 'portaliq-objects'}>
														{loaded.objects.length === 0 && <li><em>{col.kind === 'inbox' ? t('No messages.') : t('No items.')}</em></li>}
														{loaded.objects.map((o, i) => (
															col.kind === 'inbox'
																	? (
																		<li key={o.id || i} className={o.read ? 'read' : 'unread'}>
																			<strong>{o.read ? '' : '● '}{o.subject || t('(no subject)')}</strong>
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
										{(c.actions || []).map((a) => {
											const isPendingHere = pendingAction
												&& pendingAction.action.id === a.id
												&& (a.type === 'create' ? pendingAction.kind === 'create' : (pendingAction.kind === 'endpoint' && pendingAction.appId === c.app))

											if (isPendingHere) {
												return (
													<ActionFieldsForm
														key={a.id}
														fields={a.fields || []}
														t={t}
														onSubmit={submitPendingAction}
														onCancel={() => setPendingAction(null)}
													/>
												)
											}

											if (a.type === 'create') {
												return (
													<Button key={a.id} appearance="secondary-action-button" onClick={() => startCreateAction(a)}>
														{a.label || a.id}
													</Button>
												)
											}

											// Endpoint-type action (contract v2, A6): enabled whenever
											// the manifest declares a non-empty endpoint — the SSRF /
											// trust / manifest-membership guards are enforced
											// server-side regardless of what the client sends.
											const feedback = actionFeedback[a.id]
											return (
												<span key={a.id} className="portaliq-endpoint-action">
													<Button
														appearance="secondary-action-button"
														disabled={!a.endpoint || feedback?.pending}
														onClick={() => startEndpointAction(c.app, a)}>
														{a.label || a.id}
													</Button>
													{feedback && !feedback.pending && (
														<span
															role="status"
															className={feedback.ok ? 'portaliq-action-ok' : 'portaliq-action-error'}>
															{feedback.ok ? '✓' : '✕'}
														</span>
													)}
												</span>
											)
										})}
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
