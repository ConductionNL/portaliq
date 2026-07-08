// SPDX-License-Identifier: EUPL-1.2
//
// Portaliq public portal shell (Phase 3 — ADR-063 frontend merge).
//
// Role-based white-label shell for the external audiences. It wires the auth
// edge (resolve the stored bearer against /portal/api/session, fail-closed; a
// debug-gated dev-login mints a test session), then drives the whole UI from the
// subject's contribution manifest: every contribution's `pages` (contribution-
// manifest-v3) become navigable views, each rendered by PageView from typed
// blocks (collection table / schema form / detail / rich text / cta). All data
// flows through the subject-scoped /portal/api adapter — the portal never reads
// OpenRegister directly.

import React, { useCallback, useEffect, useMemo, useState } from 'react'
import { createPortalApi, getToken } from '@portal/lib/portalApi.js'
import PageView from '@portal/components/PageView.jsx'

// Flatten every contribution's pages into a single navigable list, tagging each
// with its owning contribution so a block's refs resolve in the right scope.
function buildNav(contributions) {
	const nav = []
	for (const contribution of (contributions || [])) {
		for (const page of (contribution.pages || [])) {
			nav.push({
				key: `${contribution.app}:${page.id}`,
				label: page.label || page.id,
				icon: page.icon,
				page,
				contribution,
			})
		}
	}
	return nav
}

export default function App({ config }) {
	const api = useMemo(() => createPortalApi(config), [config])
	const [token, setTokenState] = useState(() => getToken())
	const [state, setState] = useState({ loading: true, session: null, contributions: null, devError: null })
	const [dataByCollection, setDataByCollection] = useState({})
	const [activeKey, setActiveKey] = useState(null)
	const [busyRow, setBusyRow] = useState(null)

	const refresh = useCallback(async () => {
		setState((s) => ({ ...s, loading: true }))
		const session = await api.getSession()
		const contributions = session ? await api.getContributions() : null
		setState({ loading: false, session, contributions, devError: null })
	}, [api])

	useEffect(() => { refresh() }, [refresh, token])

	const nav = useMemo(() => buildNav(state.contributions?.contributions), [state.contributions])

	// Default to the first page once contributions load.
	useEffect(() => {
		if (nav.length > 0 && (activeKey === null || !nav.some((n) => n.key === activeKey))) {
			setActiveKey(nav[0].key)
		}
	}, [nav, activeKey])

	const active = useMemo(() => nav.find((n) => n.key === activeKey) || null, [nav, activeKey])

	// Load a collection's objects, subject-scoped, keyed by the collection id.
	const loadCollection = useCallback(async (collection) => {
		setDataByCollection((d) => ({ ...d, [collection.id]: { loading: true, objects: d[collection.id]?.objects || [] } }))
		const objects = await api.fetchCollection(collection)
		setDataByCollection((d) => ({ ...d, [collection.id]: { loading: false, objects } }))
	}, [api])

	// When the active page changes, load every collection it references.
	useEffect(() => {
		if (!active) {
			return
		}
		const ids = new Set()
		for (const block of (active.page.blocks || [])) {
			if ((block.type === 'collection' || block.type === 'detail') && block.collection) {
				ids.add(block.collection)
			}
		}
		for (const id of ids) {
			const collection = (active.contribution.collections || []).find((c) => c.id === id)
			if (collection) {
				loadCollection(collection)
			}
		}
	}, [active, loadCollection])

	// After a create/update, reload any loaded collection that reads that schema.
	const onCreated = useCallback((_obj, action) => {
		for (const contribution of (state.contributions?.contributions || [])) {
			for (const collection of (contribution.collections || [])) {
				if (collection.register === action.register && collection.schema === action.schema && dataByCollection[collection.id]) {
					loadCollection(collection)
				}
			}
		}
	}, [state.contributions, dataByCollection, loadCollection])

	// A per-row status transition (approve/reject/close): invoke a resolved
	// `type: update` action against the row's id with NO field data — the server
	// applies the action's `set` values, so the transition target is enforced
	// server-side and re-scoped to the subject. Then reload that collection.
	const onRowAction = useCallback(async (action, row, collection) => {
		const id = row.id || row['@self']?.id
		if (!id) {
			return
		}
		setBusyRow(id)
		await api.updateObject(action, id, {})
		setBusyRow(null)
		loadCollection(collection)
	}, [api, loadCollection])

	// Forward an endpoint / A6 action server-to-server (best-effort; the shell
	// does not hold the target's credentials — Portaliq signs the assertion).
	const onAction = useCallback(async (action) => {
		if (!action.endpoint && !action.id) {
			return
		}
		// A6 actions are addressed by app + action id; endpoint-only actions are
		// forwarded by the backend. This is a thin trigger; result UI is a
		// follow-up (Phase 4 blocks).
		try {
			await fetch(`${config.apiBase}/actions/${encodeURIComponent(action.app || active?.contribution?.app || '')}/${encodeURIComponent(action.id)}`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(getToken() ? { Authorization: `Bearer ${getToken()}` } : {}) },
				body: '{}',
			})
		} catch (e) {
			/* best-effort */
		}
	}, [config, active])

	async function devLogin() {
		const minted = await api.devLogin(config.audience)
		if (minted) {
			setTokenState(minted)
		} else {
			setState((s) => ({ ...s, devError: 'Dev-login is disabled on this environment.' }))
		}
	}

	async function logout() {
		await api.logout()
		setTokenState(null)
	}

	return (
		<div className={`portaliq-shell theme-${config.theme}`}>
			<header className="portaliq-header">
				<span className="portaliq-org">{config.organisationName}</span>
				{state.session && (
					<button type="button" className="portaliq-logout" onClick={logout}>Uitloggen</button>
				)}
			</header>

			{!state.loading && state.session && nav.length > 0 && (
				<nav className="portaliq-nav">
					{nav.map((n) => (
						<button
							key={n.key}
							type="button"
							className={n.key === activeKey ? 'portaliq-nav-item active' : 'portaliq-nav-item'}
							onClick={() => setActiveKey(n.key)}
						>
							{n.label}
						</button>
					))}
				</nav>
			)}

			<main className="portaliq-main">
				{state.loading && <p>…</p>}

				{!state.loading && !state.session && (
					<section className="portaliq-login">
						<h1>Welkom</h1>
						<p>Log in om uw gegevens te bekijken.</p>
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
						<p className="portaliq-subject">
							Ingelogd als <strong>{state.session.subjectRef}</strong>
							{' '}({state.session.audience} · {state.session.organisation})
						</p>

						{nav.length === 0 && <p>Nog geen bijdragen om weer te geven.</p>}

						{active && (
							<PageView
								page={active.page}
								contribution={active.contribution}
								api={api}
								dataByCollection={dataByCollection}
								onCreated={onCreated}
								onAction={onAction}
								onRowAction={onRowAction}
								busyRow={busyRow}
							/>
						)}
					</section>
				)}
			</main>
		</div>
	)
}
