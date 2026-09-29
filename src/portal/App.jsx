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

import AccessRequestsPage from '@portal/components/AccessRequestsPage.jsx'
import ActingForSwitcher from '@portal/components/ActingForSwitcher.jsx'
import InboxPage from '@portal/components/InboxPage.jsx'
import MessagesPage from '@portal/components/MessagesPage.jsx'
import MyCasesPage from '@portal/components/MyCasesPage.jsx'
import NewsPage, { hasNews } from '@portal/components/NewsPage.jsx'
import PageView from '@portal/components/PageView.jsx'
import TasksPage from '@portal/components/TasksPage.jsx'
import { actingForHeld, keepActingFor, readActingFor } from '@portal/lib/myCases.js'
import { consumeOpenTarget, forgetOpenTarget, navKeyFor } from '@portal/lib/openRecord.js'
import { consumeOidcCallbackFragment, createPortalApi, getToken } from '@portal/lib/portalApi.js'
import { runAction } from '@portal/lib/rowAction.js'
import { consumeSigninFailed, loginStartUrl } from '@portal/lib/signinRoute.js'
import React, { useCallback, useEffect, useMemo, useState } from 'react'
import Loading from './components/Loading.jsx'

// The fixed cross-app inbox nav entry's key (portal-inbox-v2 T05) — distinct
// from any `${contribution.app}:${page.id}` key a real contribution could mint.
const INBOX_KEY = '__inbox__'

// The fixed "Mijn taken" nav entry's key (portal-task-delivery) — like the
// inbox, a shell surface rather than any contribution's own page: portal
// tasks live behind openregister's assertion-guarded seam, not in a
// contribution collection. Shown only when the backend announces
// `tasks: {enabled: true}` on the contributions aggregate.
const TASKS_KEY = '__tasks__'

// The guardian's conversations with school (translated-message-notice), shown
// only when the subject takes part in at least one message thread, so a
// supplier or citizen portal never shows an empty tab.
const MESSAGES_KEY = '__messages__'

// School news (news-item-translation), shown only when the guardian's feed holds
// an item, so a portal without news never shows an empty tab.
const NEWS_KEY = '__news__'

// The asker's side of an access request (identity-access-requests).
const ACCESS_KEY = '__access__'

// "My cases" (cases-my-cases-page): every app's cases in one list, shown when
// the backend announces `cases: {enabled: true}` on the contributions
// aggregate, that is when some contribution declares a `kind: cases` collection.
const CASES_KEY = '__cases__'

/**
 * sessionStorage, or null where the browser refuses it (private mode, a
 * sandboxed frame): the record link then lives as long as the page.
 *
 * @return {Storage|null} The storage, or null.
 */
function sessionStore() {
	try {
		return window.sessionStorage
	} catch {
		return null
	}
}

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
/**
 *
 * @param contributions
 * @param t
 * @param tasksEnabled
 * @param messagesEnabled
 * @param {boolean} newsEnabled Whether the guardian's feed holds news.
 * @param {boolean} accessEnabled Whether the signed-in user's contributions have loaded.
 * @param {boolean} casesEnabled Whether a contribution declares a case collection.
 */
function buildNav(contributions, t, tasksEnabled, messagesEnabled = false, newsEnabled = false, accessEnabled = false, casesEnabled = false) {
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
	// The fixed "Mijn taken" entry (portal-task-delivery): announced by the
	// backend only when the task seam is actually reachable, and appended
	// even when no contribution pages exist — a party can have an open task
	// without any other portal content.
	// "My cases" first (cases-my-cases-page): the one place a person sees
	// every case from every app, so it is where the portal opens.
	if (casesEnabled) {
		nav.unshift({ key: CASES_KEY, label: t('My cases'), icon: 'FolderAccount', special: 'cases' })
	}
	if (tasksEnabled) {
		nav.push({ key: TASKS_KEY, label: t('My tasks'), icon: 'CheckboxMarkedOutline', special: 'tasks' })
	}
	if (messagesEnabled) {
		nav.push({ key: MESSAGES_KEY, label: t('Messages'), icon: 'MessageText', special: 'messages' })
	}
	if (newsEnabled) {
		nav.push({ key: NEWS_KEY, label: t('News'), icon: 'Newspaper', special: 'news' })
	}
	// Surface the fixed cross-app inbox only once contributions have loaded.
	// Appending it on the initial (pre-load) render would make it the sole nav
	// entry, locking the default active page to the empty inbox instead of the
	// subject's first content page.
	if (nav.length > 0) {
		nav.push({ key: INBOX_KEY, label: t('Inbox'), icon: 'Email', special: 'inbox' })
	}
	// Asking for access to a party's cases (identity-access-requests, T05).
	// Offered to every signed-in user once the contributions have loaded, for
	// the same reason as the inbox; last, so it never becomes the default.
	// Its own entry, because a person without any case yet has no "My
	// cases" page to find it on.
	if (accessEnabled) {
		nav.push({ key: ACCESS_KEY, label: t('Access to cases'), icon: 'AccountKey', special: 'access' })
	}
	return nav
}

