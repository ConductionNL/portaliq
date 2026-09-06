// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The pure half of the traffic client (portal-traffic-analytics): the
// decisions that need no browser, kept apart from client.js so a plain
// node test can exercise them. client.js is the only importer; the built
// bundle inlines both into one IIFE.
//
// PLAIN SYNTAX ON PURPOSE. The traffic bundle is served to whatever
// browser a visitor of a public government portal brings, and it is not
// transpiled: no spread, no optional chaining, no async/await. The one
// newer construct is the bare `catch {}` (2018, older than sendBeacon
// support in every browser that has it).

/**
 * The event names the collector knows. Anything else is dropped here
 * rather than posted and refused.
 */
export const KNOWN_EVENTS = [
	'page_view',
	'session_start',
	'scroll',
	'outbound_click',
	'file_download',
	'search',
	'form_submit',
	'form_start',
	'form_field',
	'form_abandon',
	'page_not_found',
	'js_error',
	'heat_click',
	'heat_scroll',
]

/**
 * The attribute an external page puts on a form it wants observed, and
 * the one the renderer (or an external page) puts on its not-found state.
 */
export const FORM_ATTRIBUTE = 'data-portaliq-form'
export const STATUS_ATTRIBUTE = 'data-portaliq-status'
export const PATH_ATTRIBUTE = 'data-portaliq-path'

/**
 * The prefix a custom dimension travels under (CONTRACT, phase 3).
 */
export const DIMENSION_PREFIX = 'cd_'

/**
 * The most events one batch may carry (CONTRACT section 1).
 */
export const MAX_BATCH = 50

/**
 * File extensions a click counts as a download.
 */
const DOWNLOAD_EXTENSIONS = [
	'pdf',
	'doc',
	'docx',
	'xls',
	'xlsx',
	'ppt',
	'pptx',
	'odt',
	'ods',
	'odp',
	'csv',
	'zip',
	'rar',
	'7z',
	'gz',
	'txt',
	'rtf',
	'xml',
	'json',
]

/**
 * Query parameters that carry a site search term.
 */
const SEARCH_PARAMS = ['q', 'zoek', 'search', 'query', 'zoekterm']

/**
 * Where the client posts and which portal it names, from the script tag
 * that loaded it.
 *
 * The Docusaurus plugin stamps `data-origin`, `data-portal` and
 * `data-appPath` on the tag and those win. The built-in renderer emits the
 * tag through Nextcloud's own CSP-nonce helper, which takes no extra
 * attributes, so there the values are derived from the tag's `src`
 * (`{origin}{appPath}/api/traffic-client.js`) and the portal from the
 * site's own config block. A tag that yields no origin at all disables
 * the client rather than posting to a guess.
 *
 * @param {object|null} tag        The `<script>` element: `{ src, dataset }`, or null.
 * @param {object|null} siteConfig The renderer's `#portaliq-site-config` JSON, when present.
 * @return {{origin: string, appPath: string, portal: string}|null} The configuration, or null.
 */
