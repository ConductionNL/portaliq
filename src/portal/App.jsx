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

import React, { useCallback, useEffect, useRef, useState } from 'react'

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

// Prettify a raw field name for a fallback column/label ('createdAt' → 'Created at').
function prettyLabel(name) {
	const spaced = String(name).replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ')
	return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

// Column definitions for a collection's schema-driven table: the projected
// `fields` (or a sensible default), labelled from the schema titles.
function columnsFor(col, schema) {
	const fields = (col.fields && col.fields.length > 0) ? col.fields : ['title']
	return fields.map((name) => ({ name, label: (schema?.[name]?.title) || prettyLabel(name) }))
}

// Render a single cell value (dates shortened, objects/arrays stringified).
function formatCell(value) {
	if (value === null || value === undefined || value === '') {
		return '—'
	}
	if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) {
		return value.slice(0, 16).replace('T', ' ')
	}
	if (typeof value === 'object') {
		return value.id || JSON.stringify(value)
	}
	return String(value)
}

// A generic schema-driven table — the Engine-B "objects render from their
// schema" primitive, in miniature.
function SchemaTable({ objects, columns }) {
	return (
		<div style={{ overflowX: 'auto' }}>
			<table className="portaliq-table" style={{ width: '100%', borderCollapse: 'collapse', marginTop: '.5rem' }}>
				<thead>
					<tr>
						{columns.map((c) => (
							<th key={c.name} style={{ textAlign: 'left', borderBottom: '2px solid #ddd', padding: '.35rem .5rem', fontSize: '.85em', opacity: 0.8 }}>{c.label}</th>
						))}
					</tr>
				</thead>
				<tbody>
					{objects.map((o, i) => (
						<tr key={o.id || o['@self']?.id || i}>
							{columns.map((c) => (
								<td key={c.name} style={{ borderBottom: '1px solid #eee', padding: '.35rem .5rem', fontSize: '.9em' }}>{formatCell(o[c.name])}</td>
							))}
						</tr>
					))}
				</tbody>
			</table>
		</div>
	)
}

