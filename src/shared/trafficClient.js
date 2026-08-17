/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The portal traffic client — the ONE implementation, for every renderer.
 *
 * A portal is rendered two ways: by this app's own Vue site renderer, and as
 * static HTML built by `docusaurus-plugin-portaliq` and hosted elsewhere. Both
 * report to the same collector, so both must decide the same way what a
 * visitor's browser may store and send. Two implementations of that decision
 * would drift, and the half that drifted would be the half that quietly starts
 * measuring something the portal never enabled — so there is one, here, and
 * both renderers load it.
 *
 * EVERYTHING IT TOUCHES IS INJECTED — storage, navigator, fetch, the clock.
 * Not for testability alone: the guarantees this file makes are about what it
 * does NOT do, and a guarantee about absence can only be tested by watching a
 * double that would have recorded the thing happening.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

/** Where the client id lives. Deliberately namespaced and human-readable. */
const CLIENT_KEY = 'portaliq.clientId'

/** Where the session lives: `{id, sequence, lastActivity}`. */
const SESSION_KEY = 'portaliq.session'

/** Where a granted consent is remembered. */
const CONSENT_KEY = 'portaliq.consent'

/** Events are flushed once this many are queued, without waiting. */
const FLUSH_AT = 10

/**
 * Whether the visitor has asked not to be tracked.
 *
 * READ FROM THREE PLACES because browsers have spelled it three ways, and a
 * signal read from the wrong property is a signal that reads as absent — which
 * is indistinguishable from consent.
 *
 * `navigator.globalPrivacyControl` is included: it is the successor signal, it
 * carries actual legal weight in some jurisdictions, and a portal that honours
 * the deprecated header while ignoring the live one has the posture backwards.
 *
 * @param {object} nav The navigator (injected).
 * @param {object} win The window (injected).
 * @return {boolean} Whether to stay silent.
 */
export function doNotTrack(nav = {}, win = {}) {
	const signals = [
		nav.doNotTrack,
		win.doNotTrack,
		nav.msDoNotTrack,
	]

	if (nav.globalPrivacyControl === true) {
		return true
	}

	return signals.some((s) => s === '1' || s === 'yes' || s === true)
}

/**
 * A random opaque identifier.
 *
 * Not derived from anything about the visitor — not the address, not the user
 * agent, not the screen. A fingerprint would survive clearing storage, which is
 * exactly the property a client id must NOT have.
 *
 * @param {object} crypto The Web Crypto implementation (injected).
 * @return {string} The identifier.
 */
export function newId(crypto) {
	if (crypto && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}

	// A portal served over plain HTTP has no `crypto.randomUUID`. Collision
	// between two visitors merely merges two anonymous journeys; it identifies
	// nobody, which is why a weaker source is acceptable here and would not be
	// for anything else.
	return 'c-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)
}

/**
 * A storage that remembers nothing.
 *
 * USED WHENEVER THE CLIENT MAY NOT PERSIST — measurement off, Do Not Track,
 * consent required but not given. It is a real object with the same shape, so
 * the rest of the client has no "are we allowed to store" branch to forget:
 * the permission is expressed once, by which storage it is handed.
 *
 * @return {object} A Storage-shaped object backed by nothing.
 */
export function ephemeralStorage() {
	const map = new Map()
	return {
		getItem: (k) => (map.has(k) ? map.get(k) : null),
		setItem: (k, v) => map.set(k, String(v)),
		removeItem: (k) => map.delete(k),
	}
}

/**
 * Read a JSON value from storage, tolerating anything.
 *
 * Storage is shared with every other script on the origin and survives across
 * deploys, so its contents are untrusted input in the ordinary sense.
 *
 * @param {object} storage The storage.
 * @param {string} key     The key.
 * @return {object|null} The parsed value, or null.
 */
function readJson(storage, key) {
	try {
		const raw = storage.getItem(key)
		if (!raw) {
			return null
		}

		const parsed = JSON.parse(raw)
		return typeof parsed === 'object' && parsed !== null ? parsed : null
	} catch {
		return null
	}
}

