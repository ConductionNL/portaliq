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
import { createPortalApi, getToken, consumeOidcCallbackFragment } from '@portal/lib/portalApi.js'
import PageView from '@portal/components/PageView.jsx'
import InboxPage from '@portal/components/InboxPage.jsx'

// The fixed cross-app inbox nav entry's key (portal-inbox-v2 T05) — distinct
// from any `${contribution.app}:${page.id}` key a real contribution could mint.
const INBOX_KEY = '__inbox__'

// How often to proactively rotate the bearer while a session is active
// (portal-session-hardening-v2, T04) — comfortably inside the 2h default TTL
// so a subject filling in a long form or reading a case is never logged out
// mid-task. A failed/refused rotation is silent (see portalApi.refreshSession);
// the existing bearer simply runs to its natural expiry.
const REFRESH_INTERVAL_MS = 25 * 60 * 1000

// Flatten every contribution's pages into a single navigable list, tagging each
// with its owning contribution so a block's refs resolve in the right scope.
// The unified inbox (portal-inbox-v2) is appended last: a fixed, cross-app nav
// entry that is not sourced from any single contribution's own `pages`.
function buildNav(contributions, t) {
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
	// Surface the fixed cross-app inbox only once contributions have loaded.
	// Appending it on the initial (pre-load) render would make it the sole nav
	// entry, locking the default active page to the empty inbox instead of the
	// subject's first content page.
	if (nav.length > 0) {
		nav.push({ key: INBOX_KEY, label: t('Inbox'), icon: 'Email', special: 'inbox' })
	}
	return nav
}

export default function App({ config, t: tProp }) {
	// `t` is optional so the shell still renders (English fallback) when a
	// caller does not supply a translator — never a blank/undefined string.
	const t = tProp || ((key) => key)
	const api = useMemo(() => createPortalApi(config), [config])
	// Pick up an OIDC callback's bearer BEFORE the initial token read (portal-
	// oidc-broker-login) — the fragment is consumed/stripped exactly once, on
	// mount, so a later re-render never re-parses a stale hash.
	const [token, setTokenState] = useState(() => {
		consumeOidcCallbackFragment()
		return getToken()
	})
	const [state, setState] = useState({ loading: true, session: null, contributions: null, devError: null })
	const [dataByCollection, setDataByCollection] = useState({})
	const [activeKey, setActiveKey] = useState(null)
	const [busyRow, setBusyRow] = useState(null)
	// A live unread-count override (portal-inbox-v2): lets a create's receipt
	// follow-on update the Inbox badge WITHOUT replacing the contributions
	// object, so the active page's form/state (e.g. the just-shown success
	// message) is never disturbed. Null = use the manifest's own count.
	const [unreadOverride, setUnreadOverride] = useState(null)

	const refresh = useCallback(async () => {
		setState((s) => ({ ...s, loading: true }))
		const session = await api.getSession()
		const contributions = session ? await api.getContributions() : null
		setUnreadOverride(null)
		setState({ loading: false, session, contributions, devError: null })
	}, [api])

	useEffect(() => { refresh() }, [refresh, token])

	// Slide the bearer forward ahead of its natural expiry (T04). Runs only
	// while a session is active; deliberately does NOT touch React state on
	// success (the rotated token is swapped in localStorage silently) so a
	// routine rotation never re-triggers the full contributions reload.
	useEffect(() => {
		if (!state.session) {
			return undefined
		}
		const id = setInterval(() => { api.refreshSession() }, REFRESH_INTERVAL_MS)
		return () => clearInterval(id)
	}, [state.session, api])

	const nav = useMemo(() => buildNav(state.contributions?.contributions, t), [state.contributions, t])
	const unreadCount = unreadOverride ?? (state.contributions?.unreadCount || 0)

	// Default to the first CONTENT page once contributions load — never the
	// synthetic cross-app inbox, which would open the portal on an empty
	// message list instead of the subject's actual records.
	useEffect(() => {
		if (nav.length > 0 && (activeKey === null || !nav.some((n) => n.key === activeKey))) {
			const firstContent = nav.find((n) => n.special !== 'inbox') || nav[0]
			setActiveKey(firstContent.key)
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
		// Guard synthetic nav entries (the fixed cross-app inbox, portal-inbox-v2)
		// that carry no `page` — they are rendered by InboxPage, not from blocks.
		// Without this, `active.page.blocks` throws when the inbox tab is active
		// (or is the only nav entry), crashing the whole SPA on load.
		if (!active || !active.page) {
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

	// After a create/update, reload every collection that reads that schema so
	// the new row shows — UNCONDITIONALLY, not only ones already in
	// dataByCollection: if the create completes before a collection's initial
	// load has settled, a `dataByCollection` guard would skip the reload and the
	// stale (pre-create) load would leave the table empty forever. Then refresh
	// the aggregated manifest so the unread inbox badge reflects any
	// server-generated follow-on — e.g. the WMEBV ontvangstbevestiging that
	// SubmissionReceiptService drops into the subject's inbox.
	const onCreated = useCallback((_obj, action) => {
		for (const contribution of (state.contributions?.contributions || [])) {
			for (const collection of (contribution.collections || [])) {
				if (collection.register === action.register && collection.schema === action.schema) {
					loadCollection(collection)
				}
			}
		}
		api.getContributions().then((fresh) => {
			if (fresh && typeof fresh.unreadCount === 'number') {
				setUnreadOverride(fresh.unreadCount)
			}
		})
	}, [state.contributions, loadCollection, api])

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

	// Navigate the WHOLE page to the OIDC start endpoint (portal-oidc-broker-
	// login) — a full-page redirect, never a fetch(), so the broker's own
	// login page renders in place of the portal.
	function oidcLogin(provider) {
		window.location.href = api.oidcStartUrl(provider)
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
							{n.special === 'inbox' && unreadCount > 0 && (
								<span className="portaliq-badge-count">{unreadCount}</span>
							)}
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
						{(config.oidcProviders || []).map((p) => (
							<button
								key={p.provider}
								type="button"
								className="portaliq-oidc-login"
								onClick={() => oidcLogin(p.provider)}
							>
								{t('Log in with {provider}', { provider: p.label })}
							</button>
						))}
						{(config.oidcProviders || []).length === 0 && (
							<p className="portaliq-idp-hint">{t('No login method is configured for this organisation yet.')}</p>
						)}
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

						{nav.length === 0 && <p>{t('No contributions to show yet.')}</p>}

						{active && active.special === 'inbox' && (
							<InboxPage
								api={api}
								t={t}
								locale={config.locale}
								onRead={() => setUnreadOverride((prev) => {
									const current = prev ?? (state.contributions?.unreadCount || 0)
									return Math.max(0, current - 1)
								})}
							/>
						)}

						{active && active.special !== 'inbox' && (
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
