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
import AccountPage, { ContactPrompt } from '@portal/components/AccountPage.jsx'
import ActingForSwitcher from '@portal/components/ActingForSwitcher.jsx'
import BranchSwitcher from '@portal/components/BranchSwitcher.jsx'
import IdleWarningDialog from '@portal/components/IdleWarningDialog.jsx'
import InboxPage from '@portal/components/InboxPage.jsx'
import MessagesPage from '@portal/components/MessagesPage.jsx'
import MyCasesPage from '@portal/components/MyCasesPage.jsx'
import NewsPage from '@portal/components/NewsPage.jsx'
import PageView from '@portal/components/PageView.jsx'
import PortalNotices from '@portal/components/PortalNotices.jsx'
import RegisteredDetailsPage from '@portal/components/RegisteredDetailsPage.jsx'
import TasksPage from '@portal/components/TasksPage.jsx'
import { consumeConfirmEmail, dismissPrompt, promptDismissed, refusalText } from '@portal/lib/account.js'
import { branchInEffect } from '@portal/lib/branch.js'
import { actingForHeld, keepActingFor, readActingFor } from '@portal/lib/myCases.js'
import { consumeOpenTarget, forgetOpenTarget, navKeyFor } from '@portal/lib/openRecord.js'
import { runAction } from '@portal/lib/rowAction.js'
import useIdleSession from '@portal/lib/useIdleSession.js'
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { logoutTarget, markIdleSignOut, silentSignInUrl, takeIdleSignOut } from '../shared/idleSession.js'
import { consumeOidcCallbackFragment, createPortalApi, getToken, setToken } from '../shared/portalApi.js'
import { buildNav, defaultNavKey, NAV_KEYS, shellSections } from '../shared/portalNav.js'
import { consumeSigninFailed, loginStartUrl, signinOrganisation } from '../shared/signinRoute.js'
import Loading from './components/Loading.jsx'