export function readConfig(tag, siteConfig) {
	const dataset = (tag && tag.dataset) || {}
	let origin = String(dataset.origin || '').replace(/\/+$/, '')
	let appPath = String(dataset.appPath || dataset.apppath || '').replace(
		/\/+$/,
		'',
	)
	let portal = String(dataset.portal || '')

	if (origin === '' && tag && tag.src) {
		const match =
			/^(https?:\/\/[^/]+)(.*?)\/api\/traffic-client\.js(?:[?#].*)?$/i.exec(
				String(tag.src),
			)
		if (match) {
			origin = match[1]
			appPath = match[2].replace(/\/+$/, '')
		}
	}

	if (portal === '' && siteConfig && typeof siteConfig.portal === 'string') {
		portal = siteConfig.portal
	}

	if (origin === '') {
		return null
	}

	return { origin, appPath, portal }
}

/**
 * Whether the visitor asked not to be tracked, through either signal the
 * platform exposes. Both are honoured before anything else runs.
 *
 * @param {object} nav The navigator.
 * @return {boolean} True when the visitor opted out.
 */
export function optedOut(nav) {
	if (!nav) {
		return false
	}
	return (
		nav.doNotTrack === '1'
		|| nav.doNotTrack === 'yes'
		|| nav.globalPrivacyControl === true
	)
}

/**
 * Whether an event may be sent under the portal's configuration and the
 * visitor's consent state. Mirrors the collector's own rule so the client
 * never posts what would be refused.
 *
 * @param {object}  traffic  The resolved `traffic` block from the site record.
 * @param {string}  name     The event name.
 * @param {boolean} consent  Whether the visitor consented.
 * @return {boolean} True when the event is allowed.
 */
export function allowedEvent(traffic, name, consent) {
	if (!traffic || traffic.enabled !== true) {
		return false
	}
	const events = Array.isArray(traffic.events) ? traffic.events : []
	if (events.indexOf(name) === -1 || KNOWN_EVENTS.indexOf(name) === -1) {
		return false
	}
	if (traffic.consentRequired === true && consent !== true) {
		const pre = Array.isArray(traffic.preConsentEvents)
			? traffic.preConsentEvents
			: []
		return pre.indexOf(name) !== -1
	}
	return true
}

/**
 * Whether the client may write to browser storage: only when the portal
 * switched persistence on AND the visitor consented (a portal that does
 * not require consent counts as consented).
 *
 * @param {object}  traffic The resolved `traffic` block.
 * @param {boolean} consent The visitor's consent state.
 * @return {boolean} True when storage may be used.
 */
export function mayPersist(traffic, consent) {
	if (!traffic || traffic.persistClientId !== true) {
		return false
	}
	return traffic.consentRequired !== true || consent === true
}

/**
 * Classify a clicked link: outbound when it leaves the page's host, a
 * download when its path ends in a document extension, else nothing.
 *
 * @param {string} href     The link's absolute href.
 * @param {string} pageHost The current page's host.
 * @return {{name: string, linkUrl: string, fileName?: string}|null} The event, or null.
 */
export function classifyLink(href, pageHost) {
	const match = /^(https?:)\/\/([^/?#]+)([^?#]*)/i.exec(String(href || ''))
	if (!match) {
		return null
	}
	const host = match[2].toLowerCase()
	const path = match[3] || '/'
	const file = path.substring(path.lastIndexOf('/') + 1)
	const dot = file.lastIndexOf('.')
	const extension = dot === -1 ? '' : file.substring(dot + 1).toLowerCase()

	if (extension !== '' && DOWNLOAD_EXTENSIONS.indexOf(extension) !== -1) {
		return { name: 'file_download', linkUrl: href, fileName: decodeSafe(file) }
	}
	if (host !== String(pageHost || '').toLowerCase()) {
		return { name: 'outbound_click', linkUrl: href }
	}
	return null
}

/**
 * The site search term a location carries, or ''.
 *
 * @param {string} location The page URL.
 * @return {string} The term, trimmed.
 */
export function searchTermFrom(location) {
	const query = /\?([^#]*)/.exec(String(location || ''))
	if (!query) {
		return ''
	}
	const pairs = query[1].split('&')
	for (let i = 0; i < pairs.length; i++) {
		const eq = pairs[i].indexOf('=')
		const key = decodeSafe(
			eq === -1 ? pairs[i] : pairs[i].substring(0, eq),
		).toLowerCase()
		if (SEARCH_PARAMS.indexOf(key) !== -1) {
			const value =
				eq === -1
					? ''
					: decodeSafe(
							pairs[i].substring(eq + 1).replace(/\+/g, ' '),
						).trim()
			if (value !== '') {
				return value.substring(0, 256)
			}
		}
	}
	return ''
}

/**
 * How far down the page the visitor has scrolled, as a percentage.
 *
 * @param {number} scrollTop  Pixels scrolled.
 * @param {number} viewport   The viewport height.
 * @param {number} pageHeight The document height.
 * @return {number} 0 to 100.
 */
export function scrollPercent(scrollTop, viewport, pageHeight) {
	const scrollable = pageHeight - viewport
	if (scrollable <= 0) {
		return 100
	}
	return Math.max(0, Math.min(100, Math.round((scrollTop / scrollable) * 100)))
}

/**
 * Cut a queue into batches the collector accepts.
 *
 * @param {Array<object>} events The queued events.
 * @return {Array<Array<object>>} Batches of at most MAX_BATCH.
 */
export function chunk(events) {
	const out = []
	for (let i = 0; i < events.length; i += MAX_BATCH) {
		out.push(events.slice(i, i + MAX_BATCH))
	}
	return out
}

/**
 * The envelope for one batch, as the JSON string sendBeacon posts.
 *
 * @param {string}        portal  The portal slug, or ''.
 * @param {boolean}       consent The visitor's consent state.
 * @param {Array<object>} events  The events.
 * @return {string} The body.
 */
export function envelope(portal, consent, events) {
	const body = { consent: consent === true, events }
	if (portal) {
		body.portal = portal
	}
	return JSON.stringify(body)
}

/**
 * A random identifier for a client or a session, when the portal persists
 * one. Crypto when the platform has it, Math.random when it does not.
 *
 * @param {object} cryptoApi `window.crypto`, or undefined.
 * @return {string} 32 hex characters.
 */
export function randomId(cryptoApi) {
	if (cryptoApi && typeof cryptoApi.getRandomValues === 'function') {
		const bytes = new Uint8Array(16)
		cryptoApi.getRandomValues(bytes)
		let hex = ''
		for (let i = 0; i < bytes.length; i++) {
			hex += (bytes[i] < 16 ? '0' : '') + bytes[i].toString(16)
		}
		return hex
	}
	let fallback = ''
	while (fallback.length < 32) {
		fallback += Math.floor(Math.random() * 16).toString(16)
	}
	return fallback
}

/**
 * The id a form is reported under: the `data-portaliq-form` attribute,
 * else the form's id, name or action. Never a value from it.
 *
 * @param {object|null} form The form element: `{ getAttribute, id }`.
 * @return {string} The id, bounded, or ''.
 */
export function formIdOf(form) {
	if (!form || typeof form.getAttribute !== 'function') {
		return ''
	}
	const id =
		form.getAttribute(FORM_ATTRIBUTE)
		|| form.id
		|| form.getAttribute('name')
		|| form.getAttribute('action')
		|| ''
	return String(id).substring(0, 256)
}

/**
 * The id a field is reported under: its id, else its name. Never its
 * value, which this function does not read.
 *
 * @param {object|null} field The field element: `{ id, name, tagName }`.
 * @return {string} The id, bounded, or ''.
 */
export function fieldIdOf(field) {
	if (!field || !field.tagName) {
		return ''
	}
	const tag = String(field.tagName).toUpperCase()
	if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'TEXTAREA') {
		return ''
	}
	if (
		tag === 'INPUT'
		&& (String(field.type).toLowerCase() === 'hidden'
			|| String(field.type).toLowerCase() === 'submit'
			|| String(field.type).toLowerCase() === 'button')
	) {
		return ''
	}
	return String(field.id || field.name || '').substring(0, 256)
}

/**
 * The custom dimension params to attach: `cd_<id>` for every declared id
 * that has a value. An id the portal did not declare is not sent, which
 * mirrors the collector's rule so nothing is posted to be stripped.
 *
 * @param {object} traffic The resolved `traffic` block.
 * @param {object} values  Id => value, as the page set them.
 * @return {object} The params.
 */
export function dimensionParams(traffic, values) {
	const declared = (traffic && traffic.customDimensions) || []
	const out = {}
	if (!Array.isArray(declared) || !values) {
		return out
	}
	for (let i = 0; i < declared.length; i++) {
		const id = declared[i] && declared[i].id
		if (!id) {
			continue
		}
		const value = values[id]
		if (value === '' || value === null || value === undefined) {
			continue
		}
		out[DIMENSION_PREFIX + id] = String(value).substring(0, 256)
	}
	return out
}

/**
 * decodeURIComponent that answers the input on a malformed sequence.
 *
 * @param {string} value The encoded value.
 * @return {string} The decoded value.
 */
function decodeSafe(value) {
	try {
		return decodeURIComponent(value)
	} catch {
		return value
	}
}

/**
 * The parameters of a `js_error` event (portal-traffic-reporting): the
 * message, the script's host and path WITHOUT its query string, the line,
 * the column, and a short hash of the stack. Never the stack itself,
 * which can carry a URL with a token in it, and never a query string,
 * which can carry anything.
 *
 * @param {object} error   The ErrorEvent-shaped object: message, filename, lineno, colno, error.
 * @return {object|null} The params, or null when there is nothing to report.
 */
export function errorParams(error) {
	if (!error) {
		return null
	}
	const message = String(error.message || '').substring(0, 256)
	const stack = error.error && error.error.stack ? String(error.error.stack) : ''
	if (message === '' && stack === '') {
		return null
	}
	const params = {
		message: message || 'Script error',
		source: sourceOf(String(error.filename || '')),
		line: Number(error.lineno) || 0,
		column: Number(error.colno) || 0,
	}
	if (stack !== '') {
		params.stackHash = hashOf(stack)
	}
	return params
}

/**
 * A script URL as host and path, query and fragment dropped.
 *
 * @param {string} url The script URL.
 * @return {string} `host/path`, or '' when there is none.
 */
function sourceOf(url) {
	if (url === '') {
		return ''
	}
	const cut = url.search(/[?#]/)
	const clean = cut === -1 ? url : url.substring(0, cut)
	return clean.replace(/^[a-z]+:\/\//i, '').substring(0, 256)
}

/**
 * A short, stable hash of a string (FNV-1a, 32 bits, hex).
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
 * The viewport width buckets a heatmap sample is filed under, in pixels
 * (portal-traffic-experiments). A bucket, not the width: the width is
 * part of a fingerprint, the bucket is not.
 */
const WIDTH_BUCKETS = [480, 768, 1024, 1280, 1600]

/**
 * The in-site route a location represents: the `route` query parameter
 * the built-in renderer uses on the platform host, else the path. The
 * trailing slash is dropped so `/contact/` and `/contact` are one page.
 *
 * @param {string} location The page URL.
 * @return {string} The route, always with a leading slash.
 */
export function siteRoute(location) {
	const url = String(location || '')
	const query = /\?([^#]*)/.exec(url)
	let route = ''
	if (query) {
		const pairs = query[1].split('&')
		for (let i = 0; i < pairs.length; i++) {
			if (pairs[i].indexOf('route=') === 0) {
				route = decodeSafe(pairs[i].substring(6))
				break
			}
		}
	}
	if (route === '') {
		const path = /^[a-z]+:\/\/[^/?#]+([^?#]*)/i.exec(url)
		route = path ? path[1] : url.replace(/[?#].*$/, '')
	}
	if (route === '' || route.charAt(0) !== '/') {
		route = '/' + route
	}
	if (route.length > 1) {
		route = route.replace(/\/+$/, '')
	}
	return route === '' ? '/' : route
}

/**
 * The running experiment on a route, or null (portal-traffic-experiments).
 * The first declared one wins when two claim the same page.
 *
 * @param {object} traffic The resolved `traffic` block.
 * @param {string} route   The current in-site route.
 * @return {object|null} The experiment.
 */
export function experimentFor(traffic, route) {
	const experiments = (traffic && traffic.experiments) || []
	if (!Array.isArray(experiments)) {
		return null
	}
	for (let i = 0; i < experiments.length; i++) {
		const experiment = experiments[i]
		if (
			experiment
			&& experiment.status === 'running'
			&& siteRoute(String(experiment.page || '')) === route
			&& Array.isArray(experiment.variants)
			&& experiment.variants.length > 1
		) {
			return experiment
		}
	}
	return null
}

/**
 * Pick a variant by weight, deterministically for one seed: the same
 * seed always lands on the same variant, which is what makes the pick
 * sticky. The seed is the experiment id plus the visitor's client id when
 * the portal persists one, else plus a random per page load.
 *
 * @param {Array<object>} variants The experiment's variants.
 * @param {string}        seed     The seed.
 * @return {object|null} The picked variant.
 */
export function pickVariant(variants, seed) {
	if (!Array.isArray(variants) || variants.length === 0) {
		return null
	}
	let total = 0
	for (let i = 0; i < variants.length; i++) {
		total += weightOf(variants[i])
	}
	const point = (parseInt(hashOf(String(seed)), 16) / 0x100000000) * total
	let at = 0
	for (let i = 0; i < variants.length; i++) {
		at += weightOf(variants[i])
		if (point < at) {
			return variants[i]
		}
	}
	return variants[variants.length - 1]
}

/**
 * A variant's weight: a positive number, else 1.
 *
 * @param {object} variant The variant.
 * @return {number} The weight.
 */
function weightOf(variant) {
	const weight = Number(variant && variant.weight)
	return weight > 0 && Number.isFinite(weight) ? weight : 1
}

/**
 * Whether the session recorder may run at all: the switch on, a site
 * this app serves (an external site's DOM is not ours to record), and
 * consent where the portal requires it (portal-traffic-experiments).
 *
 * @param {object}  traffic The resolved `traffic` block.
 * @param {boolean} consent The visitor's consent state.
 * @return {boolean} True when recording may start.
 */
export function mayRecord(traffic, consent) {
	if (!traffic || traffic.enabled !== true || !traffic.sensitive) {
		return false
	}
	if (traffic.sensitive.sessionRecording !== true || traffic.kind === 'external') {
		return false
	}
	return traffic.consentRequired !== true || consent === true
}

/**
 * The params of a `heat_click`: where on the document the click was, as
 * fractions, the viewport bucket, the element's tag and a short selector
 * with nothing in it that could name a person.
 *
 * @param {object} click The click: `{ pageX, pageY, target }`.
 * @param {object} size  The document: `{ width, height, viewport }`.
 * @return {object|null} The params, or null off the page.
 */
export function heatClickParams(click, size) {
	if (!click || !size || !(size.width > 0) || !(size.height > 0)) {
		return null
	}
	const x = Number(click.pageX) / size.width
	const y = Number(click.pageY) / size.height
	if (!(x >= 0 && x <= 1 && y >= 0 && y <= 1)) {
		return null
	}
	const target = click.target
	return {
		x: Math.round(x * 10000) / 10000,
		y: Math.round(y * 10000) / 10000,
		vw: widthBucket(size.viewport),
		tag: String((target && target.tagName) || '')
			.toLowerCase()
			.substring(0, 32),
		selector: safeSelector(target),
	}
}

/**
 * The bucket a viewport width falls in.
 *
 * @param {number} width The viewport width.
 * @return {number} The bucket's upper bound, or 0 for wider than the last.
 */
export function widthBucket(width) {
	for (let i = 0; i < WIDTH_BUCKETS.length; i++) {
		if (Number(width) <= WIDTH_BUCKETS[i]) {
			return WIDTH_BUCKETS[i]
		}
	}
	return 0
}

/**
 * A short selector for an element: up to three ancestors of tag and
 * class names. No ids (an id is where a record number ends up), no
 * attributes, and no class that carries digits (a generated class can
 * carry one too).
 *
 * @param {object|null} element The element.
 * @return {string} The selector, at most 128 characters.
 */
export function safeSelector(element) {
	const parts = []
	let node = element
	while (node && node.nodeType === 1 && parts.length < 3) {
		let part = String(node.tagName || '').toLowerCase()
		const classes = String(
			node.className && node.className.baseVal !== undefined
				? node.className.baseVal
				: node.className || '',
		)
			.split(/\s+/)
			.filter((name) => name !== '' && !/\d/.test(name))
			.slice(0, 2)
		if (classes.length > 0) {
			part += '.' + classes.join('.')
		}
		parts.unshift(part)
		node = node.parentNode
	}
	return parts.join(' > ').substring(0, 128)
}

/**
 * The deepest scroll position as a fraction of the document.
 *
 * @param {number} scrollTop  Pixels scrolled.
 * @param {number} viewport   The viewport height.
 * @param {number} pageHeight The document height.
 * @return {number} 0 to 1, four decimals.
 */
export function scrollDepth(scrollTop, viewport, pageHeight) {
	if (!(pageHeight > 0)) {
		return 1
	}
	const seen = Math.min(
		pageHeight,
		Math.max(0, Number(scrollTop) || 0) + (Number(viewport) || 0),
	)
	return Math.round((seen / pageHeight) * 10000) / 10000
}

/**
 * The URL of a variant page, in the shape the current location uses: the
 * `route` query parameter replaced when the built-in renderer runs on
 * the platform host, else the path replaced. The rest of the query
 * (the portal, a campaign tag) travels along.
 *
 * @param {string} location  The current page URL.
 * @param {string} pageRoute The variant's in-site route.
 * @return {string} The URL to move to.
 */
export function variantUrl(location, pageRoute) {
	const url = String(location || '')
	const cut = url.search(/[?#]/)
	const base = cut === -1 ? url : url.substring(0, cut)
	const query = /\?([^#]*)/.exec(url)
	const pairs = query && query[1] !== '' ? query[1].split('&') : []
	let replaced = false
	for (let i = 0; i < pairs.length; i++) {
		if (pairs[i].indexOf('route=') === 0) {
			pairs[i] = 'route=' + encodeURIComponent(pageRoute)
			replaced = true
		}
	}
	if (replaced) {
		return base + '?' + pairs.join('&')
	}
	const origin = /^([a-z]+:\/\/[^/?#]+)/i.exec(base)
	const path = origin ? origin[1] + pageRoute : pageRoute
	return pairs.length > 0 ? path + '?' + pairs.join('&') : path
}