/**
 *
 * @param root0
 * @param root0.config
 * @param root0.t
 */
export default function App({ config, t: tProp }) {
	// `t` is optional so the shell still renders (English fallback) when a
	// caller does not supply a translator — never a blank/undefined string.
	//
	// Memoised on `tProp`: without this the identity-fallback arrow is a NEW
	// function on every render, so `t` changes every render, so the `nav`
	// useMemo below (which depends on `t`) recomputes every render and
	// memoises nothing.
	const t = useMemo(() => tProp || ((key) => key), [tProp])
	const api = useMemo(() => createPortalApi(config), [config])
	// A notification's link to one record (inbox-notifications-and-
	// preferences, REQ-NAP-005): read and stripped BEFORE the OIDC fragment
	// check, and kept in sessionStorage so it survives the sign-in.
	const [openTarget, setOpenTarget] = useState(() => consumeOpenTarget(window.location, window.history, sessionStore()))
	// Pick up an OIDC callback's bearer BEFORE the initial token read (portal-
	// oidc-broker-login) — the fragment is consumed/stripped exactly once, on
	// mount, so a later re-render never re-parses a stale hash.
	// A failed broker login comes back as `#signin=failed`, with no reason
	// (signin-integriq-broker-login T10): read and stripped once, on mount.
	const [signinFailed] = useState(() => consumeSigninFailed(window.location, window.history))
	const [token, setTokenState] = useState(() => {
		consumeOidcCallbackFragment()
		return getToken()
	})
	const [state, setState] = useState({ loading: true, session: null, contributions: null, threads: [], news: [], devError: null })
	const [dataByCollection, setDataByCollection] = useState({})
	const [activeKey, setActiveKey] = useState(null)
	const [busyRow, setBusyRow] = useState(null)
	const [actionMessage, setActionMessage] = useState('')
	// A live unread-count override (portal-inbox-v2): lets a create's receipt
	// follow-on update the Inbox badge WITHOUT replacing the contributions
	// object, so the active page's form/state (e.g. the just-shown success
	// message) is never disturbed. Null = use the manifest's own count.
	const [unreadOverride, setUnreadOverride] = useState(null)

	// parent-pwa-installability: the browser's own install offer, captured so
	// this shell can show its own dismissible control instead of (or as well
	// as) the browser's default mini-infobar. Null on a browser that never
	// fires the event (Safari, or an already-installed app) — the control
	// below renders nothing in that case, by construction, not by a flag.
	const [installPrompt, setInstallPrompt] = useState(null)
	const [installDismissed, setInstallDismissed] = useState(false)

	useEffect(() => {
		/**
		 * @param event
		 */
		function onBeforeInstallPrompt(event) {
			event.preventDefault()
			setInstallPrompt(event)
		}

		/**
		 *
		 */
		function onAppInstalled() {
			setInstallPrompt(null)
		}

		window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt)
		window.addEventListener('appinstalled', onAppInstalled)
		return () => {
			window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt)
			window.removeEventListener('appinstalled', onAppInstalled)
		}
	}, [])

	/**
	 *
	 */
	async function installApp() {
		if (!installPrompt) {
			return
		}
		await installPrompt.prompt()
		setInstallPrompt(null)
	}

	const refresh = useCallback(async () => {
		setState((s) => ({ ...s, loading: true }))
		const session = await api.getSession()
		const contributions = session ? await api.getContributions() : null
		const threads = session ? await api.fetchThreads() : []
		const news = session ? await api.fetchNewsFeed() : []
		setUnreadOverride(null)
		setState({ loading: false, session, contributions, threads, news, devError: null })
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

	// Whom the person acts for (cases-my-cases-page REQ-CMC-004): yourself or
	// a mandate, kept for the session. The mandates held are learned from the
	// "My cases" answer; a refusal never forgets them.
	const [actingFor, setActingFor] = useState(() => readActingFor(sessionStore()))
	const [mandates, setMandates] = useState([])
	const onCasesLoaded = useCallback((answer) => {
		if (!answer || !answer.ok) {
			return
		}
		setMandates(answer.mandates || [])
		setActingFor((current) => actingForHeld(current, answer.mandates))
	}, [])
	const chooseActingFor = useCallback((id) => {
		keepActingFor(sessionStore(), id)
		setActingFor(id)
	}, [])

	// The inbox deep link into "Mijn taken" (portal-task-delivery): the task
	// uuid a "Bekijk taak" click hands over, opened once TasksPage mounts.
	const [pendingTaskUuid, setPendingTaskUuid] = useState(null)

	const nav = useMemo(
		() => buildNav(
			state.contributions?.contributions,
			t,
			state.contributions?.tasks?.enabled === true,
			(state.threads || []).length > 0,
			hasNews(state.news),
			Boolean(state.session && state.contributions),
			state.contributions?.cases?.enabled === true,
		),
		[state.session, state.contributions, state.threads, state.news, t],
	)
	const unreadCount = unreadOverride ?? (state.contributions?.unreadCount || 0)

	// Default to the first CONTENT page once contributions load — never the
	// synthetic cross-app inbox, which would open the portal on an empty
	// message list instead of the subject's actual records.
	useEffect(() => {
		if (nav.length > 0 && (activeKey === null || !nav.some((n) => n.key === activeKey))) {
			const firstContent = nav.find((n) => n.special !== 'inbox' && n.special !== 'access') || nav[0]
			setActiveKey(firstContent.key)
		}
	}, [nav, activeKey])

	const active = useMemo(() => nav.find((n) => n.key === activeKey) || null, [nav, activeKey])

	// Signed in with a record to open: make its page active. Declared after
	// the default-page effect, so it wins on the same render. A link whose
	// collection no page shows is dropped rather than kept for ever.
	useEffect(() => {
		if (!openTarget || !state.session || nav.length === 0) {
			return
		}
		forgetOpenTarget(sessionStore())
		const key = navKeyFor(nav, openTarget)
		if (key) {
			setActiveKey(key)
		} else {
			setOpenTarget(null)
		}
	}, [openTarget, state.session, nav])
	const onRecordOpened = useCallback(() => setOpenTarget(null), [])

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

	// Forward an endpoint / A6 action server-to-server by app + action id;
	// Portaliq signs the assertion. The leaf app's answer is shown (or its
	// checked redirect followed), never discarded (#804).
	const onAction = useCallback(async (action) => {
		if (!action || !action.id) {
			return
		}
		setActionMessage('')
		const app = action.app || active?.contribution?.app || ''
		const { redirect, messageKey } = await runAction(api, app, action)
		if (redirect) {
			window.location.assign(redirect)
			return
		}
		setActionMessage(t(messageKey))
	}, [api, active, t])

	// An action's answer belongs to the page it was run on.
	useEffect(() => {
		setActionMessage('')
	}, [activeKey])

	/**
	 *
	 */
	async function devLogin() {
		const minted = await api.devLogin(config.audience)
		if (minted) {
			setTokenState(minted)
		} else {
			setState((s) => ({ ...s, devError: 'Dev-login is disabled on this environment.' }))
		}
	}

	/**
	 *
	 */
	async function logout() {
		await api.logout()
		setTokenState(null)
	}

	// Navigate the WHOLE page to the OIDC start endpoint (portal-oidc-broker-
	// login) — a full-page redirect, never a fetch(), so the broker's own
	// login page renders in place of the portal.
	/**
	 * Start the login for one provider entry.
	 *
	 * @param {object} p The provider entry: `provider`, `label`, `route`.
	 */
	function oidcLogin(p) {
		// The route the organisation chose for this provider: its own OIDC
		// broker or integriq's (signin-integriq-broker-login T09).
		window.location.href = loginStartUrl(config.apiBase, config.organisationSlug, p.provider, p.route)
	}

	return (
		<div className={`portaliq-shell theme-${config.theme}`}>
			<header className="portaliq-header">
				<span className="portaliq-org">{config.organisationName}</span>
				{state.session && (
					<ActingForSwitcher t={t} mandates={mandates} value={actingFor} onChange={chooseActingFor} />
				)}
				{state.session && (
					<button type="button" className="portaliq-logout" onClick={logout}>Uitloggen</button>
				)}
			</header>

			{installPrompt && !installDismissed && (
				<div className="portaliq-install-banner" role="region" aria-label={t('Install this app')}>
					<span>{t('Install this app on your device?')}</span>
					<button type="button" className="portaliq-install-accept" onClick={installApp}>{t('Install')}</button>
					<button type="button" className="portaliq-install-dismiss" onClick={() => setInstallDismissed(true)}>{t('Not now')}</button>
				</div>
			)}

			{!state.loading && state.session && nav.length > 0 && (
				<nav className="portaliq-nav">
					{nav.map((n) => (
						<button
							key={n.key}
							type="button"
							className={n.key === activeKey ? 'portaliq-nav-item active' : 'portaliq-nav-item'}
							onClick={() => {
								// A manual nav click always lands on the page's own
								// start state — never a stale inbox deep link.
								setPendingTaskUuid(null)
								setActiveKey(n.key)
							}}
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
				{state.loading && <Loading t={t} />}

				{!state.loading && !state.session && (
					<section className="portaliq-login">
						<h1>Welkom</h1>
						<p>Log in om uw gegevens te bekijken.</p>
						{(config.oidcProviders || []).map((p) => (
							<button
								key={p.provider}
								type="button"
								className="portaliq-oidc-login"
								onClick={() => oidcLogin(p)}
							>
								{t('Log in with {provider}', { provider: p.label })}
							</button>
						))}
						{signinFailed && (
							<p className="portaliq-error" role="alert">{t('Signing in did not work. Try again or choose another way in.')}</p>
						)}
						{(config.oidcProviders || []).length === 0 && (
							<p className="portaliq-idp-hint">{t('No login method is configured for this organisation yet.')}</p>
						)}
						<button type="button" className="portaliq-devlogin" onClick={devLogin}>
							Dev-login (test)
						</button>
						{state.devError && <p className="portaliq-error" role="alert">{state.devError}</p>}
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
								onOpenRecord={(link) => {
									// The message's "Open" button: the same path as
									// a link from the e-mail, without a reload.
									const key = navKeyFor(nav, link)
									if (key) {
										setOpenTarget(link)
										setActiveKey(key)
									}
								}}
								onOpenTask={(taskUuid) => {
									// The message's "Bekijk taak" deep link: hand the
									// uuid to "Mijn taken" and switch to it.
									setPendingTaskUuid(taskUuid)
									setActiveKey(TASKS_KEY)
								}}
							/>
						)}

						{active && active.special === 'cases' && (
							<MyCasesPage
								api={api}
								t={t}
								locale={config.locale}
								closedMarker={state.contributions?.cases?.closedMarker === true}
								mandateId={actingFor}
								onLoaded={onCasesLoaded}
								canOpen={(target) => navKeyFor(nav, target) !== null}
								onOpenCase={(target, row) => {
									// The same path as a notification's link: the
									// app's page opens with the case selected. The
									// row travels along, so a case read under a
									// mandate opens although it is not in the
									// person's own rows.
									setOpenTarget({ ...target, row })
									setActiveKey(navKeyFor(nav, target))
								}}
							/>
						)}

						{active && active.special === 'messages' && (
							<MessagesPage
								api={api}
								t={t}
								locale={config.locale}
								subjectRef={state.session.subjectRef}
							/>
						)}

						{active && active.special === 'news' && (
							<NewsPage api={api} t={t} locale={config.locale} />
						)}

						{active && active.special === 'access' && (
							<AccessRequestsPage api={api} t={t} locale={config.locale} />
						)}

						{active && active.special === 'tasks' && (
							<TasksPage
								api={api}
								t={t}
								locale={config.locale}
								initialTaskUuid={pendingTaskUuid}
							/>
						)}

						{active && !active.special && actionMessage && (
							<p className="portaliq-rowaction-status" role="status">{actionMessage}</p>
						)}

						{active && !active.special && (
							<PageView
								page={active.page}
								contribution={active.contribution}
								api={api}
								dataByCollection={dataByCollection}
								onCreated={onCreated}
								onAction={onAction}
								onRowAction={onRowAction}
								busyRow={busyRow}
								t={t}
								openRecord={openTarget && openTarget.app === active.contribution?.app ? openTarget : null}
								onRecordOpened={onRecordOpened}
							/>
						)}
					</section>
				)}
			</main>
		</div>
	)
}