// The shell's own sections (inbox, tasks, messages, news, access, cases,
// details, account) carry fixed keys, distinct from any
// `${contribution.app}:${page.id}` key a real contribution could mint. The
// navigation itself is built in src/shared/portalNav.js, which the Vue site
// renderer uses too.
const { tasks: TASKS_KEY, account: ACCOUNT_KEY } = NAV_KEYS

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
	// A confirmation link (identity-profile-page T08): `#confirm-email=<secret>`
	// is read and stripped once, on mount, and posted; it needs no session.
	const [confirmToken] = useState(() => consumeConfirmEmail(window.location, window.history))
	const [confirmMessage, setConfirmMessage] = useState(null)
	const [promptHidden, setPromptHidden] = useState(() => promptDismissed(sessionStore()))
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

	useEffect(() => {
		if (!confirmToken) {
			return
		}
		api.confirmEmail(confirmToken).then((answer) => {
			setConfirmMessage(answer.ok
				? { role: 'status', text: t('Your e-mail address is confirmed.') }
				: { role: 'alert', text: t(refusalText('link_not_valid')) })
		})
	}, [api, confirmToken, t])

	// The idle window (signin-session-idle-warning-and-sso T03-T05): activity
	// refreshes the bearer, idling opens the warning, expiry ends the session
	// and the login screen says why. A refresh swaps the token in localStorage
	// without touching React state, so it never reloads the contributions.
	const [idleSignedOut, setIdleSignedOut] = useState(() => takeIdleSignOut(sessionStore()))
	const endSession = useCallback((reason) => {
		setToken(null)
		if (reason === 'idle') {
			markIdleSignOut(sessionStore())
			setIdleSignedOut(true)
		}
		setTokenState(null)
	}, [])
	const idle = useIdleSession({ session: state.session, api, onEnded: endSession })
	useEffect(() => {
		if (state.session) {
			setIdleSignedOut(false)
		}
	}, [state.session])

	// Silent sign-in (T09): tried on the first load only, once per browser
	// session, and only when the organisation turned it on. A later signed-out
	// state (a sign-out, an inactivity sign-out) never tries again.
	const firstLoad = useRef(true)
	useEffect(() => {
		if (state.loading || !firstLoad.current) {
			return
		}
		firstLoad.current = false
		if (state.session || signinFailed || idleSignedOut) {
			return
		}
		const url = silentSignInUrl(config, sessionStore())
		if (url) {
			window.location.assign(url)
		}
	}, [state.loading, state.session, signinFailed, idleSignedOut, config])

	// Whom the person acts for (cases-my-cases-page REQ-CMC-004): yourself or
	// a mandate, kept for the session. The mandates held are learned from the
	// "My cases" answer; a refusal never forgets them.
	const [actingFor, setActingFor] = useState(() => readActingFor(sessionStore()))
	// The company's branches a whole-company eHerkenning session may narrow
	// to (signin-eherkenning-branch T06). A session the login restricted to
	// a branch never asks.
	const [branches, setBranches] = useState([])
	const [branchRefused, setBranchRefused] = useState(false)
	const sessionBranchRestricted = state.session ? state.session.branchRestricted === true : true
	useEffect(() => {
		if (sessionBranchRestricted) {
			setBranches([])
			return undefined
		}
		let live = true
		api.fetchBranches().then((answer) => {
			if (live) {
				setBranches(answer.restricted ? [] : answer.branches)
			}
		})
		return () => {
			live = false
		}
	}, [api, sessionBranchRestricted])
	const chooseBranch = useCallback(async (branch) => {
		setBranchRefused(false)
		const answer = await api.chooseBranch(branch)
		if (!answer.ok) {
			setBranchRefused(true)
			return
		}
		// The new bearer names the branch; every list reads it again.
		window.location.reload()
	}, [api])
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
		() => buildNav(state.contributions?.contributions, t, shellSections(state)),
		[state.session, state.contributions, state.threads, state.news, t],
	)
	const unreadCount = unreadOverride ?? (state.contributions?.unreadCount || 0)

	// Default to the first CONTENT page once contributions load — never the
	// synthetic cross-app inbox, which would open the portal on an empty
	// message list instead of the subject's actual records.
	useEffect(() => {
		if (nav.length > 0 && (activeKey === null || !nav.some((n) => n.key === activeKey))) {
			setActiveKey(defaultNavKey(nav))
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
		const answer = await api.logout()
		setTokenState(null)
		// The broker's own sign-out, when it offers one (T11).
		const target = logoutTarget(answer)
		if (target) {
			window.location.assign(target)
		}
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
		window.location.href = loginStartUrl(config.apiBase, signinOrganisation(config), p.provider, p.route, config.organisationSlug)
	}

	return (
		<div className={`portaliq-shell theme-${config.theme}`}>
			<header className="portaliq-header">
				<span className="portaliq-org">{config.organisationName}</span>
				{state.session && (
					<ActingForSwitcher t={t} mandates={mandates} value={actingFor} onChange={chooseActingFor} />
				)}
				{state.session && state.session.branchRestricted !== true && branches.length > 1 && (
					<BranchSwitcher t={t} branches={branches} value={state.session.branch || ''} onChange={chooseBranch} />
				)}
				{state.session && branchInEffect(state.session, t) !== '' && !(state.session.branchRestricted !== true && branches.length > 1) && (
					<span className="portaliq-branch" data-testid="branch-in-effect">{branchInEffect(state.session, t)}</span>
				)}
				{branchRefused && <span className="portaliq-branch-refused" role="alert">{t('That branch could not be chosen.')}</span>}
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

			<PortalNotices notices={config.notices} t={t} />

			{confirmMessage && (
				<p className={confirmMessage.role === 'alert' ? 'portaliq-error' : 'portaliq-notice'} role={confirmMessage.role} data-testid="confirm-email-result">{confirmMessage.text}</p>
			)}

			{state.session && state.session.contactPrompt === true && !promptHidden && (
				<ContactPrompt
					t={t}
					onOpen={() => setActiveKey(ACCOUNT_KEY)}
					onDismiss={() => { dismissPrompt(sessionStore()); setPromptHidden(true) }} />
			)}

			{state.session && idle.warning && idle.times && (
				<IdleWarningDialog
					times={idle.times}
					t={t}
					onStay={idle.extend}
					onSignOut={logout} />
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
						{idleSignedOut && (
							<p className="portaliq-notice" role="status" data-testid="idle-signed-out">{t('You were signed out because you were inactive.')}</p>
						)}
						{(config.oidcProviders || []).length === 0 && (
							<p className="portaliq-idp-hint">{t('No login method is configured for this organisation yet.')}</p>
						)}
						{config.devLogin === true && (
							<button type="button" className="portaliq-devlogin" onClick={devLogin}>
								Dev-login (test)
							</button>
						)}
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

						{active && active.special === 'details' && (
							<RegisteredDetailsPage api={api} t={t} locale={config.locale} />
						)}

						{active && active.special === 'account' && (
							<AccountPage api={api} t={t} onRemoved={logout} />
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