/**
 * Write a JSON value to storage, tolerating a refusal.
 *
 * A browser in private mode throws on `setItem`. Measurement must degrade to
 * "this visit is one session" rather than take the page down with it.
 *
 * @param {object} storage The storage.
 * @param {string} key     The key.
 * @param {*}      value   The value.
 * @return {void}
 */
function writeJson(storage, key, value) {
	try {
		storage.setItem(key, JSON.stringify(value))
	} catch {
		// Deliberately ignored — see above.
	}
}

/**
 * Build a traffic client for one portal.
 *
 * @param {object}   options              The environment and configuration.
 * @param {object}   options.config       The `traffic` block from `/api/content/site`.
 * @param {string}   options.endpoint     The collector URL.
 * @param {string}   options.portal       The portal slug, for a cross-origin client.
 * @param {object}   options.storage      Persistent storage (localStorage).
 * @param {object}   options.navigator    The navigator.
 * @param {object}   options.window       The window.
 * @param {Function} options.now          Returns the current epoch ms.
 * @param {Function} options.fetchImpl    Used when there is no `sendBeacon`.
 * @param {Function} options.setTimeoutImpl Schedules the idle flush; omitted means never.
 * @param {number}   options.flushDelayMs How long to wait for a companion event.
 * @return {object} The client.
 */
