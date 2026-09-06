// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The session recorder (portal-traffic-experiments). A separate script
// from the traffic client, loaded by it only for a portal whose operator
// switched session recording on, that this app serves, and after
// consent where consent is required. A portal that records nothing never
// downloads this file.
//
// MASKING IS THE SERIALISER, NOT A PASS OVER IT. A text node is written as
// its length and nothing else; an input is written as the length of its
// value; an image, a frame and a script are written as their box or not
// at all; an attribute survives only from a short list of layout ones.
// There is no code path that reads a text node's content into the
// stream, so there is nothing to forget to mask. The collector applies
// the same rules again on arrival, because this code runs on somebody
// else's machine.
//
// What IS recorded: the page's structure and classes (so a replay lays
// out like the page), its stylesheets once, the pointer, the clicks, the
// scrolling, the viewport and the navigations, each with a time offset.
// Chunks post to the collector every few seconds; a visit stops at two
// megabytes.
//
// Plain syntax, no transpiler, like the client: see helpers.js.

/**
 * Milliseconds between two recorded pointer positions.
 */
const MOVE_EVERY = 50

/**
 * Milliseconds between two recorded scroll positions.
 */
const SCROLL_EVERY = 100

/**
 * Milliseconds between the last DOM change and the snapshot it earns,
 * and the least time between two snapshots.
 */
const SNAPSHOT_AFTER = 800

/**
 * Milliseconds between two posts, and the queue size that posts sooner.
 */
const FLUSH_EVERY = 5000

/**
 * Bytes queued that post without waiting.
 */
const FLUSH_AT_BYTES = 200 * 1024

/**
 * The most bytes one post carries: the collector's chunk budget is 256
 * KB, and a post is cut before it gets there. One event larger than
 * this goes alone.
 */
const MAX_POST_BYTES = 200 * 1024

/**
 * The most bytes a keepalive request may carry: browsers cap a
 * keepalive body at 64 KB and drop a larger one without a word. A
 * larger post goes as an ordinary request, which the page's unload may
 * cut short; the next visit's chunks are worth more than that one.
 */
const MAX_KEEPALIVE_BYTES = 60 * 1024

/**
 * The most bytes one event (a snapshot) may carry.
 */
const MAX_CHUNK_BYTES = 256 * 1024

/**
 * The longest stylesheet text sent, once per visit.
 */
const MAX_STYLE_BYTES = 128 * 1024

/**
 * The most bytes one visit may send, after which the recorder stops.
 */
const MAX_TOTAL_BYTES = 2 * 1024 * 1024

/**
 * The attributes a serialised element keeps. Layout and identity of the
 * element; nothing a person wrote or is named by.
 */
const ATTRIBUTES = [
	'class',
	'id',
	'style',
	'type',
	'rel',
	'width',
	'height',
	'role',
	'dir',
	'lang',
	'hidden',
	'disabled',
	'colspan',
	'rowspan',
	'size',
	'rows',
	'cols',
	'viewBox',
	'd',
	'fill',
	'stroke',
	'stroke-width',
	'x',
	'y',
	'cx',
	'cy',
	'r',
	'points',
	'transform',
	'xmlns',
]

/**
 * Elements written as a box, without children or source.
 */
const BOXES = [
	'img',
	'video',
	'audio',
	'iframe',
	'canvas',
	'object',
	'embed',
	'picture',
	'source',
]

/**
 * Elements not written at all.
 */
const DROPPED = ['script', 'noscript', 'template']

/**
 * The deepest nesting and the most children written.
 */
const MAX_DEPTH = 64

/**
 * The most children written per element.
 */
const MAX_CHILDREN = 500

/**
 * A short, stable hash of a string (FNV-1a, 32 bits, hex), the same one
 * the client uses for a stack.
 *
 * @param {string} value The string.
 * @return {string} Eight hex characters.
 */
