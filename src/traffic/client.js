// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The traffic client (portal-traffic-analytics, CONTRACT section 5).
//
// One file for every renderer: the built-in site loads it through
// /api/traffic-client.js with a CSP nonce, and a Docusaurus build loads
// the same URL from its own origin. It asks the portal what to measure
// (GET /api/content/site), sends only that, and in the default cookieless
// mode writes NOTHING to the visitor's browser: no cookie, no local
// storage, no session storage. Do Not Track and Global Privacy Control
// switch it off before it reads anything.
//
// Plain syntax, no transpiler: see helpers.js.

import {
	allowedEvent,
	chunk,
	classifyLink,
	dimensionParams,
	envelope,
	fieldIdOf,
	formIdOf,
	mayPersist,
	optedOut,
	randomId,
	readConfig,
	scrollPercent,
	searchTermFrom,
	STATUS_ATTRIBUTE,
} from './helpers.js'

/**
 * The scroll depth, in percent, at which one `scroll` event is sent.
 */
const SCROLL_AT = 90

/**
 * Milliseconds the queue waits for company before a flush.
 */
const FLUSH_AFTER = 2000

/**
 * Queue length that flushes without waiting.
 */
const FLUSH_AT = 20

/**
 * Boot the client in a window.
 *
 * Exported for the bundle's own entry below; a test that wants the pure
 * decisions imports helpers.js instead.
 *
 * @param {Window} win The window.
 * @return {void}
 */
