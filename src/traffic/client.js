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
	envelope,
	mayPersist,
	optedOut,
	randomId,
	readConfig,
	scrollPercent,
	searchTermFrom,
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
	const api = { track() {}, consent() {}, disable() {} }
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
		win.addEventListener('scroll', onScroll, { passive: true })
		win.addEventListener('pagehide', flush)
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
		record('page_view', {})
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
	 *
	 * @param {Event} event The submit.
	 * @return {void}
	 */
	function onSubmit(event) {
		const form = event.target
		const id =
			form
			&& (form.id
				|| form.getAttribute('name')
				|| form.getAttribute('action')
				|| '')
		record('form_submit', { formId: String(id || '').substring(0, 256) })
		flush()
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
		Object.keys(fields).forEach((key) => {
			if (key === 'searchTerm' || key === 'linkUrl' || key === 'fileName') {
				event[key] = fields[key]
				return
			}
			event.params[key] = fields[key]
		})
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
		batches.forEach((events) => {
			const body = envelope(config.portal, state.consent, events)
			if (
				win.navigator.sendBeacon
				&& win.navigator.sendBeacon(state.collector, body)
			) {
				return
			}
			win.fetch(state.collector, {
				method: 'POST',
				body,
				headers: { 'Content-Type': 'text/plain' },
				mode: 'cors',
				credentials: 'omit',
				keepalive: true,
			}).catch(() => {})
		})
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
				record('session_start', first ? { first: true } : {})
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