export default function App({ config }) {
	const [token, setToken] = useState(() => window.localStorage.getItem(TOKEN_KEY) || null)
	const [state, setState] = useState({ loading: true, session: null, contributions: null, devError: null })
	const [collectionData, setCollectionData] = useState({})
	// Schema-driven create form: { action, props, values, submitting, error } or null.
	const [form, setForm] = useState(null)

	// Fetch a schema's field definitions (title/type/enum) so the UI can render
	// schema-driven tables + forms. Cached per register/schema for the session.
	const schemaCacheRef = useRef({})
	async function fetchSchema(register, schema) {
		const key = `${register}/${schema}`
		if (schemaCacheRef.current[key]) {
			return schemaCacheRef.current[key]
		}
		try {
			const res = await fetch(`${config.apiBase}/schema/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`, {
				headers: { Accept: 'application/json', ...authHeaders(token) },
			})
			const props = res.ok ? ((await res.json()).properties || {}) : {}
			schemaCacheRef.current[key] = props
			return props
		} catch (e) {
			return {}
		}
	}

	// Load one collection's objects (subject-scoped, authorised server-side) plus
	// its schema (for column titles). Keyed by the collection's own `id` and
	// disambiguated on the wire with a `collection` param, so two collections
	// that share a register+schema never collide.
	async function loadCollection(col) {
		const key = col.id
		setCollectionData((d) => ({ ...d, [key]: { loading: true, objects: [], schema: {} } }))
		try {
			const url = `${config.apiBase}/collections/${encodeURIComponent(col.register)}/${encodeURIComponent(col.schema)}?collection=${encodeURIComponent(col.id)}`
			const [res, schema] = await Promise.all([
				fetch(url, { headers: { Accept: 'application/json', ...authHeaders(token) } }),
				fetchSchema(col.register, col.schema),
			])
			const objects = res.ok ? ((await res.json()).objects || []) : []
			setCollectionData((d) => ({ ...d, [key]: { loading: false, objects, schema } }))
		} catch (e) {
			setCollectionData((d) => ({ ...d, [key]: { loading: false, objects: [], schema: {} } }))
		}
	}

	// Open a schema-driven create form for a declared `create` action: fetch the
	// schema field definitions and render an input per whitelisted field.
	async function openCreateForm(action) {
		const props = await fetchSchema(action.register, action.schema)
		const values = {}
		for (const field of (action.fields || [])) {
			values[field] = ''
		}
		setForm({ action, props, values, submitting: false, error: null })
	}

	// Submit the schema-driven form: POST the collected fields (server stamps
	// ownership), close the modal, and reload any collection over that schema.
	async function submitForm() {
		const { action, values } = form
		setForm((f) => ({ ...f, submitting: true, error: null }))
		try {
			const res = await fetch(`${config.apiBase}/collections/${encodeURIComponent(action.register)}/${encodeURIComponent(action.schema)}`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...authHeaders(token) },
				body: JSON.stringify(values),
			})
			if (!res.ok) {
				setForm((f) => ({ ...f, submitting: false, error: 'Aanmaken mislukt.' }))
				return
			}
		} catch (e) {
			setForm((f) => ({ ...f, submitting: false, error: 'Aanmaken mislukt.' }))
			return
		}
		setForm(null)
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
	// Accepts an optional preset identity so a tester can switch audiences.
	async function devLogin(identity) {
		const payload = identity || { audience: config.audience || 'supplier' }
		try {
			const res = await fetch(`${config.apiBase}/session/dev-login`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify(payload),
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
						<button type="button" className="portaliq-devlogin" onClick={() => devLogin()}>
							Dev-login (test)
						</button>
						<div style={{ marginTop: '1rem' }}>
							<p style={{ fontSize: '0.85em', opacity: 0.7, margin: '0 0 0.5rem' }}>Of log in als een test-identiteit:</p>
							<button type="button" className="portaliq-devlogin" onClick={() => devLogin({ subjectRef: 'dev-supplier', audience: 'supplier', organisation: 'dev-org', trust: 'low' })}>
								Leverancier (demo)
							</button>{' '}
							<button type="button" className="portaliq-devlogin" onClick={() => devLogin({ subjectRef: 'student-emma', audience: 'student', organisation: 'school-1', trust: 'low' })}>
								Student — Emma
							</button>{' '}
							<button type="button" className="portaliq-devlogin" onClick={() => devLogin({ subjectRef: 'parent-emma', audience: 'parent', organisation: 'school-1', trust: 'substantial' })}>
								Ouder van Emma
							</button>
						</div>
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
												{loaded && !loaded.loading && col.kind === 'inbox' && (
													<ul className="portaliq-inbox">
														{loaded.objects.length === 0 && <li><em>Geen berichten.</em></li>}
														{loaded.objects.map((o, i) => (
															<li key={o.id || i} className={o.read ? 'read' : 'unread'}>
																<strong>{o.read ? '' : '● '}{o.subject || '(geen onderwerp)'}</strong>
																{o.body && <div className="portaliq-msg-body">{o.body}</div>}
															</li>
														))}
													</ul>
												)}
												{loaded && !loaded.loading && col.kind !== 'inbox' && (
													loaded.objects.length === 0
														? <p className="portaliq-empty"><em>Geen items.</em></p>
														: <SchemaTable objects={loaded.objects} columns={columnsFor(col, loaded.schema)} />
												)}
											</li>
										)
									})}
								</ul>
								{(c.actions || []).length > 0 && (
									<div className="portaliq-actions">
										{(c.actions || []).map((a) => (
											a.type === 'create'
												? <button key={a.id} type="button" onClick={() => openCreateForm(a)}>{a.label || a.id}</button>
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

			{form && (
				<div
					className="portaliq-modal-backdrop"
					style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.4)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '3rem 1rem', zIndex: 1000 }}
					onClick={() => !form.submitting && setForm(null)}
				>
					<div
						className="portaliq-modal"
						style={{ background: 'var(--color-main-background, #fff)', color: 'var(--color-main-text, #222)', borderRadius: 8, padding: '1.5rem', width: '100%', maxWidth: 480, boxShadow: '0 8px 30px rgba(0,0,0,.25)' }}
						onClick={(e) => e.stopPropagation()}
					>
						<h2 style={{ marginTop: 0 }}>{form.action.label || 'Nieuw'}</h2>
						{(form.action.fields || []).map((name) => {
							const spec = form.props[name] || { title: prettyLabel(name), type: 'string', enum: [] }
							const val = form.values[name] ?? ''
							const setVal = (v) => setForm((f) => ({ ...f, values: { ...f.values, [name]: v } }))
							const isLong = (spec.maxLength && spec.maxLength > 200) || name === 'description'
							return (
								<label key={name} className="portaliq-field" style={{ display: 'block', marginBottom: '.85rem' }}>
									<span style={{ display: 'block', fontSize: '.85em', fontWeight: 600, marginBottom: '.25rem' }}>{spec.title || prettyLabel(name)}</span>
									{(spec.enum && spec.enum.length > 0)
										? (
											<select value={val} onChange={(e) => setVal(e.target.value)} style={{ width: '100%', padding: '.4rem' }}>
												<option value="">— kies —</option>
												{spec.enum.map((opt) => <option key={opt} value={opt}>{opt}</option>)}
											</select>
										)
										: isLong
											? <textarea rows={4} value={val} onChange={(e) => setVal(e.target.value)} style={{ width: '100%', padding: '.4rem' }} />
											: <input type="text" value={val} onChange={(e) => setVal(e.target.value)} style={{ width: '100%', padding: '.4rem' }} />}
								</label>
							)
						})}
						{form.error && <p className="portaliq-error" style={{ color: '#c00' }}>{form.error}</p>}
						<div style={{ display: 'flex', gap: '.5rem', justifyContent: 'flex-end', marginTop: '1rem' }}>
							<button type="button" onClick={() => setForm(null)} disabled={form.submitting}>Annuleren</button>
							<button type="button" onClick={submitForm} disabled={form.submitting}>{form.submitting ? 'Bezig…' : 'Versturen'}</button>
						</div>
					</div>
				</div>
			)}
		</div>
	)
}