export function createTrafficClient({
	config = {},
	endpoint = '',
	portal = '',
	storage = ephemeralStorage(),
	navigator: nav = {},
	window: win = {},
	now = () => 0,
	fetchImpl = null,
	setTimeoutImpl = null,
	flushDelayMs = 2000,
} = {}) {
	// A caller that could not obtain real storage passes null rather than
	// omitting it, and a default parameter does not cover null. Normalising
	// here means a privacy-mode browser degrades to "this page only" instead of
	// throwing on the first `getItem`.
	storage = storage || ephemeralStorage()

	const permitted = Array.isArray(config.events) ? config.events : []
	const consentConfig = config.consent || {}
	const consentRequired = consentConfig.required !== false
	const preConsent = Array.isArray(consentConfig.preConsentEvents)
		? consentConfig.preConsentEvents
		: []
	const timeoutMs = Math.max(1, Number(config.sessionTimeoutMinutes) || 30) * 60 * 1000

	// THE THREE WAYS THIS CLIENT STAYS SILENT, decided once, up front.
	//
	// `enabled` is the portal's decision, an empty event list means the portal
	// enabled measurement and named nothing (which must not widen to
	// everything), and Do Not Track is the visitor's decision, which overrides
	// the portal's. A silent client is not a disabled object: it answers every
	// call and does nothing, so a caller never needs to check.
	const silent = config.enabled !== true
		|| permitted.length === 0
		|| doNotTrack(nav, win)

	let queue = []
	let consented = false

	/**
	 * Whether the visitor has granted consent, from storage.
	 *
	 * Read through the EPHEMERAL storage when consent is required and not yet
	 * given, so a "have they consented" check cannot itself be the thing that
	 * writes to the browser.
	 *
	 * @return {boolean} Whether consent is on record.
	 */
	function storedConsent() {
		if (silent === true) {
			return false
		}

		return readJson(storage, CONSENT_KEY)?.granted === true
	}

	consented = consentRequired === false || storedConsent()

	// Held for the lifetime of the page so a pre-consent visit is ONE session
	// rather than a new one per event.
	const session = { ephemeral: ephemeralStorage() }

	/**
	 * The storage this client is currently ALLOWED to use.
	 *
	 * The whole permission question reduces to this one function. Before
	 * consent, it is a Map that outlives nothing — so a pre-consent event still
	 * carries a client id and a sequence, and still tells the truth about the
	 * order of things, while leaving no trace in the browser.
	 *
	 * @return {object} Persistent or ephemeral storage.
	 */
	function permittedStorage() {
		return consented === true ? storage : session.ephemeral
	}

	/**
	 * The client id, created on first use.
	 *
	 * @return {string} The id.
	 */
	function clientId() {
		const store = permittedStorage()
		let id = store.getItem(CLIENT_KEY)
		if (!id) {
			id = newId(win.crypto || nav.crypto)
			try {
				store.setItem(CLIENT_KEY, id)
			} catch {
				// Private mode; the id lives for this page only.
			}
		}

		return id
	}

	/**
	 * The current session, starting or renewing it as the clock requires.
	 *
	 * THE TIMEOUT IS MEASURED FROM THE LAST EVENT, not from the session's
	 * start. A visitor reading one long page for an hour is one session; a
	 * visitor who leaves and returns after the window is two. Getting this
	 * backwards inflates the session count on exactly the portals whose pages
	 * are worth reading.
	 *
	 * @return {object} `{id, sequence}` with the sequence already advanced.
	 */
	function nextSequence() {
		const store = permittedStorage()
		const at = now()
		const current = readJson(store, SESSION_KEY)

		const lapsed = current === null
			|| typeof current.lastActivity !== 'number'
			|| (at - current.lastActivity) > timeoutMs

		const next = lapsed === true
			? { id: newId(win.crypto || nav.crypto), sequence: 0, lastActivity: at }
			: { id: current.id, sequence: Number(current.sequence || 0) + 1, lastActivity: at }

		writeJson(store, SESSION_KEY, next)
		return { id: next.id, sequence: next.sequence, isNew: lapsed }
	}

	/**
	 * Whether one event may be sent right now.
	 *
	 * @param {string} name The event name.
	 * @return {boolean} Whether to send it.
	 */
	function allowed(name) {
		if (silent === true || permitted.includes(name) === false) {
			return false
		}

		return consented === true || preConsent.includes(name)
	}

	/**
	 * Post the queue, preferring a beacon.
	 *
	 * `sendBeacon` is used because the events worth having most — the last page
	 * of a visit, the exit — are queued while the page is going away, and a
	 * `fetch` at that moment is cancelled. A beacon that the browser refuses
	 * (over quota) falls back to a keepalive fetch rather than being dropped.
	 *
	 * @return {void}
	 */
	function flush() {
		if (queue.length === 0) {
			return
		}

		const body = JSON.stringify({ events: queue, portal })
		queue = []

		// `sendBeacon` IS ONLY USABLE SAME-ORIGIN, and this is measured, not
		// cautious. A beacon is always sent with credentials mode `include`,
		// so the browser refuses a response whose `Access-Control-Allow-Origin`
		// is `*` — the exact error a statically built portal produced against
		// the live collector:
		//
		//   "The value of the 'Access-Control-Allow-Origin' header in the
		//    response must not be the wildcard '*' when the request's
		//    credentials mode is 'include'."
		//
		// The alternative was to echo the caller's origin and allow
		// credentials, which would let any site send this instance's cookies
		// to an endpoint that deliberately has none. Keeping the server strict
		// and switching the transport keeps "no credentials, ever" true.
		const beacon = nav.sendBeacon
		if (typeof beacon === 'function' && sameOrigin() === true) {
			// A Blob carries the content type; `sendBeacon` with a bare string
			// sends `text/plain`, which the collector does not parse as JSON.
			const Ctor = win.Blob
			const payload = typeof Ctor === 'function'
				? new Ctor([body], { type: 'application/json' })
				: body

			if (beacon.call(nav, endpoint, payload) === true) {
				return
			}
		}

		if (typeof fetchImpl === 'function') {
			fetchImpl(endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body,
				// `keepalive` is what lets this survive the page going away —
				// it is the reason a fetch is an acceptable substitute for a
				// beacon at all.
				keepalive: true,
				// EXPLICIT, NOT INHERITED. The default for a cross-origin
				// fetch is already `same-origin`, but saying it here is what
				// keeps the wildcard CORS response valid, and it states the
				// property the endpoint's whole posture rests on: this request
				// carries no identity.
				credentials: 'omit',
			}).catch(() => {
				// A collector that is down must never surface in a visitor's
				// console on a government portal.
			})
		}
	}

	/**
	 * Record one event.
	 *
	 * @param {string} name   The event name, from the shipped vocabulary.
	 * @param {object} params Bounded extra parameters.
	 * @return {boolean} Whether it was queued.
	 */
	function track(name, params = {}) {
		if (allowed(name) === false) {
			return false
		}

		const doc = win.document || {}
		const loc = win.location || {}
		const seq = nextSequence()

		queue.push({
			name,
			clientId: clientId(),
			sessionId: seq.id,
			sequence: seq.sequence,
			timestamp: new Date(now()).toISOString(),
			pageLocation: String(loc.href || ''),
			pageReferrer: String(doc.referrer || ''),
			pageTitle: String(doc.title || ''),
			params: params || {},
		})

		if (queue.length >= FLUSH_AT) {
			flush()
			return true
		}

		// A SINGLE-PAGE VISIT IS THE COMMON VISIT, and waiting for `pagehide`
		// to deliver it loses every visitor whose browser is killed, whose tab
		// is discarded under memory pressure, or who is on an iOS Safari that
		// simply does not fire it. The short delay still batches the burst of
		// events a page produces on arrival; it only stops the queue from
		// depending on an unload that may never come.
		scheduleFlush()

		return true
	}

	/**
	 * Whether the collector is on the page's own origin.
	 *
	 * A relative endpoint always is. An absolute one is compared properly
	 * rather than by prefix: `https://portal.example.evil.test` starts with
	 * the same characters as `https://portal.example` and is a different site.
	 *
	 * @return {boolean} Whether the collector shares this page's origin.
	 */
	function sameOrigin() {
		const here = (win.location && win.location.origin) || ''
		if (endpoint.startsWith('http') === false) {
			return true
		}

		try {
			return new URL(endpoint, here || undefined).origin === here
		} catch {
			return false
		}
	}

	/** The pending idle-flush handle, so only one is ever outstanding. */
	let flushTimer = null

	/**
	 * Flush shortly, unless a flush is already pending.
	 *
	 * @return {void}
	 */
	function scheduleFlush() {
		if (typeof setTimeoutImpl !== 'function' || flushTimer !== null) {
			return
		}

		flushTimer = setTimeoutImpl(() => {
			flushTimer = null
			flush()
		}, flushDelayMs)
	}

	return {
		/** Whether this client will ever send anything. */
		get silent() {
			return silent
		},

		/** Whether it may currently store and send unrestricted. */
		get consented() {
			return consented
		},

		track,
		flush,

		/**
		 * Record the visitor's consent decision.
		 *
		 * GRANTING FLUSHES NOTHING RETROACTIVELY. Events queued before consent
		 * were only ever the pre-consent ones, and events that were refused
		 * were never kept — there is no buffer of withheld events waiting for
		 * permission, which is the shape that turns a consent banner into a
		 * delay rather than a choice.
		 *
		 * @param {boolean} granted The decision.
		 * @return {void}
		 */
		consent(granted) {
			if (silent === true) {
				return
			}

			consented = granted === true
			if (consented === true) {
				writeJson(storage, CONSENT_KEY, { granted: true })
				return
			}

			// A withdrawal clears what was kept. Leaving the id behind would
			// make "no" mean "stop sending" while the browser still carries a
			// stable identifier this portal put there.
			try {
				storage.removeItem(CONSENT_KEY)
				storage.removeItem(CLIENT_KEY)
				storage.removeItem(SESSION_KEY)
			} catch {
				// Nothing to undo if storage is unavailable.
			}
		},

		/**
		 * Track a page view, the event every portal that measures anything has.
		 *
		 * @param {object} params Extra parameters.
		 * @return {boolean} Whether it was queued.
		 */
		pageView(params = {}) {
			return track('page_view', params)
		},
	}
}