function hashOf(value) {
	let hash = 0x811c9dc5
	for (let i = 0; i < value.length; i++) {
		hash ^= value.charCodeAt(i)
		hash = Math.imul(hash, 0x01000193) >>> 0
	}
	return ('0000000' + hash.toString(16)).slice(-8)
}

/**
 * Start recording in a window whose traffic client asked for it.
 *
 * @param {Window} win The window.
 * @return {void}
 */
export function start(win) {
	const api = win.portaliqTraffic
	const cfg = api && api.recording
	if (!cfg || cfg.started || cfg.consent() !== true) {
		return
	}
	cfg.started = true
	const doc = win.document
	const state = {
		began: Date.now(),
		events: [],
		bytes: 0,
		total: 0,
		stopped: false,
		timer: null,
		lastMove: 0,
		lastScroll: 0,
		snapshotTimer: null,
		lastSnapshotAt: 0,
		lastSnapshot: '',
		observer: null,
		// The post in flight. Posts go ONE AT A TIME: the collector appends
		// a chunk by reading the visit's object and writing it back, and
		// two posts in flight at once would each read the same object and
		// the later write would drop the earlier chunk.
		sending: Promise.resolve(),
		// The stylesheets already sent, by hash. A page's inline styles
		// are the bulk of a snapshot (the built-in site carries its whole
		// stylesheet inline) and they do not change between snapshots,
		// so each is sent ONCE per visit as its own event and referenced
		// by hash from every snapshot after.
		styles: {},
	}

	/**
	 * Milliseconds since the recording began.
	 *
	 * @return {number} The offset.
	 */
	function now() {
		return Date.now() - state.began
	}

	/**
	 * Queue one event and post when the queue is large.
	 *
	 * @param {object} event The event.
	 * @return {void}
	 */
	function push(event) {
		if (state.stopped) {
			return
		}
		const size = JSON.stringify(event).length
		if (size > MAX_CHUNK_BYTES) {
			// A page too large to snapshot within one chunk is a page this
			// recorder cannot replay; better nothing than a torn stream.
			stop()
			return
		}
		state.events.push(event)
		state.bytes += size
		if (state.bytes >= FLUSH_AT_BYTES) {
			flush()
		}
	}

	/**
	 * A text node as its length.
	 *
	 * @param {Node} node The text node.
	 * @return {object} `{ l }`.
	 */
	function textNode(node) {
		return { l: String(node.nodeValue || '').length }
	}

	/**
	 * An element as its tag, allowed attributes and children; boxes and
	 * dropped elements as described above.
	 *
	 * @param {Element} node  The element.
	 * @param {number}  depth How deep the walk is.
	 * @return {object|null} The node, or null to omit it.
	 */
	function element(node, depth) {
		const tag = String(node.tagName || '').toLowerCase()
		if (DROPPED.indexOf(tag) !== -1 || depth > MAX_DEPTH) {
			return null
		}
		const out = { n: tag, a: attributes(node, tag) }
		if (BOXES.indexOf(tag) !== -1) {
			out.c = []
			return out
		}
		if (tag === 'input' || tag === 'textarea') {
			out.v = String(node.value || '').length
			out.c = []
			return out
		}
		if (tag === 'select') {
			out.v = 0
			out.c = []
			return out
		}
		if (tag === 'style') {
			out.h = stylesheet(
				String(node.textContent || '').substring(0, MAX_STYLE_BYTES),
			)
			out.c = []
			return out
		}
		const children = []
		const list = node.childNodes
		for (let i = 0; i < list.length && children.length < MAX_CHILDREN; i++) {
			const child = serialise(list[i], depth + 1)
			if (child) {
				children.push(child)
			}
		}
		out.c = children
		return out
	}

	/**
	 * The hash a stylesheet's text is referenced by, sending the text
	 * itself as its own event the first time it is seen in this visit.
	 *
	 * @param {string} text The stylesheet.
	 * @return {string} The hash.
	 */
	function stylesheet(text) {
		const hash = hashOf(text)
		if (!state.styles[hash]) {
			state.styles[hash] = true
			push({ k: 'y', t: now(), h: hash, s: text })
		}
		return hash
	}

	/**
	 * The allowed attributes of an element. A stylesheet link keeps its
	 * absolute address, the one address that survives.
	 *
	 * @param {Element} node The element.
	 * @param {string}  tag  Its tag.
	 * @return {object} Name to value.
	 */
	function attributes(node, tag) {
		const out = {}
		for (let i = 0; i < ATTRIBUTES.length; i++) {
			const name = ATTRIBUTES[i]
			if (!node.hasAttribute || !node.hasAttribute(name)) {
				continue
			}
			let value = String(node.getAttribute(name) || '').substring(0, 512)
			if (name === 'style') {
				value = value.replace(/url\s*\([^)]*\)/gi, 'none')
			}
			out[name] = value
		}
		if (tag === 'link' && out.rel === 'stylesheet' && node.href) {
			out.href = String(node.href).substring(0, 512)
		}
		return out
	}

	/**
	 * Any node.
	 *
	 * @param {Node}   node  The node.
	 * @param {number} depth How deep the walk is.
	 * @return {object|null} The serialised node, or null.
	 */
	function serialise(node, depth) {
		if (node.nodeType === 3) {
			return textNode(node)
		}
		if (node.nodeType === 1) {
			return element(node, depth)
		}
		return null
	}

	/**
	 * A full snapshot of the document, when it differs from the last.
	 *
	 * @return {void}
	 */
	function snapshot() {
		state.snapshotTimer = null
		if (state.stopped || !doc.documentElement) {
			return
		}
		const tree = serialise(doc.documentElement, 0)
		const encoded = JSON.stringify(tree)
		if (encoded === state.lastSnapshot) {
			return
		}
		state.lastSnapshot = encoded
		state.lastSnapshotAt = Date.now()
		push({ k: 's', t: now(), w: win.innerWidth, h: win.innerHeight, n: tree })
	}

	/**
	 * Ask for a snapshot soon, coalescing a burst of changes.
	 *
	 * @return {void}
	 */
	function scheduleSnapshot() {
		if (state.stopped || state.snapshotTimer !== null) {
			return
		}
		const wait = Math.max(
			SNAPSHOT_AFTER,
			state.lastSnapshotAt + SNAPSHOT_AFTER - Date.now(),
		)
		state.snapshotTimer = win.setTimeout(snapshot, wait)
	}

	/**
	 * The current in-site route, for a navigation event.
	 *
	 * @return {string} The route.
	 */
	function route() {
		if (typeof cfg.route === 'function') {
			return String(cfg.route() || '/')
		}
		return String(win.location.pathname || '/')
	}

	/**
	 * A navigation: the new route and a fresh snapshot once it rendered.
	 *
	 * @return {void}
	 */
	function navigated() {
		push({ k: 'n', t: now(), p: route() })
		scheduleSnapshot()
	}

	/**
	 * Route pushState and replaceState through a navigation, like the
	 * client does for its page views.
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
				win.setTimeout(navigated, 0)
				return result
			}
		})
	}

	/**
	 * Post the queued events as one chunk, as text/plain like the client.
	 *
	 * @return {void}
	 */
	function flush() {
		if (state.timer !== null) {
			win.clearTimeout(state.timer)
			state.timer = null
		}
		if (state.events.length === 0) {
			return
		}
		const events = state.events
		state.total += state.bytes
		state.events = []
		state.bytes = 0
		posts(events).forEach((batch) => {
			const body = {
				consent: cfg.consent() === true,
				recording: cfg.id,
				page: route(),
				elapsed: now(),
				sessionId:
					typeof cfg.sessionId === 'function' ? cfg.sessionId() : '',
				events: batch,
			}
			if (cfg.portal) {
				body.portal = cfg.portal
			}
			const encoded = JSON.stringify(body)
			state.sending = state.sending.then(() =>
				win
					.fetch(cfg.endpoint, {
						method: 'POST',
						body: encoded,
						headers: { 'Content-Type': 'text/plain' },
						mode: 'cors',
						credentials: 'omit',
						keepalive: encoded.length <= MAX_KEEPALIVE_BYTES,
					})
					.catch(() => {}),
			)
		})
		if (state.total >= MAX_TOTAL_BYTES) {
			stop()
			return
		}
		if (!state.stopped) {
			state.timer = win.setTimeout(flush, FLUSH_EVERY)
		}
	}

	/**
	 * Cut queued events into posts under the post budget, in order.
	 *
	 * @param {Array<object>} events The events.
	 * @return {Array<Array<object>>} The posts.
	 */
	function posts(events) {
		const out = []
		let batch = []
		let size = 0
		events.forEach((event) => {
			const length = JSON.stringify(event).length
			if (batch.length > 0 && size + length > MAX_POST_BYTES) {
				out.push(batch)
				batch = []
				size = 0
			}
			batch.push(event)
			size += length
		})
		if (batch.length > 0) {
			out.push(batch)
		}
		return out
	}

	/**
	 * Stop recording: drop the listeners and the queue.
	 *
	 * @return {void}
	 */
	function stop() {
		state.stopped = true
		state.events = []
		state.bytes = 0
		if (state.observer) {
			state.observer.disconnect()
		}
		doc.removeEventListener('mousemove', onMove, true)
		doc.removeEventListener('click', onClick, true)
		win.removeEventListener('scroll', onScroll)
		win.removeEventListener('resize', onResize)
		win.removeEventListener('popstate', navigated)
	}

	/**
	 * The pointer, throttled.
	 *
	 * @param {MouseEvent} event The move.
	 * @return {void}
	 */
	function onMove(event) {
		const at = Date.now()
		if (at - state.lastMove < MOVE_EVERY) {
			return
		}
		state.lastMove = at
		push({
			k: 'm',
			t: now(),
			x: Math.round(event.clientX),
			y: Math.round(event.clientY),
		})
	}

	/**
	 * A click, as a position.
	 *
	 * @param {MouseEvent} event The click.
	 * @return {void}
	 */
	function onClick(event) {
		push({
			k: 'c',
			t: now(),
			x: Math.round(event.clientX),
			y: Math.round(event.clientY),
		})
	}

	/**
	 * The scroll position, throttled.
	 *
	 * @return {void}
	 */
	function onScroll() {
		const at = Date.now()
		if (at - state.lastScroll < SCROLL_EVERY) {
			return
		}
		state.lastScroll = at
		push({
			k: 'r',
			t: now(),
			x: Math.round(win.pageXOffset || 0),
			y: Math.round(win.pageYOffset || 0),
		})
	}

	/**
	 * The viewport changed.
	 *
	 * @return {void}
	 */
	function onResize() {
		push({ k: 'v', t: now(), w: win.innerWidth, h: win.innerHeight })
	}

	cfg.stop = stop
	doc.addEventListener('mousemove', onMove, true)
	doc.addEventListener('click', onClick, true)
	win.addEventListener('scroll', onScroll, { passive: true })
	win.addEventListener('resize', onResize)
	win.addEventListener('popstate', navigated)
	win.addEventListener('pagehide', flush)
	doc.addEventListener('visibilitychange', () => {
		if (doc.visibilityState === 'hidden') {
			flush()
		}
	})
	patchHistory()
	if (typeof win.MutationObserver === 'function' && doc.documentElement) {
		state.observer = new win.MutationObserver(scheduleSnapshot)
		state.observer.observe(doc.documentElement, {
			childList: true,
			subtree: true,
			attributes: true,
			characterData: true,
		})
	}
	push({ k: 'v', t: 0, w: win.innerWidth, h: win.innerHeight })
	snapshot()
	state.timer = win.setTimeout(flush, FLUSH_EVERY)
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
	start(window)
}
