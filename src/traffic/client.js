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
	errorParams,
	experimentFor,
	fieldIdOf,
	formIdOf,
	heatClickParams,
	mayPersist,
	mayRecord,
	optedOut,
	PATH_ATTRIBUTE,
	pickVariant,
	randomId,
	readConfig,
	scrollDepth,
	scrollPercent,
	searchTermFrom,
	siteRoute,
	STATUS_ATTRIBUTE,
	variantUrl,
	widthBucket,
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
	const api = {
		track() {},
		consent() {},
		disable() {},
		dimension() {},
		recording: null,
	}
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
		// Page experiments (portal-traffic-experiments): the experiment
		// and variant this session was put on, once, and the random half
		// of the seed that makes a cookieless pick sticky for the page
		// load. Stored nowhere: a cookieless client writes nothing.
		experiment: null,
		loadId: randomId(win.crypto),
		changes: null,
		// Heatmaps: the deepest scroll of the current page view.
		heatDepth: 0,
		// Session recording: whether the recorder script was asked for.
		recorderLoaded: false,
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
				loadRecorder()
				return
			}
			forgetIdentity()
		}
		api.disable = () => {
			state.disabled = true
			state.queue = []
			forgetIdentity()
			if (api.recording && typeof api.recording.stop === 'function') {
				api.recording.stop()
			}
		}

		doc.addEventListener('click', onClick, true)
		doc.addEventListener('submit', onSubmit, true)
		doc.addEventListener('focusin', onFocusIn, true)
		doc.addEventListener('focusout', onFocusOut, true)
		win.addEventListener('error', onError)
		win.addEventListener('scroll', onScroll, { passive: true })
		win.addEventListener('pagehide', () => {
			abandonForms()
			heatScroll()
			flush()
		})
		if (heatmapsOn()) {
			doc.addEventListener('click', onHeatClick, true)
			win.addEventListener('scroll', onHeatScroll, { passive: true })
		}
		loadRecorder()
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
		if (state.location !== '') {
			heatScroll()
		}
		if (applyExperiment(location)) {
			return
		}
		state.location = location
		state.scrolled = false
		state.notFound = false
		state.heatDepth = 0
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
	 * Put this session on a variant of the running experiment on this
	 * page, once per session (portal-traffic-experiments), and apply it:
	 * either move to the variant page without a reload, or change the
	 * text the variant names. Returns true when the visitor was moved,
	 * because the page view then belongs to the page they land on.
	 *
	 * The pick is sticky for the session: the seed is the experiment id
	 * plus the client id when the portal persists one (so it survives a
	 * reload) and plus a per-load random otherwise (so it survives the
	 * client side navigation of one visit and nothing is stored).
	 *
	 * @param {string} location The page URL.
	 * @return {boolean} True when the location changed.
	 */
	function applyExperiment(location) {
		const route = siteRoute(location)
		if (state.experiment === null) {
			const experiment = experimentFor(state.traffic, route)
			if (!experiment) {
				return false
			}
			const variant = pickVariant(
				experiment.variants,
				experiment.id + ':' + (state.clientId || state.loadId),
			)
			if (!variant) {
				return false
			}
			state.experiment = { id: String(experiment.id), variant }
		}
		const variant = state.experiment.variant
		const page = experimentFor(state.traffic, route)
		if (!page || String(page.id) !== state.experiment.id) {
			watchChanges(null)
			return false
		}
		if (variant.pageRoute && siteRoute(variant.pageRoute) !== route) {
			const target = variantUrl(location, String(variant.pageRoute))
			win.history.replaceState(win.history.state, '', target)
			let event
			try {
				event = new win.PopStateEvent('popstate', {
					state: win.history.state,
				})
			} catch {
				event = doc.createEvent('Event')
				event.initEvent('popstate', false, false)
			}
			win.dispatchEvent(event)
			return true
		}
		watchChanges(Array.isArray(variant.changes) ? variant.changes : null)
		return false
	}

	/**
	 * Apply a variant's text changes now and whenever the page renders
	 * again, or stop watching when the visitor left the experiment's page.
	 *
	 * @param {Array<object>|null} changes The changes, or null to stop.
	 * @return {void}
	 */
	function watchChanges(changes) {
		if (state.changes && state.changes.observer) {
			state.changes.observer.disconnect()
		}
		state.changes = null
		if (!changes || changes.length === 0) {
			return
		}
		state.changes = { list: changes, observer: null, timer: null }
		applyChanges()
		if (typeof win.MutationObserver !== 'function' || !doc.documentElement) {
			return
		}
		const observer = new win.MutationObserver(() => {
			if (state.changes && state.changes.timer === null) {
				state.changes.timer = win.setTimeout(() => {
					if (state.changes) {
						state.changes.timer = null
						applyChanges()
					}
				}, 50)
			}
		})
		observer.observe(doc.documentElement, {
			childList: true,
			subtree: true,
			characterData: true,
		})
		state.changes.observer = observer
	}

	/**
	 * Set the text of every element a change names, when it differs.
	 * Text only, through textContent, so a variant can never add markup.
	 *
	 * @return {void}
	 */
	function applyChanges() {
		if (!state.changes) {
			return
		}
		state.changes.list.forEach((change) => {
			let nodes
			try {
				nodes = doc.querySelectorAll(String(change.selector || ''))
			} catch {
				return
			}
			const text = String(change.text || '')
			for (let i = 0; i < nodes.length; i++) {
				if (nodes[i].textContent !== text) {
					nodes[i].textContent = text
				}
			}
		})
	}

	/**
	 * Whether the portal switched heatmaps on (portal-traffic-experiments).
	 *
	 * @return {boolean} True when on.
	 */
	function heatmapsOn() {
		return Boolean(
			state.traffic
			&& state.traffic.sensitive
			&& state.traffic.sensitive.heatmaps === true,
		)
	}

	/**
	 * A click as a position on the document, never as what was clicked on
	 * beyond its tag and a short selector.
	 *
	 * @param {MouseEvent} event The click.
	 * @return {void}
	 */
	function onHeatClick(event) {
		const root = doc.documentElement
		const params = heatClickParams(event, {
			width: Math.max(root.scrollWidth, doc.body ? doc.body.scrollWidth : 0),
			height: Math.max(
				root.scrollHeight,
				doc.body ? doc.body.scrollHeight : 0,
			),
			viewport: win.innerWidth,
		})
		if (params) {
			record('heat_click', params)
		}
	}

	/**
	 * Keep the deepest scroll of this page view.
	 *
	 * @return {void}
	 */
	function onHeatScroll() {
		const root = doc.documentElement
		const top = win.pageYOffset || root.scrollTop || 0
		const height = Math.max(
			root.scrollHeight,
			doc.body ? doc.body.scrollHeight : 0,
		)
		state.heatDepth = Math.max(
			state.heatDepth,
			scrollDepth(top, win.innerHeight, height),
		)
	}

	/**
	 * Report the deepest scroll of the page view that is ending, once.
	 *
	 * @return {void}
	 */
	function heatScroll() {
		if (!heatmapsOn() || state.location === '') {
			return
		}
		onHeatScroll()
		const depth = state.heatDepth
		state.heatDepth = 0
		record('heat_scroll', { depth, vw: widthBucket(win.innerWidth) })
	}

	/**
	 * Load the session recorder, once, and only for a portal whose
	 * operator switched recording on, that this app serves, and after
	 * consent where consent is required (portal-traffic-experiments).
	 * The recorder is a separate script so a portal that records nothing
	 * never downloads the code that could.
	 *
	 * @return {void}
	 */
	function loadRecorder() {
		if (
			state.recorderLoaded
			|| state.disabled
			|| !mayRecord(state.traffic, state.consent)
		) {
			return
		}
		state.recorderLoaded = true
		api.recording = {
			id: randomId(win.crypto),
			endpoint: config.origin + config.appPath + '/api/traffic/recording',
			portal: config.portal,
			consent: () => state.consent,
			sessionId: () => state.sessionId,
			route: () => siteRoute(String(win.location.href)),
		}
		const script = doc.createElement('script')
		script.async = true
		script.src = config.origin + config.appPath + '/api/traffic-recorder.js'
		;(doc.head || doc.documentElement).appendChild(script)
	}

	/**
	 * Report a script error (portal-traffic-reporting): message, source
	 * file without its query string, line, column and a stack hash. Never
	 * the stack. Only when the portal enabled `js_error`, which `record`
	 * decides.
	 *
	 * @param {ErrorEvent} event The error event.
	 * @return {void}
	 */
	function onError(event) {
		const params = errorParams(event)
		if (params) {
			record('js_error', params)
		}
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
		const marker = doc.querySelector('[' + STATUS_ATTRIBUTE + '="404"]')
		if (marker) {
			state.notFound = true
			// The route the renderer could not find, when it says: the
			// built-in site carries its route in the query string, which
			// the collector strips from the path.
			const path = String(marker.getAttribute(PATH_ATTRIBUTE) || '')
			record(
				'page_not_found',
				path === '' ? {} : { path: path.substring(0, 256) },
			)
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
		if (state.experiment !== null) {
			params.experiment = state.experiment.id
			params.variant = String(state.experiment.variant.id)
		}
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
