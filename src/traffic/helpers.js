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
]

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
