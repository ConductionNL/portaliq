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
 * The state of one client in a window: nothing measured, nothing queued,
 * no identity, and the random half of a cookieless experiment seed.
 *
 * @param {Window} win The window.
 * @return {object} The state.
 */
function newClientState(win) {
return {
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
}

/**
 * The portal's site record, where the traffic configuration lives.
 *
 * @param {{origin: string, appPath: string, portal: string}} config The client's config.
 * @return {string} The address.
 */
function siteUrlOf(config) {
	return (
		config.origin
		+ config.appPath
		+ '/api/content/site'
		+ (config.portal ? '?portal=' + encodeURIComponent(config.portal) : '')
	)
}

/**
 * Whether the site record says this portal measures, and where to.
 *
 * @param {object|null} site The site record.
 * @return {boolean} True to start.
 */
function isMeasured(site) {
	return Boolean(
		site && site.traffic && site.traffic.enabled === true && site.collector,
	)
}

/**
 * The page's handle on a client that measures nothing (yet): every call is
 * a no-op until the portal says what to measure.
 *
 * @return {object} The handle: `track`, `consent`, `disable` and `dimension` doing nothing, no `recording`.
 */
function idleApi() {
	return {
		track() {},
		consent() {},
		disable() {},
		dimension() {},
		recording: null,
	}
}

/**
 * The link a click landed on: the nearest anchor up from its target, when
 * it has an address.
 *
 * @param {Node|null} target The click's target.
 * @return {Element|null} The anchor, or null.
 */
function anchorOf(target) {
	let node = target
	while (isElement(node) && node.tagName !== 'A') {
		node = node.parentNode
	}
	return isElement(node) && node.href ? node : null
}

/**
 * Whether a node is an element.
 *
 * @param {Node|null} node The node.
 * @return {boolean} True for an element.
 */
function isElement(node) {
	return Boolean(node) && node.nodeType === 1
}

/**
 * How far a window's page is scrolled down, in pixels.
 *
 * @param {Window} win The window.
 * @return {number} The offset.
 */
function scrollTopOf(win) {
	return win.pageYOffset || win.document.documentElement.scrollTop || 0
}

/**
 * A document's full height, in pixels.
 *
 * @param {Document} doc The document.
 * @return {number} The height.
 */
function pageHeightOf(doc) {
	return Math.max(
		doc.documentElement.scrollHeight,
		doc.body ? doc.body.scrollHeight : 0,
	)
}

/**
 * Whether a variant lives on another page than the route.
 *
 * @param {object} variant The variant.
 * @param {string} route The in-site route.
 * @return {boolean} True when the visitor has to move.
 */
function isElsewhere(variant, route) {
	return Boolean(variant.pageRoute) && siteRoute(variant.pageRoute) !== route
}

/**
 * The text changes a variant names, or null for none.
 *
 * @param {object} variant The variant.
 * @return {Array<object>|null} The changes.
 */
function changesOf(variant) {
	return Array.isArray(variant.changes) ? variant.changes : null
}

/**
 * Whether the experiment running on a route is the one the session joined.
 *
 * @param {object} state The client's state, with the joined `experiment`.
 * @param {string} route The in-site route.
 * @return {boolean} True on the joined experiment's page.
 */
function onJoinedExperiment(state, route) {
	const page = experimentFor(state.traffic, route)
	return Boolean(page) && String(page.id) === state.experiment.id
}

/**
 * A popstate event, also where the browser has no PopStateEvent.
 *
 * @param {Window} win The window.
 * @return {Event} The event.
 */
function popStateEvent(win) {
	try {
		return new win.PopStateEvent('popstate', {
			state: win.history.state,
		})
	} catch {
		const event = win.document.createEvent('Event')
		event.initEvent('popstate', false, false)
		return event
	}
}

/**
 * Stop a variant's observer, when one watches the page.
 *
 * @param {{observer: MutationObserver|null}|null} changes The variant's watched changes.
 * @return {void}
 */
function stopObserving(changes) {
	if (changes && changes.observer) {
		changes.observer.disconnect()
	}
}

/**
 * Whether a window can watch its page for changes.
 *
 * @param {Window} win The window.
 * @return {boolean} True when a MutationObserver and a document element exist.
 */
function canObserve(win) {
	return (
		typeof win.MutationObserver === 'function'
		&& Boolean(win.document.documentElement)
	)
}

/**
 * Watch a window's page text and tree for a re-render.
 *
 * @param {Window} win The window.
 * @param {MutationCallback} onChange Called after the page changed.
 * @return {MutationObserver} The observer.
 */
function observeTree(win, onChange) {
	const observer = new win.MutationObserver(onChange)
	observer.observe(win.document.documentElement, {
		childList: true,
		subtree: true,
		characterData: true,
	})
	return observer
}

/**
 * The elements a selector names; none for a selector the browser refuses.
 *
 * @param {Document} doc The document.
 * @param {string|undefined} selector The selector.
 * @return {NodeList|Array} The elements.
 */
function elementsMatching(doc, selector) {
	try {
		return doc.querySelectorAll(String(selector || ''))
	} catch {
		return []
	}
}

/**
 * Set the text of every element one change names, when it differs. Text
 * only, through textContent, so a variant can never add markup.
 *
 * @param {Document} doc The document.
 * @param {{selector?: string, text?: string}} change The change.
 * @return {void}
 */
function applyChange(doc, change) {
	const nodes = elementsMatching(doc, change.selector)
	const text = String(change.text || '')
	for (let i = 0; i < nodes.length; i++) {
		if (nodes[i].textContent !== text) {
			nodes[i].textContent = text
		}
	}
}

/**
 * The route the renderer could not find, when it says: the built-in site
 * carries its route in the query string, which the collector strips from
 * the path.
 *
 * @param {Element} marker The renderer's not-found marker.
 * @return {{path?: string}} The params.
 */
function notFoundParams(marker) {
	const path = String(marker.getAttribute(PATH_ATTRIBUTE) || '')
	return path === '' ? {} : { path: path.substring(0, 256) }
}

/**
 * The headers of a batch sent by fetch, with the bearer when there is one.
 *
 * @param {string} bearer The bearer, or ''.
 * @return {object} The headers.
 */
function batchHeaders(bearer) {
	const headers = { 'Content-Type': 'text/plain' }
	if (bearer !== '') {
		headers.Authorization = 'Bearer ' + bearer
	}
	return headers
}

/**
 * The fetch request that posts one batch, with the bearer of a linked
 * account when there is one.
 *
 * @param {string} body The batch's envelope.
 * @param {string} bearer The bearer, or ''.
 * @return {object} The request init.
 */
function batchRequest(body, bearer) {
	return {
		method: 'POST',
		body,
		headers: batchHeaders(bearer),
		mode: 'cors',
		credentials: 'omit',
		keepalive: true,
	}
}

/**
 * Whether the portal links visits to accounts.
 *
 * @param {object|null} traffic The portal's traffic configuration.
 * @return {boolean} True when account linking is on.
 */
function linksAccounts(traffic) {
	const sensitive = traffic && traffic.sensitive
	return Boolean(sensitive) && sensitive.accountLinking === true
}

/**
 * The portal session bearer, ONLY for a portal that links accounts
 * (Ruben, decision 6). The built-in portal keeps its session token in
 * local storage; a batch that carries it lets the collector attach the
 * account's pseudonymous reference. sendBeacon cannot carry a header,
 * so such a batch goes by fetch. Every other portal never reads the
 * token at all.
 *
 * @param {Window} win The window.
 * @param {object|null} traffic The portal's traffic configuration.
 * @return {string} The bearer, or ''.
 */
function linkedBearer(win, traffic) {
	if (!linksAccounts(traffic)) {
		return ''
	}
	try {
		return String(win.localStorage.getItem('portaliq_token') || '')
	} catch {
		return ''
	}
}

/**
 * A session's stored sequence number, or 0.
 *
 * @param {Storage} storage The session storage.
 * @param {string} key The storage key prefix.
 * @return {number} The sequence.
 */
function storedSequence(storage, key) {
	return parseInt(storage.getItem(key + ':sequence') || '0', 10) || 0
}

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
	const api = idleApi()
	win.portaliqTraffic = api

	if (optedOut(win.navigator)) {
		return
	}

	const config = readConfig(doc.currentScript, siteConfig(doc))
	if (!config) {
		return
	}

	const state = newClientState(win)

	// A page sets its dimensions while this client is still asking the
	// portal what to measure, so the setter works from the first moment
	// and the values are attached once sending starts.
	api.dimension = (id, value) => {
		state.dimensions[String(id || '')] = value
	}

	win.fetch(siteUrlOf(config), { credentials: 'omit', mode: 'cors' })
		.then((response) => (response.ok ? response.json() : null))
		.then(measure)
		.catch(() => {})

	/**
	 * Start measuring when the site record says this portal measures, under
	 * the consent it asks for.
	 *
	 * @param {object|null} site The site record.
	 * @return {void}
	 */
	function measure(site) {
		if (!isMeasured(site)) {
			return
		}
		state.traffic = site.traffic
		state.collector = String(site.collector)
		state.consent = site.traffic.consentRequired !== true
		start()
	}

	/**
	 * Wire the listeners and send the first page view.
	 *
	 * @return {void}
	 */
	function start() {
		restoreIdentity()
		exposeApi()
		listen()
		pageView()
	}

	/**
	 * The page's handle on the client: track an event, give or withdraw
	 * consent, switch the client off.
	 *
	 * @return {void}
	 */
	function exposeApi() {
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
	}

	/**
	 * Wire the listeners: links, forms, errors, scrolling, heatmaps, the
	 * recorder, the not-found marker, the page going away and navigation.
	 *
	 * @return {void}
	 */
	function listen() {
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
		const top = scrollTopOf(win)
		const height = pageHeightOf(doc)
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
		const node = anchorOf(event.target)
		if (!node) {
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
		if (state.experiment === null && !joinExperiment(route)) {
			return false
		}
		if (!onJoinedExperiment(state, route)) {
			watchChanges(null)
			return false
		}
		return applyVariant(location, route)
	}

	/**
	 * Apply the joined variant on its experiment's page: move to the
	 * variant's own page, or watch for the text it changes.
	 *
	 * @param {string} location The page URL.
	 * @param {string} route The in-site route.
	 * @return {boolean} True when the location changed.
	 */
	function applyVariant(location, route) {
		const variant = state.experiment.variant
		if (isElsewhere(variant, route)) {
			moveToVariant(location, variant)
			return true
		}
		watchChanges(changesOf(variant))
		return false
	}

	/**
	 * Put this session on a variant of the experiment running on a route.
	 *
	 * @param {string} route The in-site route.
	 * @return {boolean} True when the session is now in an experiment.
	 */
	function joinExperiment(route) {
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
		return true
	}

	/**
	 * Move to the variant's page without a reload, so the site's own
	 * router renders it.
	 *
	 * @param {string} location The page URL.
	 * @param {object} variant The variant with its `pageRoute`.
	 * @return {void}
	 */
	function moveToVariant(location, variant) {
		const target = variantUrl(location, String(variant.pageRoute))
		win.history.replaceState(win.history.state, '', target)
		win.dispatchEvent(popStateEvent(win))
	}

	/**
	 * Apply a variant's text changes now and whenever the page renders
	 * again, or stop watching when the visitor left the experiment's page.
	 *
	 * @param {Array<object>|null} changes The changes, or null to stop.
	 * @return {void}
	 */
	function watchChanges(changes) {
		stopObserving(state.changes)
		state.changes = null
		if (!changes || changes.length === 0) {
			return
		}
		state.changes = { list: changes, observer: null, timer: null }
		applyChanges()
		if (!canObserve(win)) {
			return
		}
		state.changes.observer = observeTree(win, scheduleChanges)
	}

	/**
	 * Apply the variant's changes again soon after the page changed,
	 * coalescing a burst of changes into one pass.
	 *
	 * @return {void}
	 */
	function scheduleChanges() {
		if (!state.changes || state.changes.timer !== null) {
			return
		}
		state.changes.timer = win.setTimeout(() => {
			if (state.changes) {
				state.changes.timer = null
				applyChanges()
			}
		}, 50)
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
		state.changes.list.forEach((change) => applyChange(doc, change))
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
		if (!marker) {
			return
		}
		state.notFound = true
		record('page_not_found', notFoundParams(marker))
	}

	/**
	 * The renderer decides it has no page only after it asked the API, so
	 * the marker appears after the page view; watch for it.
	 *
	 * @return {void}
	 */
	function watchNotFound() {
		if (!canObserve(win)) {
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
		const event = newEvent(name)
		event.params = eventParams(event, fields)
		if (state.clientId) {
			event.clientId = state.clientId
			event.sessionId = state.sessionId
			persistSequence()
		}
		enqueue(event)
	}

	/**
	 * A new event of this page, numbered in the session's sequence.
	 *
	 * @param {string} name The event name.
	 * @return {object} The event, without its params yet.
	 */
	function newEvent(name) {
		return {
			name,
			timestamp: new Date().toISOString(),
			sequence: state.sequence++,
			// Never the fragment: it can carry a mailed secret (#email-link=).
			pageLocation: String(win.location.href)
				.replace(/#.*$/, '')
				.substring(0, 512),
			pageReferrer: String(doc.referrer || '').substring(0, 512),
			pageTitle: String(doc.title || '').substring(0, 256),
			params: {},
		}
	}

	/**
	 * An event's params: the custom dimensions, the fields that are not
	 * dimension-shaped (those go on the event itself), and the experiment
	 * the session is on.
	 *
	 * @param {object} event The event, given its dimension-shaped fields.
	 * @param {object} fields Dimension fields and params.
	 * @return {object} The params.
	 */
	function eventParams(event, fields) {
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
		return params
	}

	/**
	 * Queue an event: post at once when the queue is full, else soon.
	 *
	 * @param {object} event The event.
	 * @return {void}
	 */
	function enqueue(event) {
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
		const bearer = linkedBearer(win, state.traffic)
		batches.forEach((events) => sendBatch(events, bearer))
	}

	/**
	 * Post one batch: by beacon when no bearer goes along and the browser
	 * takes it, else by fetch, with the bearer of a linked account.
	 *
	 * @param {Array<object>} events The batch.
	 * @param {string} bearer The bearer, or ''.
	 * @return {void}
	 */
	function sendBatch(events, bearer) {
		const body = envelope(config.portal, state.consent, events)
		if (
			bearer === ''
			&& win.navigator.sendBeacon
			&& win.navigator.sendBeacon(state.collector, body)
		) {
			return
		}
		win.fetch(state.collector, batchRequest(body, bearer)).catch(() => {})
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
			resumeSession(key)
		} catch {
			// Storage refused (private mode, a blocking policy): cookieless it is.
		}
	}

	/**
	 * Pick up the stored session and its sequence, or start a new session.
	 *
	 * @param {string} key The storage key prefix.
	 * @return {void}
	 */
	function resumeSession(key) {
		const client = storedClient(key)
		const sessionId = win.sessionStorage.getItem(key + ':session')
		if (!sessionId) {
			startSession(key, client)
			return
		}
		state.sequence = storedSequence(win.sessionStorage, key)
		state.clientId = client.id
		state.sessionId = sessionId
	}

	/**
	 * The portal's client id from local storage, created and kept the first
	 * time.
	 *
	 * @param {string} key The storage key prefix.
	 * @return {{id: string, first: boolean}} The id, and whether it is new.
	 */
	function storedClient(key) {
		const kept = win.localStorage.getItem(key + ':client')
		if (kept) {
			return { id: kept, first: false }
		}
		const id = randomId(win.crypto)
		win.localStorage.setItem(key + ':client', id)
		return { id, first: true }
	}

	/**
	 * Start a session for the client: a new session id, the sequence from
	 * zero, and a `session_start` saying whether the visitor is new.
	 *
	 * @param {string} key The storage key prefix.
	 * @param {{id: string, first: boolean}} client The client id.
	 * @return {void}
	 */
	function startSession(key, client) {
		const sessionId = randomId(win.crypto)
		win.sessionStorage.setItem(key + ':session', sessionId)
		win.sessionStorage.setItem(key + ':sequence', '0')
		state.sequence = 0
		state.clientId = client.id
		state.sessionId = sessionId
		record('session_start', { visitorType: client.first ? 'new' : 'returning' })
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