export function boot(win) {
	const doc = win.document
	const api = { track() {}, consent() {}, disable() {}, dimension() {} }
	win.portaliqTraffic = api

	if (optedOut(win.navigator)) {
		return
	}

	const config = readConfig(doc.currentScript, siteConfig(doc))
	if (!config) {
		return
	}

	const state = {
		traffic: null,
		collector: '',
		consent: false,
		disabled: false,
		sequence: 0,
		queue: [],
		timer: null,
		clientId: '',
		sessionId: '',
		scrolled: false,
		location: '',
		// Form analytics (portal-traffic-outcomes): the forms started on
		// this page, by id, each with the field in focus and when it got
		// it. Ids and times only; no field's value is ever read.
		forms: {},
		// Custom dimensions the page set, by id, attached to what is sent.
		dimensions: {},
		notFound: false,
	}

	// A page sets its dimensions while this client is still asking the
	// portal what to measure, so the setter works from the first moment
	// and the values are attached once sending starts.
	api.dimension = (id, value) => {
		state.dimensions[String(id || '')] = value
	}

	const siteUrl =
		config.origin
		+ config.appPath
		+ '/api/content/site'
		+ (config.portal ? '?portal=' + encodeURIComponent(config.portal) : '')

	win.fetch(siteUrl, { credentials: 'omit', mode: 'cors' })
		.then((response) => (response.ok ? response.json() : null))
		.then((site) => {
			if (
				!site
				|| !site.traffic
				|| site.traffic.enabled !== true
				|| !site.collector
			) {
				return
			}
			state.traffic = site.traffic
			state.collector = String(site.collector)
			state.consent = site.traffic.consentRequired !== true
			start()
		})
		.catch(() => {})

	/**
	 * Wire the listeners and send the first page view.
	 *
	 * @return {void}
	 */
	function start() {
		restoreIdentity()

		api.track = (name, params) => record(String(name || ''), params || {})
		api.consent = (granted) => {
			state.consent = granted === true
			if (state.consent) {
				restoreIdentity()
				return
			}
			forgetIdentity()
		}
		api.disable = () => {
			state.disabled = true
			state.queue = []
			forgetIdentity()
		}

		doc.addEventListener('click', onClick, true)
		doc.addEventListener('submit', onSubmit, true)
		doc.addEventListener('focusin', onFocusIn, true)
		doc.addEventListener('focusout', onFocusOut, true)
		win.addEventListener('scroll', onScroll, { passive: true })
		win.addEventListener('pagehide', () => {
			abandonForms()
			flush()
		})
		watchNotFound()
		doc.addEventListener('visibilitychange', () => {
			if (doc.visibilityState === 'hidden') {
				flush()
			}
		})
		win.addEventListener('popstate', () => pageView())
		patchHistory()

		pageView()
	}

	/**
	 * Report a page view for the current location, plus the search term it
	 * carries, and re-arm the scroll marker.
	 *
	 * @return {void}
	 */
	function pageView() {
		const location = String(win.location.href)
		if (location === state.location) {
			return
		}
		state.location = location
		state.scrolled = false
		state.notFound = false
		state.forms = {}
		record('page_view', {})
		notFoundCheck()
		const term = searchTermFrom(location)
		if (term !== '') {
			record('search', { searchTerm: term })
		}
	}

	/**
	 * Route pushState and replaceState through a page view, for a client
	 * side router that never fires popstate on its own navigation.
	 *
	 * @return {void}
	 */
	function patchHistory() {
		const history = win.history
		if (!history) {
			return
		}
		;['pushState', 'replaceState'].forEach((method) => {
			const original = history[method]
			if (typeof original !== 'function') {
				return
			}
			history[method] = function () {
				const result = original.apply(this, arguments)
				win.setTimeout(pageView, 0)
				return result
			}
		})
	}

	/**
	 * One `scroll` at 90 percent, once per page view.
	 *
	 * @return {void}
	 */
	function onScroll() {
		if (state.scrolled) {
			return
		}
		const root = doc.documentElement
		const top = win.pageYOffset || root.scrollTop || 0
		const height = Math.max(
			root.scrollHeight,
			doc.body ? doc.body.scrollHeight : 0,
		)
		if (scrollPercent(top, win.innerHeight, height) >= SCROLL_AT) {
			state.scrolled = true
			record('scroll', { percent: SCROLL_AT })
		}
	}

	/**
	 * Outbound links and downloads.
	 *
	 * @param {Event} event The click.
	 * @return {void}
	 */
	function onClick(event) {
		let node = event.target
		while (node && node.nodeType === 1 && node.tagName !== 'A') {
			node = node.parentNode
		}
		if (!node || node.nodeType !== 1 || !node.href) {
			return
		}
		const link = classifyLink(String(node.href), win.location.host)
		if (!link) {
			return
		}
		const fields = { linkUrl: link.linkUrl }
		if (link.fileName) {
			fields.fileName = link.fileName
		}
		record(link.name, fields)
		flush()
	}

	/**
	 * A form submission, by the form's id or name only. Never a value.
	 * A submitted form is no longer one that can be abandoned.
	 *
	 * @param {Event} event The submit.
	 * @return {void}
	 */
	function onSubmit(event) {
		const id = formIdOf(event.target)
		leaveField(id, Date.now())
		delete state.forms[id]
		record('form_submit', { formId: id })
		flush()
	}

	/**
	 * A field got focus: the first one on a form starts it, and the time
	 * is noted so the blur can say how long the field held focus.
	 *
	 * @param {Event} event The focusin.
	 * @return {void}
	 */
	function onFocusIn(event) {
		const field = event.target
		const fieldId = fieldIdOf(field)
		const formId = formIdOf(field && field.form)
		if (fieldId === '' || formId === '') {
			return
		}
		if (!state.forms[formId]) {
			state.forms[formId] = { field: '', since: 0, last: '' }
			record('form_start', { formId })
		}
		state.forms[formId].field = fieldId
		state.forms[formId].since = Date.now()
	}

	/**
	 * A field lost focus: report which one and for how long. What was
	 * typed into it is not read.
	 *
	 * @param {Event} event The focusout.
	 * @return {void}
	 */
	function onFocusOut(event) {
		const field = event.target
		leaveField(formIdOf(field && field.form), Date.now())
	}

	/**
	 * Close the field in focus on a started form, if any, as one
	 * `form_field` with the milliseconds it held focus.
	 *
	 * @param {string} formId The form.
	 * @param {number} now    The clock.
	 * @return {void}
	 */
	function leaveField(formId, now) {
		const form = state.forms[formId]
		if (!form || form.field === '') {
			return
		}
		record('form_field', {
			formId,
			fieldId: form.field,
			ms: Math.max(0, now - form.since),
		})
		form.last = form.field
		form.field = ''
	}

	/**
	 * The page is going away: every started, unsubmitted form is
	 * abandoned, with the field the visitor was last on.
	 *
	 * @return {void}
	 */
	function abandonForms() {
		const now = Date.now()
		Object.keys(state.forms).forEach((formId) => {
			leaveField(formId, now)
			record('form_abandon', {
				formId,
				lastFieldId: state.forms[formId].last,
			})
		})
		state.forms = {}
	}

	/**
	 * Report the not-found state once per page view, when the document
	 * carries the renderer's marker now.
	 *
	 * @return {void}
	 */
	function notFoundCheck() {
		if (state.notFound || !doc.querySelector) {
			return
		}
		if (doc.querySelector('[' + STATUS_ATTRIBUTE + '="404"]')) {
			state.notFound = true
			record('page_not_found', {})
		}
	}

	/**
	 * The renderer decides it has no page only after it asked the API, so
	 * the marker appears after the page view; watch for it.
	 *
	 * @return {void}
	 */
	function watchNotFound() {
		if (typeof win.MutationObserver !== 'function' || !doc.documentElement) {
			return
		}
		new win.MutationObserver(notFoundCheck).observe(doc.documentElement, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [STATUS_ATTRIBUTE],
		})
	}

	/**
	 * Queue one event when the portal enabled it and consent allows it.
	 *
	 * Dimension-shaped fields (searchTerm, linkUrl, fileName) travel at the
	 * top level of the event, where the collector reads its dimensions;
	 * anything else goes into params.
	 *
	 * @param {string} name   The event name.
	 * @param {object} fields Dimension fields and params.
	 * @return {void}
	 */
	function record(name, fields) {
		if (state.disabled || !allowedEvent(state.traffic, name, state.consent)) {
			return
		}
		const event = {
			name,
			timestamp: new Date().toISOString(),
			sequence: state.sequence++,
			pageLocation: String(win.location.href).substring(0, 512),
			pageReferrer: String(doc.referrer || '').substring(0, 512),
			pageTitle: String(doc.title || '').substring(0, 256),
			params: {},
		}
		const params = dimensionParams(state.traffic, state.dimensions)
		Object.keys(fields).forEach((key) => {
			if (key === 'searchTerm' || key === 'linkUrl' || key === 'fileName') {
				event[key] = fields[key]
				return
			}
			params[key] = fields[key]
		})
		event.params = params
		if (state.clientId) {
			event.clientId = state.clientId
			event.sessionId = state.sessionId
			persistSequence()
		}
		state.queue.push(event)
		if (state.queue.length >= FLUSH_AT) {
			flush()
			return
		}
		if (state.timer === null) {
			state.timer = win.setTimeout(flush, FLUSH_AFTER)
		}
	}

	/**
	 * Post the queue, in batches the collector accepts, as text/plain so
	 * a cross-origin post needs no preflight.
	 *
	 * @return {void}
	 */
	function flush() {
		if (state.timer !== null) {
			win.clearTimeout(state.timer)
			state.timer = null
		}
		if (state.queue.length === 0 || state.collector === '') {
			return
		}
		const batches = chunk(state.queue)
		state.queue = []
		const bearer = linkedBearer()
		batches.forEach((events) => {
			const body = envelope(config.portal, state.consent, events)
			if (
				bearer === ''
				&& win.navigator.sendBeacon
				&& win.navigator.sendBeacon(state.collector, body)
			) {
				return
			}
			const headers = { 'Content-Type': 'text/plain' }
			if (bearer !== '') {
				headers.Authorization = 'Bearer ' + bearer
			}
			win.fetch(state.collector, {
				method: 'POST',
				body,
				headers,
				mode: 'cors',
				credentials: 'omit',
				keepalive: true,
			}).catch(() => {})
		})
	}

	/**
	 * The portal session bearer, ONLY for a portal that links accounts
	 * (Ruben, decision 6). The built-in portal keeps its session token in
	 * local storage; a batch that carries it lets the collector attach the
	 * account's pseudonymous reference. sendBeacon cannot carry a header,
	 * so such a batch goes by fetch. Every other portal never reads the
	 * token at all.
	 *
	 * @return {string} The bearer, or ''.
	 */
	function linkedBearer() {
		const sensitive = state.traffic && state.traffic.sensitive
		if (!sensitive || sensitive.accountLinking !== true) {
			return ''
		}
		try {
			return String(win.localStorage.getItem('portaliq_token') || '')
		} catch {
			return ''
		}
	}

	/**
	 * With persistence on and consent given, load or create the client id
	 * (localStorage), the session id and the sequence (sessionStorage),
	 * scoped to the portal. In every other case this reads and writes
	 * nothing.
	 *
	 * @return {void}
	 */
	function restoreIdentity() {
		if (!mayPersist(state.traffic, state.consent)) {
			return
		}
		const key = storageKey()
		try {
			let clientId = win.localStorage.getItem(key + ':client')
			let first = false
			if (!clientId) {
				clientId = randomId(win.crypto)
				win.localStorage.setItem(key + ':client', clientId)
				first = true
			}
			let sessionId = win.sessionStorage.getItem(key + ':session')
			if (!sessionId) {
				sessionId = randomId(win.crypto)
				win.sessionStorage.setItem(key + ':session', sessionId)
				win.sessionStorage.setItem(key + ':sequence', '0')
				state.sequence = 0
				state.clientId = clientId
				state.sessionId = sessionId
				record('session_start', { visitorType: first ? 'new' : 'returning' })
				return
			}
			state.sequence =
				parseInt(win.sessionStorage.getItem(key + ':sequence') || '0', 10)
				|| 0
			state.clientId = clientId
			state.sessionId = sessionId
		} catch {
			// Storage refused (private mode, a blocking policy): cookieless it is.
		}
	}

	/**
	 * Keep the sequence monotonic across page loads of one session.
	 *
	 * @return {void}
	 */
	function persistSequence() {
		try {
			win.sessionStorage.setItem(
				storageKey() + ':sequence',
				String(state.sequence),
			)
		} catch {
			// Nothing to keep.
		}
	}

	/**
	 * Consent withdrawn or the client disabled: remove every key this
	 * client wrote for the portal, and stop carrying an id.
	 *
	 * @return {void}
	 */
	function forgetIdentity() {
		state.clientId = ''
		state.sessionId = ''
		const key = storageKey()
		try {
			win.localStorage.removeItem(key + ':client')
			win.sessionStorage.removeItem(key + ':session')
			win.sessionStorage.removeItem(key + ':sequence')
		} catch {
			// Nothing was there.
		}
	}

	/**
	 * The storage key prefix, scoped to the portal so two portals on one
	 * origin never share an id.
	 *
	 * @return {string} The prefix.
	 */
	function storageKey() {
		return 'portaliq-traffic:' + (config.portal || win.location.host)
	}
}

/**
 * The built-in renderer's config block, when this is that page.
 *
 * @param {Document} doc The document.
 * @return {object|null} The parsed JSON, or null.
 */
function siteConfig(doc) {
	const node = doc.getElementById('portaliq-site-config')
	if (!node) {
		return null
	}
	try {
		return JSON.parse(node.textContent || 'null')
	} catch {
		return null
	}
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
	boot(window)
}
